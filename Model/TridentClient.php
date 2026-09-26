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

use Psr\Log\LoggerInterface;
use Qoliber\Trident\Admin\Api;
use Qoliber\Trident\Admin\ApiError;
use Qoliber\Trident\Admin\Fleet;
use Qoliber\Trident\Admin\Payload;
use Qoliber\Trident\Client\TridentClient as AdminClient;
use Qoliber\Trident\Delivery\Acknowledgement;
use Qoliber\Trident\Delivery\Instance;
use Qoliber\Trident\Delivery\PurgeAttempt;
use Qoliber\Trident\Delivery\PurgeClient;
use Qoliber\Trident\Delivery\Transport;
use Qoliber\Trident\Exception\TridentException;
use Qoliber\TridentCache\Model\Admin\InstanceSelection;

/**
 * The admin API of the store's Trident instances, as the module's screens,
 * plugins and observers use it: decoded JSON arrays, or null when there is no
 * answer to show (the reason in {@see lastFailure()} and the log).
 *
 * A thin adapter over qoliber/trident-php, keeping the module's public
 * methods so controllers and templates do not change:
 *
 * - reads and actions the library returns raw ({@see Payload}) are its typed
 *   {@see AdminClient}'s;
 * - invalidations on several instances run through its {@see Fleet};
 * - purges and clears are judged by its {@see Acknowledgement};
 * - the remaining endpoints go through its {@see Api} request path, because
 *   the typed client's answers for them are normalised objects that drop
 *   fields the screens show (see the library gaps in CHANGELOG 1.8.0).
 *
 * X03: invalidations go to every instance; everything else (dashboard reads,
 * the warmer, launch, reflect, bans …) to one — the instance the admin chose
 * with the switcher on the Trident screens ({@see InstanceSelection}),
 * otherwise the first. A client bound with {@see forInstance()} is always
 * that one instance.
 */
class TridentClient
{
    /** Why the last request was not acknowledged or answered; null after a success. */
    private ?string $lastFailure = null;

    /** Emit the "engine 3 but no token" warning at most once per PHP request. */
    private static bool $tokenMissingLogged = false;

    /** Null — as injected — means "the store's instances"; see the class comment. */
    private ?Instance $instance = null;

    /**
     * @param Transport $transport
     * @param LoggerInterface $logger
     * @param Config $config
     * @param InstanceSelection|null $selection The admin's chosen instance (a proxy: read only in the admin).
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly ?InstanceSelection $selection = null
    ) {
    }

    /**
     * X03: every Trident instance this store invalidates.
     *
     * @return list<Instance>
     */
    public function instances(): array
    {
        return $this->config->getInstances();
    }

    /**
     * X03: a client bound to one instance.
     *
     * @param Instance $instance
     * @return self
     */
    public function forInstance(Instance $instance): self
    {
        $client = clone $this;
        $client->instance = $instance;
        $client->lastFailure = null;
        return $client;
    }

    /**
     * The instance a single-instance call from this client goes to.
     *
     * @return Instance|null Null only when no instance is configured at all.
     */
    public function target(): ?Instance
    {
        if ($this->instance !== null) {
            return $this->instance;
        }
        $instances = $this->instances();
        if ($instances === []) {
            return null;
        }
        $selected = $this->selection?->selected();
        if ($selected !== null) {
            foreach ($instances as $instance) {
                if ($instance->name === $selected) {
                    return $instance;
                }
            }
        }
        return $instances[0];
    }

