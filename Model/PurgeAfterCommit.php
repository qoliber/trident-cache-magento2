<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Model\CallbackPool;
use Psr\Log\LoggerInterface;
use Qoliber\Trident\Delivery\Drainer;
use Qoliber\Trident\Delivery\Instance;
use Qoliber\Trident\Delivery\OutboxEntry;
use Qoliber\Trident\Delivery\Packer;
use Qoliber\Trident\Delivery\PurgeClient;
use Qoliber\Trident\Delivery\Purger;
use Qoliber\TridentCache\Model\Outbox\PurgeOutboxInterface;

/**
 * Sends a purge only once the transaction that caused it has committed.
 *
 * Magento dispatches `clean_cache_by_tags` from AbstractModel::afterSave(),
 * which runs INSIDE the save transaction. A purge sent from there reaches
 * Trident while the new data is still invisible to every other connection.
 * With soft purge the refresh worker re-fetches the page at once, the
 * storefront renders it from the old, committed state, and that old page is
 * stored again as fresh — for the whole TTL, because the only purge has
 * already been spent. The same render also refills the storefront's own
 * block_html cache with the old content, so a later purge re-fetches the old
 * block too. Measured on the e2e stack: a price change held 4 s between
 * afterSave and commit stayed old on the edge AND at the origin until the
 * indexer cron ran; with no cron, nothing else clears it before the TTL.
 *
 * Outside a transaction the purge goes out immediately, as before. Inside
 * one, purges are merged and sent from a commit callback. Magento 2.4 runs
 * those through the `execute_commit_callbacks` plugin on every commit that
 * brings the level back to zero, raw adapter commits included, and clears
 * them on rollback. Tags a rollback leaves pending go out with the next
 * flush — an unneeded purge, which is what the module sent before this class
 * existed — or not at all if no transaction follows; the data did not change.
 *
 * X02 — durable delivery. Every purge is first RECORDED in the outbox
 * ({@see PurgeOutboxInterface}) through the same connection, so inside a
 * transaction the record commits or rolls back with the data. Delivery is
 * qoliber/trident-php's {@see Drainer}: requests of at most 1000 tags, each
 * row removed only when Trident acknowledges it (the library's Acknowledgement: a
 * 200 with the engine's purge schema, or Reflect mode's 202 `recorded`), a
 * backoff per row, and an instance left for the next drain after three
 * consecutive failures. A 401, 429, 5xx, timeout or dead process leaves the
 * row, and the next drain — the next commit, or the cron job — sends it
 * again. Purges are idempotent, so a duplicate after a crash between "sent"
 * and "removed" costs one extra purge, never a lost one.
 *
 * Why not the library's {@see Purger}: it remembers what this process
 * recorded to skip duplicates, and a rolled-back transaction takes the rows
 * with it while that memory stays — a retried save would then record
 * nothing. Magento's transactions make the dedupe unnecessary anyway: every
 * row is invisible to other drainers until its save committed.
 *
 * X03 — several instances. A purge is recorded once per instance and each
 * record is acknowledged, backed off and retried on its own: an edge that is
 * down keeps its own purges pending without holding back the others.
 *
 * Supported setup: the single `default` connection, which is all Open Source
 * has. Both the transaction check and the callback key use it, so an entity
 * saved through another connection (Commerce split database, a module's own
 * connection) is checked against the wrong transaction.
 */
class PurgeAfterCommit
{
    /** Entries a drain looks at from a request thread. */
    private const REQUEST_DRAIN_LIMIT = Purger::REQUEST_DRAIN_LIMIT;

    /**
     * Fallback only — used when the outbox cannot be written (the module was
     * upgraded but `setup:upgrade` has not created its table yet). Tags
     * waiting for the commit, as keys for deduplication.
     *
     * @var array<string, true>
     */
    private array $pendingTags = [];

    /**
     * Fallback only: a full purge waiting for the commit.
     *
     * @var bool
     */
    private bool $pendingAll = false;

    /**
     * Config-derived tags, held until the configuration is reloaded.
     *
     * @var array<string, true>
     */
    private array $pendingConfigTags = [];

    /**
     * Whether the end-of-request floor for config tags is armed.
     *
     * @var bool
     */
    private bool $configFloorRegistered = false;

    /**
     * Cache entries Trident reported purging in the last drain.
     *
     * @var int
     */
    private int $lastPurged = 0;

    /**
     * @param ResourceConnection $resourceConnection
     * @param PurgeOutboxInterface $outbox
     * @param Config $config
     * @param TridentClient $tridentClient
     * @param LoggerInterface $logger
     * @param Clock $clock
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PurgeOutboxInterface $outbox,
        private readonly Config $config,
        private readonly TridentClient $tridentClient,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock
    ) {
    }

    /**
     * Record a purge by tags; send it now, or after the commit when a
     * transaction is open.
     *
     * @param array<string> $tags
     * @return void
     */
    public function purgeTags(array $tags): void
    {
        $tags = array_values(array_map('strval', $tags));
        if ($tags === [] || !$this->configured()) {
            return;
        }
        try {
            $now = $this->clock->now();
            foreach ($this->config->getInstances() as $instance) {
                foreach (Packer::chunk($tags) as $chunk) {
                    // Due at once: until the save commits, nobody else sees it.
                    $this->outbox->record($instance->name, $chunk, $now, $now);
                }
            }
        } catch (\Throwable $e) {
            $this->outboxUnavailable($e);
            if (!$this->inTransaction()) {
                $this->sendDirect($tags);
                return;
            }
            foreach ($tags as $tag) {
                $this->pendingTags[$tag] = true;
            }
        }
        $this->afterRecording();
    }

    /**
     * Hold config-derived tags until the configuration has been reloaded.
     *
     * Saving a configuration value does not make the new value visible: the
     * admin save runs `configStorage->save()` and only afterwards
     * `ReinitableConfig::reinit()`. A purge sent at save time therefore hands
     * the refresh a storefront that still answers with the OLD value, and the
     * edge stores it again — measured on 2.4.x with `/robots.txt`, where the
     * edge ended up permanently ONE change behind: save A then B, and the edge
     * serves A.
     *
     * These tags are flushed by the reinit plugin instead — deliberately NOT
     * by the commit callback, which runs before the reload and would spend the
     * purge on the old value again. A shutdown flush is the floor for a CLI
     * path that never reinitialises: late beats never.
     *
     * @param array<string> $tags
     * @return void
     */
    public function purgeConfigTags(array $tags): void
    {
        if ($tags === []) {
            return;
        }
        foreach ($tags as $tag) {
            $this->pendingConfigTags[(string) $tag] = true;
        }
        if (!$this->configFloorRegistered) {
            // Floor, not the intended path: a CLI save that never reinitialises
            // would otherwise never purge at all. End of process is late, and
            // late beats never.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            register_shutdown_function([$this, 'flushConfigTags']);
            $this->configFloorRegistered = true;
        }
    }

    /**
     * The configuration is now reloaded: record and send the held tags.
     *
     * @return void
     */
    public function flushConfigTags(): void
    {
        if ($this->pendingConfigTags === []) {
            return;
        }
        $tags = array_map('strval', array_keys($this->pendingConfigTags));
        $this->pendingConfigTags = [];
        try {
            $this->purgeTags($tags);
        } catch (\Throwable $e) {
            // Also runs as a shutdown function: never turn a lost purge into a
            // fatal error at the end of the request.
            $this->logger->error('Trident config purge failed', ['error' => $e->getMessage(), 'tags' => $tags]);
        }
    }