    /**
     * Whether Trident is the store's cache and can be talked to: an instance
     * with an admin token is configured. A bound client asks about its own
     * instance; otherwise ANY configured instance counts — never the one the
     * admin switcher shows, or choosing an instance without a token would
     * switch off every purge the admin triggers (cache flush, cache clean).
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        if (!$this->config->isTridentEnabled()) {
            return false;
        }
        $instances = $this->instance !== null ? [$this->instance] : $this->instances();
        foreach ($instances as $instance) {
            if ($instance->apiToken !== '') {
                return true;
            }
        }
        if ($instances !== [] && !self::$tokenMissingLogged) {
            // An empty Bearer only earns 401s; say so once, whatever the
            // debug setting.
            self::$tokenMissingLogged = true;
            $this->logger->warning(
                'Trident FPC is enabled (engine 3) but no admin api_token is set — '
                . 'purges and admin calls are disabled until a token is configured.'
            );
        }
        return false;
    }

    /**
     * Purge by tags. Returns Trident's acknowledgement, or null when the purge
     * was not acknowledged.
     *
     * @param array<string> $tags
     * @param array<string> $excludeTags
     * @return array<string, mixed>|null
     */
    public function purgeTags(array $tags, array $excludeTags = []): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeTags($tags, $excludeTags));
        }
        if (empty($tags)) {
            return null;
        }
        $data = [
            'tags' => array_values(array_unique(array_map('strval', $tags))),
            'mode' => $this->config->getPurgeMode(),
        ];
        if (!empty($excludeTags)) {
            $data['exclude_tags'] = array_values(array_unique($excludeTags));
        }
        return $this->invalidate('/admin/purge/tags', $data);
    }

    /**
     * Clear the whole edge cache. Returns Trident's acknowledgement, or null
     * when the clear was not acknowledged.
     *
     * @return array<string, mixed>|null
     */
    public function purgeAll(): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeAll());
        }
        return $this->invalidate('/admin/cache/clear', ['confirm' => true]);
    }

    /**
     * X02: a full clear of one instance, as the outbox delivers it.
     *
     * @param Instance $instance
     * @return PurgeAttempt
     */
    public function clear(Instance $instance): PurgeAttempt
    {
        return $this->send($instance, '/admin/cache/clear', ['confirm' => true])[0];
    }

    /**
     * X02: the library's purge client for one instance, over this transport.
     *
     * @param Instance $instance
     * @return PurgeClient
     */
    public function purgeClient(Instance $instance): PurgeClient
    {
        return new PurgeClient($instance, $this->transport);
    }

    /**
     * X02: why the last request was not acknowledged or answered, or null.
     *
     * @return string|null
     */
    public function lastFailure(): ?string
    {
        return $this->lastFailure;
    }

    /**
     * Get cache statistics
     *
     * @return array<string, mixed>|null
     */
    public function getStats(): ?array
    {
        return $this->call('GET', '/admin/stats');
    }

    /**
     * Get cache rules
     *
     * @return array<string, mixed>|null
     */
    public function getRules(): ?array
    {
        return $this->call('GET', '/admin/rules');
    }

    /**
     * Get Trident health status
     *
     * @return array<string, mixed>|null
     */
    public function getHealth(): ?array
    {
        return $this->call('GET', '/admin/health');
    }

    /**
     * Licence and degraded-mode status: GET /admin/status — `mode` is
     * 'licensed' (caching active), 'degraded' (pass-through) or 'unknown'.
     *
     * @return array<string, mixed>|null
     */
    public function getStatus(): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->status());
    }

    /**
     * Dry-run cacheability diagnostic: POST /admin/explain. A `host` header
     * names the site of a path; other headers cannot be sent through the
     * library (a gap listed in CHANGELOG 1.8.0).
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>|null
     */
    public function explain(string $method, string $url, array $headers = [], bool $detail = true): ?array
    {
        $host = array_change_key_case($headers)['host'] ?? '';
        if ($host !== '' && str_starts_with($url, '/')) {
            $url = 'http://' . $host . $url;
        }
        return $this->read(fn (AdminClient $c): Payload => $c->explain($url, $method, $detail));
    }

    /**
     * Get cache entries with optional filtering
     *
     * @param int $offset
     * @param int $limit
     * @param string|null $tag
     * @param string $sort
     * @return array<string, mixed>|null
     */
    public function getEntries(int $offset = 0, int $limit = 50, ?string $tag = null, string $sort = 'age'): ?array
    {
        return $this->call('GET', '/admin/cache/entries', [
            'offset' => $offset,
            'limit' => $limit,
            'sort' => $sort,
            'tag' => $tag !== '' ? $tag : null,
        ]);
    }

    /**
     * Get cache tags with optional prefix filtering
     *
     * @param int $offset
     * @param int $limit
     * @param string|null $prefix
     * @param string $sort
     * @return array<string, mixed>|null
     */
    public function getTags(int $offset = 0, int $limit = 100, ?string $prefix = null, string $sort = 'count'): ?array
    {
        return $this->call('GET', '/admin/cache/tags', [
            'offset' => $offset,
            'limit' => $limit,
            'sort' => $sort,
            'prefix' => $prefix !== '' ? $prefix : null,
        ]);
    }

    /**
     * Get top URLs by request count
     *
     * @param int $limit
     * @param string $sort
     * @return array<string, mixed>|null
     */
    public function getTopUrls(int $limit = 20, string $sort = 'requests'): ?array
    {
        return $this->call('GET', '/admin/stats/top', ['limit' => $limit, 'sort' => $sort]);
    }

    /**
     * Purge a single URL from cache
     *
     * @param string $url
     * @param string|null $host
     * @return array<string, mixed>|null
     */
    public function purgeUrl(string $url, ?string $host = null): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeUrl($url, $host));
        }
        if (empty($url)) {
            return null;
        }
        $data = ['url' => $url, 'mode' => $this->config->getPurgeMode()];
        if ($host !== null && $host !== '') {
            $data['host'] = $host;
        }
        return $this->call('POST', '/admin/purge/url', [], $data);
    }

    /**
     * Purge cache by URL pattern
     *
     * @param string $pattern
     * @return array<string, mixed>|null
     */
    public function purgePattern(string $pattern): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgePattern($pattern));
        }
        if (empty($pattern)) {
            return null;
        }
        return $this->call('POST', '/admin/purge/urls', [], [
            'pattern' => $pattern,
            'mode' => $this->config->getPurgeMode(),
        ]);
    }

    // =========================================================================
    // Cache Warmer
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getWarmerStatus(): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->warmerStatus());
    }

    /**
     * Warm the configured sources — or, given URLs, exactly those: the
     * engine's run takes no body, so a URL list goes to the warmer's queue
     * (before, the list was sent to /admin/warmer/run and silently ignored).
     *
     * @param array<int, string> $urls
     * @return array<string, mixed>|null
     */
    public function warmerRun(array $urls = []): ?array
    {
        if ($urls !== []) {
            return $this->warmerQueue($urls);
        }
        return $this->read(fn (AdminClient $c): Payload => $c->warmerRun());
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, mixed>|null
     */
    public function warmerQueue(array $urls): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->warmerQueue(array_values($urls)));
    }

    /** @return array<string, mixed>|null */
    public function warmerCancel(): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->warmerCancel());
    }

    // =========================================================================
    // Cache Coverage
    // =========================================================================

    /**
     * Batch cache-membership check: per-URL cached/not + aggregate percentage.
     * `$method` is kept for compatibility; the engine checks GET entries.
     *
     * @param array<int, string> $urls
     * @return array<string, mixed>|null
     */
    public function cacheCoverage(
        array $urls,
        ?string $host = null,
        string $scheme = 'https',
        string $method = 'GET'
    ): ?array {
        return $this->read(fn (AdminClient $c): Payload => $c->coverage(
            array_values($urls),
            $host !== null && $host !== '' ? $host : null,
            $scheme
        ));
    }

    // =========================================================================
    // Launch Mode
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getLaunchStatus(): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->launch());
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function launchStart(array $options = []): ?array
    {
        return $this->call('POST', '/admin/launch/start', [], $options);
    }

    /** @return array<string, mixed>|null */
    public function launchComplete(): ?array
    {
        return $this->call('POST', '/admin/launch/complete', [], []);
    }

    /** @return array<string, mixed>|null */
    public function launchAbort(?string $reason = null): ?array
    {
        $data = $reason !== null && $reason !== '' ? ['reason' => $reason] : [];
        return $this->call('POST', '/admin/launch/abort', [], $data);
    }

    // =========================================================================
    // Reflect Mode
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getReflectStatus(): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->reflectStatus());
    }

    /** @return array<string, mixed>|null */
    public function getReflectQueue(): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->reflectQueue());
    }

    /**
     * @param string $level full|selective|ttl_extension
     * @return array<string, mixed>|null
     */
    public function reflectEnable(string $level = 'full', ?string $duration = null, ?string $reason = null): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->reflectEnable($level, $duration, $reason));
    }

    /**
     * @param string $mode replay|hard
     * @return array<string, mixed>|null
     */
    public function reflectDisable(string $mode = 'replay'): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->reflectDisable($mode));
    }

    // =========================================================================
    // Denoisers
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getDenoiserReport(): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->denoiserReport());
    }

    /** @return array<string, mixed>|null */
    public function getDenoiserQueryScopes(): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->denoiserQueryScopes());
    }

    /** @return array<string, mixed>|null */
    public function getDenoiserPathZones(): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->denoiserPathZones());
    }

    /**
     * The engine also requires `class` (noise|signal), which this screen does
     * not collect yet — see "Known" in CHANGELOG 1.8.0.
     *
     * @return array<string, mixed>|null
     */
    public function denoiserQueryPin(string $param, ?string $pathPrefix = null): ?array
    {
        $data = ['param' => $param];
        if ($pathPrefix !== null && $pathPrefix !== '') {
            $data['path_prefix'] = $pathPrefix;
        }
        return $this->call('POST', '/admin/denoisers/query/pin', [], $data);
    }

    /** @return array<string, mixed>|null */
    public function denoiserQueryUnpin(string $param, ?string $pathPrefix = null): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->denoiserQueryUnpin(
            $param,
            '*',
            $pathPrefix !== null && $pathPrefix !== '' ? $pathPrefix : '/'
        ));
    }

    /** @return array<string, mixed>|null */
    public function denoiserQueryReset(): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->denoiserReset('query'));
    }

    /**
     * The engine also requires `status` (dead|alive), which this screen does
     * not collect yet — see "Known" in CHANGELOG 1.8.0.
     *
     * @return array<string, mixed>|null
     */
    public function denoiserPathPin(string $host, string $pathPrefix): ?array
    {
        return $this->call('POST', '/admin/denoisers/path/pin', [], ['host' => $host, 'path_prefix' => $pathPrefix]);
    }

    /** @return array<string, mixed>|null */
    public function denoiserPathUnpin(string $host, string $pathPrefix): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->denoiserPathUnpin($host, $pathPrefix));
    }

    /** @return array<string, mixed>|null */
    public function denoiserPathReset(): ?array
    {
        return $this->read(fn (AdminClient $c): Payload => $c->denoiserReset('path'));
    }

    /** @return array<string, mixed>|null */
    public function getDenoiserWafExport(): ?array
    {
        return $this->call('GET', '/admin/denoisers/export/waf');
    }

    // =========================================================================
    // Bans (soft purge)
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getBans(): ?array
    {
        return $this->call('GET', '/admin/bans');
    }

    /**
     * @param string $type url|tag|pattern|bulkurl|tags (Trident BanType enum)
     * @return array<string, mixed>|null
     */
    public function createBan(string $pattern, string $type = 'url'): ?array
    {
        // X03: one instance only, like listing and deleting them — ban ids
        // are per engine, so a ban created everywhere could be deleted from
        // one instance and live on, unseen, on the others.
        return $this->call('POST', '/admin/bans', [], ['pattern' => $pattern, 'type' => $type]);
    }

    /** @return array<string, mixed>|null */
    public function deleteBan(string $id): ?array
    {
        return $this->call('DELETE', '/admin/bans/' . rawurlencode($id));
    }

    // =========================================================================
    // Backends + connection pools
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getBackends(): ?array
    {
        return $this->call('GET', '/admin/backends');
    }

    /** @return array<string, mixed>|null */
    public function getBackendDetail(string $name): ?array
    {
        return $this->call('GET', '/admin/backends/detail', ['name' => $name]);
    }

    /** @return array<string, mixed>|null */
    public function getConnections(): ?array
    {
        return $this->call('GET', '/admin/connections');
    }

    /** @return array<string, mixed>|null */
    public function drainBackend(string $name): ?array
    {
        return $this->call('POST', '/admin/backends/drain', [], ['name' => $name]);
    }

    /** @return array<string, mixed>|null */
    public function restoreBackend(string $name): ?array
    {
        return $this->call('POST', '/admin/backends/restore', [], ['name' => $name]);
    }

    // =========================================================================
    // DNS discovery
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getDiscovery(): ?array
    {
        return $this->call('GET', '/admin/discovery');
    }

    /** @return array<string, mixed>|null */
    public function getDiscoveryDetail(string $name): ?array
    {
        return $this->call('GET', '/admin/discovery/detail', ['name' => $name]);
    }

    /**
     * Force a DNS re-resolve for a single discovery target. Trident's
     * /admin/discovery/refresh requires the backend `name` as a query param.
     *
     * @return array<string, mixed>|null
     */
    public function refreshDiscovery(string $name): ?array
    {
        return $this->call('POST', '/admin/discovery/refresh', ['name' => $name], []);
    }

    // =========================================================================
    // Extended statistics + memory
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getLatencyStats(): ?array
    {
        return $this->call('GET', '/admin/stats/latency');
    }

    /** @return array<string, mixed>|null */
    public function getErrorStats(int $limit = 20): ?array
    {
        return $this->call('GET', '/admin/stats/errors', ['limit' => $limit]);
    }

    /** @return array<string, mixed>|null */
    public function getProtectionStats(): ?array
    {
        return $this->call('GET', '/admin/stats/protection');
    }

    /** @return array<string, mixed>|null */
    public function getMemory(): ?array
    {
        return $this->call('GET', '/admin/memory');
    }

    /** @return array<string, mixed>|null */
    public function getRefreshQueue(): ?array
    {
        return $this->call('GET', '/admin/refresh/queue');
    }

    // =========================================================================
    // Extended purge (host / vary / tag pattern)
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function purgeHost(string $host): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeHost($host));
        }
        return $this->call('POST', '/admin/purge/host', [], ['host' => $host, 'mode' => $this->config->getPurgeMode()]);
    }

    /** @return array<string, mixed>|null */
    public function purgeVary(string $header, string $value): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeVary($header, $value));
        }
        return $this->call('POST', '/admin/purge/vary', [], [
            'header' => $header,
            'value' => $value,
            'mode' => $this->config->getPurgeMode(),
        ]);
    }

    /**
     * Tag-pattern purge: POST /admin/purge/tag/pattern — every entry whose
     * tag matches a wildcard (default) or regex, e.g. `cat_c_*`.
     *
     * @return array<string, mixed>|null
     */
    public function purgeTagPattern(string $pattern, bool $regex = false): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeTagPattern($pattern, $regex));
        }
        return $this->call('POST', '/admin/purge/tag/pattern', [], [
            'pattern' => $pattern,
            'pattern_type' => $regex ? 'regex' : 'wildcard',
            'mode' => $this->config->getPurgeMode(),
        ]);
    }

    // =========================================================================
    // Requests
    // =========================================================================

    /**
     * Whether an invalidation from this client must go to several instances.
     *
     * @return bool
     */
    private function fansOut(): bool
    {
        return $this->instance === null && count($this->instances()) > 1;
    }

    /**
     * X03: an invalidation on every instance, through the library's Fleet. It
     * counts as done only when every instance answered: a purge that reached
     * edge-1 but not edge-2 leaves edge-2 serving the old page, so it is
     * reported as failed, with the instances that failed and why in
     * {@see lastFailure()}. Counts (`purged`, `affected` …) are summed;
     * per-instance answers are under `instances`.
     *
     * @param callable(self): (array<string, mixed>|null) $call
     * @return array<string, mixed>|null
     */
    private function onEveryInstance(callable $call): ?array
    {
        $fleet = new Fleet($this->instances(), $this->transport, $this->logger);
        $results = [];
        $failed = [];
        foreach ($fleet->each(function (AdminClient $admin, Instance $instance) use ($call): array {
            $client = $this->forInstance($instance);
            return $call($client) ?? throw new TridentException($client->lastFailure() ?? 'no acknowledgement');
        }) as $result) {
            $results[$result->name()] = $result->value;
            if (!$result->isOk()) {
                $failed[] = $result->name() . ': ' . $result->reason();
            }
        }
        if ($failed !== []) {
            $this->lastFailure = implode('; ', $failed);
            return null;
        }
        $this->lastFailure = null;
        /** @var array<string, array<string, mixed>> $results */
        $merged = reset($results);
        foreach (['purged', 'affected', 'queued_refresh', 'entries_removed', 'bytes_freed'] as $count) {
            $values = array_column($results, $count);
            if (count($values) === count($results) && array_filter($values, 'is_int') === $values) {
                $merged[$count] = array_sum($values);
            }
        }
        $merged['instances'] = $results;
        return $merged;
    }

    /**
     * The instance a single-instance call goes to, when one can be made — with
     * a token: an empty Bearer only earns a 401.
     *
     * @return Instance|null
     */
    private function ready(): ?Instance
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $target = $this->target();
        if ($target !== null && $target->apiToken === '') {
            $this->lastFailure = sprintf('instance "%s" has no API token', $target->name);
            return null;
        }
        return $target;
    }

    /**
     * A call of the library's typed client on the target instance: its raw
     * answer, or null with the reason.
     *
     * @param callable(AdminClient): Payload $call
     * @return array<string, mixed>|null
     */
    private function read(callable $call): ?array
    {
        $target = $this->ready();
        if ($target === null) {
            return null;
        }
        try {
            $data = $call(AdminClient::forInstance($target, $this->transport, $this->logger))->all();
        } catch (\Throwable $e) {
            return $this->failed($target, 'admin API', $e);
        }
        $this->lastFailure = null;
        return $data;
    }

    /**
     * One endpoint through the library's {@see Api}: the decoded body of a 2xx
     * JSON answer, or null — with the reason in {@see lastFailure()} and the
     * log — for an error status, a redirect, a non-JSON answer or none at all.
     * An error body is never handed to a caller as the data it asked for.
     *
     * @param string $method
     * @param string $path
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null
     */
    private function call(string $method, string $path, array $query = [], ?array $body = null): ?array
    {
        $target = $this->ready();
        if ($target === null) {
            return null;
        }
        try {
            $data = (new Api($target, $this->transport, $this->logger))->call($method, $path, $query, $body)['data'];
        } catch (\Throwable $e) {
            return $this->failed($target, $method . ' ' . $path, $e);
        }
        $this->lastFailure = null;
        if ($method !== 'GET' && $this->config->isDebugEnabled()) {
            $this->logger->info('Trident ' . $method, [
                'instance' => $target->name,
                'path' => $path,
                'data' => $body,
                'result' => $data,
            ]);
        }
        return $data;
    }

    /**
     * @param Instance $target
     * @param string $request
     * @param \Throwable $e
     * @return null
     */
    private function failed(Instance $target, string $request, \Throwable $e): ?array
    {
        $reason = $e instanceof ApiError ? $e->reason() : $e->getMessage();
        $this->lastFailure = $request . ': ' . $reason;
        $this->logger->error('Trident admin request failed', [
            'instance' => $target->name,
            'request' => $request,
            'reason' => $reason,
        ]);
        return null;
    }

    /**
     * A purge or clear on this client's target: the decoded acknowledgement,
     * or null (the reason in {@see lastFailure()} and the log).
     *
     * @param string $path
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function invalidate(string $path, array $data): ?array
    {
        $target = $this->ready();
        if ($target === null) {
            return null;
        }
        [$attempt, $body] = $this->send($target, $path, $data);
        if ($this->config->isDebugEnabled()) {
            $this->logger->info('Trident ' . $path, ['instance' => $target->name, 'data' => $data, 'result' => $body]);
        }
        $context = $path === '/admin/cache/clear' ? 'cache_clear' : 'purge_tags';
        if ($attempt->acknowledged()) {
            $this->lastFailure = null;
            return $body ?? [];
        }
        $this->lastFailure = $context . ': ' . $attempt->failure;
        $this->logger->error('Trident did not acknowledge the request', [
            'instance' => $target->name,
            'context' => $context,
            'error' => $attempt->failure,
        ]);
        return null;
    }

    /**
     * The one place a purge or clear is sent and judged: the library's
     * {@see Acknowledgement} — HTTP 200 with the engine's schema for the
     * endpoint, or (a purge) Reflect mode's 202 `recorded`. Anything else —
     * 401, 429, 5xx, a full queue, a proxy's HTML page, an error object sent
     * with a 200 — is not a purge that happened.
     *
     * @param Instance $instance
     * @param string $path
     * @param array<string, mixed> $data
     * @return array{0: PurgeAttempt, 1: array<string, mixed>|null} The attempt, and the decoded answer.
     */
    private function send(Instance $instance, string $path, array $data): array
    {
        $response = $this->transport->request(
            'POST',
            $instance->apiUrl . $path,
            Api::headers($instance),
            (string) json_encode($data)
        );
        if ($response['status'] === 0) {
            $error = (string) ($response['error'] ?? '');
            return [new PurgeAttempt('no response' . ($error !== '' ? ' — ' . $error : ''), true), null];
        }
        $failure = $path === '/admin/cache/clear'
            ? Acknowledgement::clearFailure($response['status'], $response['body'])
            : Acknowledgement::purgeFailure($response['status'], $response['body']);
        $decoded = json_decode($response['body'], true);
        return [new PurgeAttempt($failure), is_array($decoded) ? $decoded : null];
    }
}