    /**
     * Record a full purge; send it now, or after the commit.
     *
     * @return void
     */
    public function purgeAll(): void
    {
        // A full clear covers every config tag still held for the reload.
        $this->pendingConfigTags = [];
        if (!$this->configured()) {
            return;
        }
        try {
            $now = $this->clock->now();
            foreach ($this->config->getInstances() as $instance) {
                $this->outbox->recordClear($instance->name, $now);
            }
        } catch (\Throwable $e) {
            $this->outboxUnavailable($e);
            if (!$this->inTransaction()) {
                $this->clearDirect();
                return;
            }
            $this->pendingAll = true;
        }
        $this->afterRecording();
    }

    /**
     * Commit callback: send what the commit made durable.
     *
     * Every deferral attaches its own callback, so this runs more than once
     * per commit; all but the first find nothing to send.
     *
     * @return void
     */
    public function flush(): void
    {
        if ($this->pendingAll) {
            $this->pendingAll = false;
            $this->pendingTags = [];
            $this->clearDirect();
        } elseif ($this->pendingTags !== []) {
            $tags = array_map('strval', array_keys($this->pendingTags));
            $this->pendingTags = [];
            $this->sendDirect($tags);
        }
        $this->drain(self::REQUEST_DRAIN_LIMIT);
    }

    /**
     * Deliver due outbox entries; remove each one Trident acknowledges.
     *
     * A due full clear goes first and, once acknowledged, removes the tag
     * entries this drain READ before sending it — and only those. Removing
     * by id range instead would also take a row an open transaction wrote
     * earlier but committed after the clear was sent: that change would never
     * be purged. An entry not read here is simply delivered later (one
     * redundant purge, never a lost one).
     *
     * The tag entries then go to the library's {@see Drainer}.
     *
     * @param int $limit Entries of each kind to read.
     * @param bool $ignoreBackoff Deliver entries still in backoff too — an
     *        operator's "deliver now" after fixing the cause (a token, an
     *        outage) must not wait out a retry schedule.
     * @return int Entries removed.
     */
    public function drain(int $limit, bool $ignoreBackoff = false): int
    {
        if ($this->inTransaction()) {
            return 0;
        }
        $instances = [];
        foreach ($this->config->getInstances() as $instance) {
            $instances[$instance->name] = $instance;
        }
        if ($instances === []) {
            return 0;
        }
        $names = array_map('strval', array_keys($instances));
        $now = $this->clock->now();
        try {
            // X03: a row written before instances existed is owed to every one.
            $this->outbox->splitLegacy($limit, $names);
            // Only instances that are still configured: a row owed to one
            // that was removed must not take the drain's slots forever.
            $clears = $this->outbox->dueClears($limit, $now, $ignoreBackoff, $names);
            $tags = $this->outbox->due($limit, $now, $ignoreBackoff, $names);
        } catch (\Throwable $e) {
            $this->outboxUnavailable($e);
            return 0;
        }

        $removed = 0;
        $this->lastPurged = 0;
        $clearsByInstance = [];
        foreach ($clears as $entry) {
            $clearsByInstance[$entry->instance][] = $entry;
        }
        foreach ($clearsByInstance as $name => $owed) {
            $last = max(array_map(fn (OutboxEntry $e): int => $e->id, $owed));
            $covered = array_values(array_filter(
                $tags,
                fn (OutboxEntry $e): bool => $e->instance === $name && $e->id <= $last
            ));
            $attempt = $this->tridentClient->purgeClient($instances[$name])->clear();
            if ($attempt->acknowledged()) {
                $this->lastPurged += (int) $attempt->purged;
                $ids = array_map(fn (OutboxEntry $e): int => $e->id, [...$owed, ...$covered]);
                $this->outbox->remove($ids);
                $removed += count($ids);
            } else {
                $this->outbox->fail($owed, (string) $attempt->failure, $now);
                $this->notAcknowledged((string) $name, 'cache_clear', (string) $attempt->failure);
            }
            // Covered rows ride on the clear: removed with it, or kept for the
            // clear's retry. An instance that did not answer at all is not
            // tried again in this drain.
            $tags = array_values(array_filter(
                $tags,
                fn (OutboxEntry $e): bool => $e->instance !== $name
                    || ($e->id > $last && !$attempt->unreachable)
            ));
        }

        $report = (new Drainer(
            $this->outbox,
            array_values($instances),
            fn (Instance $instance): PurgeClient => $this->tridentClient->purgeClient($instance),
            $this->config->getPurgeMode()
        ))->deliverEntries($tags, $now);
        foreach ($report->instances as $name => $result) {
            if ($result['error'] !== null) {
                $this->notAcknowledged($name, 'purge_tags', $result['error']);
            }
        }

        $this->lastPurged += $report->purged;
        return $removed + $report->delivered;
    }

    /**
     * Cache entries Trident reported purging in the last {@see drain()} (the
     * library's DrainReport, and the entries each full clear removed).
     *
     * @return int
     */
    public function purgedByLastDrain(): int
    {
        return $this->lastPurged;
    }

    /**
     * Whether purges can go anywhere: Trident is the FPC application and an
     * instance is configured. Recording rows nobody can deliver would only
     * grow the table.
     *
     * @return bool
     */
    private function configured(): bool
    {
        return $this->config->isTridentEnabled() && $this->config->getInstances() !== [];
    }

    /**
     * Send now when the record is already durable, else after the commit.
     *
     * @return void
     */
    private function afterRecording(): void
    {
        if ($this->inTransaction()) {
            $this->deferFlush();
            return;
        }
        $this->flush();
    }

    /**
     * Fallback send (no outbox): best effort, as before X02.
     *
     * @param array<string> $tags
     * @return void
     */
    private function sendDirect(array $tags): void
    {
        foreach ($this->config->getInstances() as $instance) {
            $client = $this->tridentClient->purgeClient($instance);
            foreach (Packer::chunk($tags) as $chunk) {
                $attempt = $client->purgeTags($chunk, $this->config->getPurgeMode());
                if (!$attempt->acknowledged()) {
                    $this->notAcknowledged($instance->name, 'purge_tags', (string) $attempt->failure);
                }
            }
        }
    }

    /**
     * Fallback full clear (no outbox): best effort, as before X02.
     *
     * @return void
     */
    private function clearDirect(): void
    {
        foreach ($this->config->getInstances() as $instance) {
            $attempt = $this->tridentClient->purgeClient($instance)->clear();
            if (!$attempt->acknowledged()) {
                $this->notAcknowledged($instance->name, 'cache_clear', (string) $attempt->failure);
            }
        }
    }

    /**
     * @param string $instance
     * @param string $context
     * @param string $failure
     * @return void
     */
    private function notAcknowledged(string $instance, string $context, string $failure): void
    {
        $this->logger->error('Trident did not acknowledge the request', [
            'instance' => $instance,
            'context' => $context,
            'error' => $failure,
        ]);
    }

    /**
     * @param \Throwable $e
     * @return void
     */
    private function outboxUnavailable(\Throwable $e): void
    {
        $this->logger->error(
            'Trident purge outbox unavailable — purges are sent best-effort and can be lost '
            . 'until it is (run bin/magento setup:upgrade)',
            ['error' => $e->getMessage()]
        );
    }

    /**
     * Whether the default connection has a transaction open.
     *
     * @return bool
     */
    private function inTransaction(): bool
    {
        return $this->resourceConnection->getConnection()->getTransactionLevel() > 0;
    }

    /**
     * Register a flush for the commit of the open transaction.
     *
     * One callback per deferral, not one per request: a rollback clears the
     * pool, and the next transaction still needs its own flush.
     *
     * @return void
     */
    private function deferFlush(): void
    {
        CallbackPool::attach(spl_object_hash($this->resourceConnection->getConnection()), [$this, 'flush']);
    }
}
