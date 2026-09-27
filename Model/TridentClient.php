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
use Qoliber\Trident\Client\TridentClient as AdminClient;
use Qoliber\Trident\Delivery\Instance;
use Qoliber\Trident\Delivery\PurgeClient;
use Qoliber\Trident\Delivery\Transport;
use Qoliber\Trident\Exception\InvalidRequest;
use Qoliber\Trident\Exception\TridentException;
use Qoliber\Trident\Purge\PurgeRequest;
use Qoliber\Trident\Response\ClearResponse;
use Qoliber\Trident\Response\PurgeResponse;
use Qoliber\TridentCache\Model\Admin\InstanceSelection;

/**
 * The admin API of the store's Trident instances, as the module's screens,
 * plugins and observers use it: the engine's decoded answer, or null when
 * there is none to show (the reason in {@see lastFailure()} and the log).
 *
 * A thin adapter over qoliber/trident-php's typed client ({@see AdminClient}),
 * keeping the module's public methods so controllers and templates do not
 * change: every call is the typed client's, and the screens get its `raw()`
 * answer — the engine's JSON as sent. Purges go through `purge(PurgeRequest)`
 * with the store's soft/hard mode always explicit, and are judged by the
 * library's acknowledgement rules; invalidations on several instances run
 * through its {@see Fleet}.
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
        if ($this->instance !== null) {
            return $this->instance->apiToken !== '';
        }
        $instances = $this->instances();
        foreach ($instances as $instance) {
            if ($instance->apiToken !== '') {
                return true;
            }
        }
        if ($instances !== [] && !self::$tokenMissingLogged) {
            // Not one instance can be asked: an empty Bearer only earns 401s.
            // Say so once, whatever the debug setting.
            self::$tokenMissingLogged = true;
            $this->logger->warning(
                'Trident FPC is enabled (engine 3) but no admin api_token is set — '
                . 'purges and admin calls are disabled until a token is configured.'
            );
        }
        return false;
    }

    /**
     * X02: the library's purge client for one instance — tag purges and full
     * clears as the outbox delivers them.
     *
     * @param Instance $instance
     * @return PurgeClient
     */
    public function purgeClient(Instance $instance): PurgeClient
    {
        return new PurgeClient($instance, $this->transport);
    }

    /**
     * How many cache entries an acknowledged purge or clear removed, as
     * Trident reported it (`purged`, or `entries_removed` for a clear; summed
     * over the instances of a fan-out) — null when it did not say (a purge
     * Reflect mode deferred has purged nothing yet).
     *
     * @param array<string, mixed> $answer A purge method's answer.
     * @return int|null
     */
    public function purgedCount(array $answer): ?int
    {
        $count = $answer['purged'] ?? $answer['entries_removed'] ?? null;
        return is_int($count) ? $count : null;
    }

    /**
     * What an acknowledged purge or clear did, for a success message: the
     * entries purged ("marked stale" for a soft purge — they are served until
     * refreshed), the entries a clear removed, or, when not every instance
     * reported a count, on how many instances it applied and how many
     * deferred it. Empty when the answer says nothing.
     *
     * @param array<string, mixed> $answer A purge method's answer.
     * @return string
     */
    public function describePurge(array $answer): string
    {
        if (is_int($answer['entries_removed'] ?? null)) {
            return (string) __('%1 entries removed', $answer['entries_removed']);
        }
        if (is_int($answer['purged'] ?? null)) {
            return $this->config->getPurgeMode() === 'soft'
                ? (string) __('%1 entries purged (soft purge: marked stale, refreshed on the next request)', $answer['purged'])
                : (string) __('%1 entries purged', $answer['purged']);
        }
        $instances = is_array($answer['instances'] ?? null) ? $answer['instances'] : null;
        if ($instances === null) {
            return $this->deferred($answer) ? (string) __('deferred by Reflect mode, applied when it ends') : '';
        }
        $deferred = count(array_filter($instances, fn ($a): bool => is_array($a) && $this->deferred($a)));
        return (string) __(
            'applied on %1 of %2 instances (%3 deferred by Reflect mode)',
            count($instances) - $deferred,
            count($instances),
            $deferred
        );
    }

    /**
     * @param array<string, mixed> $answer
     * @return bool
     */
    private function deferred(array $answer): bool
    {
        return ($answer['state'] ?? null) === 'recorded' || ($answer['status'] ?? null) === 'deferred';
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

    // =========================================================================
    // Purges
    // =========================================================================

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
        $request = PurgeRequest::tags(array_values(array_unique(array_map('strval', $tags))));
        if (!empty($excludeTags)) {
            $request = $request->excluding(array_values(array_unique(array_map('strval', $excludeTags))));
        }
        return $this->purge('purge_tags', $request);
    }

    /**
     * Clear the whole edge cache. Returns Trident's acknowledgement (with
     * `entries_removed`), or null when the clear was not acknowledged.
     *
     * @return array<string, mixed>|null
     */
    public function purgeAll(): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeAll());
        }
        return $this->acknowledged('cache_clear', fn (AdminClient $c): ClearResponse => $c->clearCache());
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
        // A path on a named host, with no scheme: the same entry the screens
        // list (a scheme-relative URL keeps the library from inventing one).
        $target = $host !== null && $host !== '' && str_starts_with($url, '/') ? '//' . $host . $url : $url;
        return $this->purge('purge_url', PurgeRequest::url($target));
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
        return $this->acknowledged(
            'purge_pattern',
            fn (AdminClient $c): PurgeResponse => $c->purgeUrlPattern($pattern, $this->soft())
        );
    }

    /** @return array<string, mixed>|null */
    public function purgeHost(string $host): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeHost($host));
        }
        return $this->acknowledged(
            'purge_host',
            fn (AdminClient $c): PurgeResponse => $c->purgeHost($host, $this->soft())
        );
    }

    /** @return array<string, mixed>|null */
    public function purgeVary(string $header, string $value): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeVary($header, $value));
        }
        return $this->acknowledged(
            'purge_vary',
            fn (AdminClient $c): PurgeResponse => $c->purgeVary($header, $value, $this->soft())
        );
    }

    /**
     * Tag-pattern purge: every entry whose tag matches a wildcard (default) or
     * regex, e.g. `cat_c_*`.
     *
     * @return array<string, mixed>|null
     */
    public function purgeTagPattern(string $pattern, bool $regex = false): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeTagPattern($pattern, $regex));
        }
        return $this->purge('purge_tag_pattern', PurgeRequest::pattern(
            $pattern,
            $regex ? PurgeRequest::PATTERN_REGEX : PurgeRequest::PATTERN_WILDCARD
        ));
    }

    // =========================================================================
    // Statistics and status
    // =========================================================================

    /**
     * Get cache statistics
     *
     * @return array<string, mixed>|null
     */
    public function getStats(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->stats(), 'GET /admin/stats');
    }

    /**
     * Get cache rules
     *
     * @return array<string, mixed>|null
     */
    public function getRules(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->rules(), 'GET /admin/rules');
    }

    /**
     * Get Trident health status
     *
     * @return array<string, mixed>|null
     */
    public function getHealth(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->health(), 'GET /admin/health');
    }

    /**
     * Licence and degraded-mode status: GET /admin/status — `mode` is
     * 'licensed' (caching active), 'degraded' (pass-through) or 'unknown'.
     *
     * @return array<string, mixed>|null
     */
    public function getStatus(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->status(), 'GET /admin/status');
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
        return $this->read(
            fn (AdminClient $c) => $c->cacheEntries($limit, $offset, null, $sort, $tag !== '' ? $tag : null),
            'GET /admin/cache/entries'
        );
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
        return $this->read(
            fn (AdminClient $c) => $c->cacheTags($limit, $offset, null, $sort, $prefix !== '' ? $prefix : null),
            'GET /admin/cache/tags'
        );
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
        return $this->read(fn (AdminClient $c) => $c->topUrls($limit, $sort), 'GET /admin/stats/top');
    }

    /** @return array<string, mixed>|null */
    public function getLatencyStats(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->latencyStats(), 'GET /admin/stats/latency');
    }

    /** @return array<string, mixed>|null */
    public function getErrorStats(int $limit = 20): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->errorStats($limit), 'GET /admin/stats/errors');
    }

    /** @return array<string, mixed>|null */
    public function getProtectionStats(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->protectionStats(), 'GET /admin/stats/protection');
    }

    /** @return array<string, mixed>|null */
    public function getMemory(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->memoryStats(), 'GET /admin/memory');
    }

    /** @return array<string, mixed>|null */
    public function getRefreshQueue(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->refreshQueue(), 'GET /admin/refresh/queue');
    }

    // =========================================================================
    // Cache Warmer
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getWarmerStatus(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->warmerStatus(), 'GET /admin/warmer/status');
    }

    /**
     * Warm the configured sources — or, given URLs, exactly those: the
     * engine's run takes no body, so a URL list goes to the warmer's queue.
     *
     * @param array<int, string> $urls
     * @return array<string, mixed>|null
     */
    public function warmerRun(array $urls = []): ?array
    {
        if ($urls !== []) {
            return $this->warmerQueue($urls);
        }
        return $this->read(fn (AdminClient $c) => $c->warmerRun(), 'POST /admin/warmer/run');
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, mixed>|null
     */
    public function warmerQueue(array $urls): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->warmerQueue(array_values($urls)), 'POST /admin/warmer/queue');
    }

    /** @return array<string, mixed>|null */
    public function warmerCancel(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->warmerCancel(), 'POST /admin/warmer/cancel');
    }

    // =========================================================================
    // Cache Coverage
    // =========================================================================

    /**
     * Batch cache-membership check: per-URL cached/not + aggregate percentage,
     * for the entries of `$method` requests (the engine's
     * CacheCoverageRequest.method). The library's `coverage()` cannot send the
     * method, so this one call names its endpoint itself, through the
     * library's {@see Api}.
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
        $data = ['urls' => array_values($urls), 'scheme' => $scheme, 'method' => $method];
        if ($host !== null && $host !== '') {
            $data['host'] = $host;
        }
        return $this->read(
            fn (AdminClient $c, Instance $target): array => (new Api($target, $this->transport, $this->logger))
                ->call('POST', '/admin/cache/coverage', [], $data)['data'],
            'POST /admin/cache/coverage'
        );
    }

    // =========================================================================
    // Launch Mode
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getLaunchStatus(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->launch(), 'GET /admin/launch/status');
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function launchStart(array $options = []): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->launchStart($options), 'POST /admin/launch/start');
    }

    /** @return array<string, mixed>|null */
    public function launchComplete(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->launchComplete(), 'POST /admin/launch/complete');
    }

    /** @return array<string, mixed>|null */
    public function launchAbort(?string $reason = null): ?array
    {
        return $this->read(
            fn (AdminClient $c) => $c->launchAbort(null, $reason !== null && $reason !== '' ? $reason : null),
            'POST /admin/launch/abort'
        );
    }

    // =========================================================================
    // Reflect Mode
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getReflectStatus(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->reflectStatus(), 'GET /admin/reflect/status');
    }

    /** @return array<string, mixed>|null */
    public function getReflectQueue(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->reflectQueue(), 'GET /admin/reflect/queue');
    }

    /**
     * @param string $level full|selective|ttl_extension
     * @return array<string, mixed>|null
     */
    public function reflectEnable(string $level = 'full', ?string $duration = null, ?string $reason = null): ?array
    {
        return $this->read(
            fn (AdminClient $c) => $c->reflectEnable($level, $duration, $reason),
            'POST /admin/reflect/enable'
        );
    }

    /**
     * @param string $mode replay|hard
     * @return array<string, mixed>|null
     */
    public function reflectDisable(string $mode = 'replay'): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->reflectDisable($mode), 'POST /admin/reflect/disable');
    }

    // =========================================================================
    // Denoisers
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getDenoiserReport(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->denoiserReport(), 'GET /admin/denoisers/report');
    }

    /** @return array<string, mixed>|null */
    public function getDenoiserQueryScopes(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->denoiserQueryScopes(), 'GET /admin/denoisers/query/scopes');
    }

    /** @return array<string, mixed>|null */
    public function getDenoiserPathZones(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->denoiserPathZones(), 'GET /admin/denoisers/path/zones');
    }

    /**
     * Pin a query parameter as `noise` or `signal` on one scope: `$host` as
     * Trident sees it (the request's Host, port included) and the scope's
     * path prefix, both exactly as the scope list shows them. Trident looks a
     * scope up by the request's real host, with no `*` fallback, so a pin on
     * `*` would never be consulted and is refused.
     *
     * @param string $param
     * @param string $class noise|signal
     * @param string $host
     * @param string $pathPrefix
     * @return array<string, mixed>|null
     * @throws InvalidRequest Input the pin cannot take: the caller's to show as a form error.
     */
    public function denoiserQueryPin(string $param, string $class, string $host, string $pathPrefix): ?array
    {
        $this->assertPinHost($host);
        return $this->read(
            fn (AdminClient $c) => $c->denoiserQueryPin($param, $class, $host, $this->prefix($pathPrefix)),
            'POST /admin/denoisers/query/pin'
        );
    }

    /**
     * Unpin a query parameter on one scope. `*` is accepted, to remove the
     * inert `*` scopes earlier pins created.
     *
     * @param string $param
     * @param string|null $pathPrefix
     * @param string $host
     * @return array<string, mixed>|null
     */
    public function denoiserQueryUnpin(string $param, ?string $pathPrefix = null, string $host = '*'): ?array
    {
        return $this->read(
            fn (AdminClient $c) => $c->denoiserQueryUnpin($param, $host !== '' ? $host : '*', $this->prefix($pathPrefix)),
            'POST /admin/denoisers/query/unpin'
        );
    }

    /** @return array<string, mixed>|null */
    public function denoiserQueryReset(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->denoiserReset('query'), 'POST /admin/denoisers/query/reset');
    }

    /**
     * Pin a path zone as `dead` or `alive`, on the zone's real host (see
     * {@see denoiserQueryPin()}: a pin on `*` is never consulted, and refused).
     *
     * @param string $host
     * @param string $pathPrefix
     * @param string $status dead|alive — anything else is refused before it is sent.
     * @return array<string, mixed>|null
     * @throws InvalidRequest An invalid status: the caller's input, shown as a form error.
     */
    public function denoiserPathPin(string $host, string $pathPrefix, string $status = ''): ?array
    {
        $this->assertPinHost($host);
        return $this->read(
            fn (AdminClient $c) => $c->denoiserPathPin($status, $host, $pathPrefix),
            'POST /admin/denoisers/path/pin'
        );
    }

    /** @return array<string, mixed>|null */
    public function denoiserPathUnpin(string $host, string $pathPrefix): ?array
    {
        return $this->read(
            fn (AdminClient $c) => $c->denoiserPathUnpin($host, $pathPrefix),
            'POST /admin/denoisers/path/unpin'
        );
    }

    /** @return array<string, mixed>|null */
    public function denoiserPathReset(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->denoiserReset('path'), 'POST /admin/denoisers/path/reset');
    }

    /**
     * The WAF export (`trident-waf-v1`) of the whole instance. Narrowing it to
     * this store's hosts is not done here — see the library gaps in CHANGELOG
     * 1.8.0.
     *
     * @return array<string, mixed>|null
     */
    public function getDenoiserWafExport(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->wafExport(), 'GET /admin/denoisers/export/waf');
    }

    // =========================================================================
    // Bans (soft purge)
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getBans(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->bans(), 'GET /admin/bans');
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
        return $this->read(fn (AdminClient $c) => $c->createBan($pattern, $type), 'POST /admin/bans');
    }

    /** @return array<string, mixed>|null */
    public function deleteBan(string $id): ?array
    {
        return $this->read(
            fn (AdminClient $c): array => ['deleted' => $c->deleteBan($id)],
            'DELETE /admin/bans/' . rawurlencode($id)
        );
    }

    // =========================================================================
    // Backends + connection pools
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getBackends(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->backends(), 'GET /admin/backends');
    }

    /** @return array<string, mixed>|null */
    public function getBackendDetail(string $name): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->backendDetail($name), 'GET /admin/backends/detail');
    }

    /** @return array<string, mixed>|null */
    public function getConnections(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->connections(), 'GET /admin/connections');
    }

    /** @return array<string, mixed>|null */
    public function drainBackend(string $name): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->drainBackend($name), 'POST /admin/backends/drain');
    }

    /** @return array<string, mixed>|null */
    public function restoreBackend(string $name): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->restoreBackend($name), 'POST /admin/backends/restore');
    }

    // =========================================================================
    // DNS discovery
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getDiscovery(): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->discoveryList(), 'GET /admin/discovery');
    }

    /** @return array<string, mixed>|null */
    public function getDiscoveryDetail(string $name): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->discoveryDetail($name), 'GET /admin/discovery/detail');
    }

    /**
     * Force a DNS re-resolve for a single discovery target.
     *
     * @return array<string, mixed>|null
     */
    public function refreshDiscovery(string $name): ?array
    {
        return $this->read(fn (AdminClient $c) => $c->discoveryRefresh($name), 'POST /admin/discovery/refresh');
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
     * {@see lastFailure()}. Counts (`purged`, `entries_removed` …) are summed;
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
            // A total only when EVERY instance reported one: a deferred purge
            // (Reflect mode) has purged nothing yet, and one instance's count
            // is not the total.
            if (count($values) === count($results) && array_filter($values, 'is_int') === $values) {
                $merged[$count] = array_sum($values);
            } else {
                unset($merged[$count]);
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
        if (!$this->config->isTridentEnabled()) {
            return null;
        }
        $target = $this->target();
        if ($target !== null && $target->apiToken === '') {
            $this->lastFailure = sprintf('instance "%s" has no API token', $target->name);
            return null;
        }
        return $target !== null && $this->isEnabled() ? $target : null;
    }

    /**
     * A call of the library's typed client on the target instance: the
     * engine's answer as sent (`raw()` of the typed response), or null with
     * the reason. An {@see InvalidRequest} — input the library refuses to
     * send — is the caller's to show, and is thrown.
     *
     * @param callable(AdminClient, Instance): mixed $call Returns a typed response, a Payload or an array.
     * @param string $request Method and path, for the log and lastFailure().
     * @return array<string, mixed>|null
     * @throws InvalidRequest
     */
    private function read(callable $call, string $request): ?array
    {
        $target = $this->ready();
        if ($target === null) {
            return null;
        }
        try {
            $answer = $call(AdminClient::forInstance($target, $this->transport, $this->logger), $target);
        } catch (InvalidRequest $e) {
            $this->lastFailure = $request . ': ' . $e->getMessage();
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($target, $request, $e);
        }
        $this->lastFailure = null;
        $data = is_array($answer) ? $answer : $answer->raw();
        if (!str_starts_with($request, 'GET ') && $this->config->isDebugEnabled()) {
            $this->logger->info('Trident ' . $request, ['instance' => $target->name, 'result' => $data]);
        }
        return $data;
    }

    /**
     * A purge through `purge(PurgeRequest)`, always with the store's soft/hard
     * mode: a request without one would take the engine's default_purge_mode.
     *
     * @param string $context
     * @param PurgeRequest $request
     * @return array<string, mixed>|null
     */
    private function purge(string $context, PurgeRequest $request): ?array
    {
        $request = $this->soft() ? $request->soft() : $request->hard();
        return $this->acknowledged($context, fn (AdminClient $c): PurgeResponse => $c->purge($request));
    }

    /**
     * A purge or clear: the engine's answer when it acknowledged it (the
     * library's rules — the schema for the endpoint, or Reflect mode's 202
     * `recorded`), else null with the reason in {@see lastFailure()} and the
     * log. An error status (401, 429, 5xx, a full queue) is not a purge that
     * happened either.
     *
     * @param string $context
     * @param callable(AdminClient): (PurgeResponse|ClearResponse) $call
     * @return array<string, mixed>|null
     */
    private function acknowledged(string $context, callable $call): ?array
    {
        $target = $this->ready();
        if ($target === null) {
            return null;
        }
        try {
            $response = $call(AdminClient::forInstance($target, $this->transport, $this->logger));
        } catch (\Throwable $e) {
            return $this->failed($target, $context, $e);
        }
        if ($this->config->isDebugEnabled()) {
            $this->logger->info('Trident ' . $context, ['instance' => $target->name, 'result' => $response->raw()]);
        }
        if ($response->isAcknowledged()) {
            $this->lastFailure = null;
            return $response->raw();
        }
        return $this->failed($target, $context, new TridentException((string) $response->failure));
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
     * @return bool Whether the store purges soft.
     */
    private function soft(): bool
    {
        return $this->config->getPurgeMode() === 'soft';
    }

    /**
     * @param string $host
     * @return void
     * @throws InvalidRequest
     */
    private function assertPinHost(string $host): void
    {
        $host = trim($host);
        if ($host === '' || $host === '*') {
            $this->lastFailure = 'a pin needs the host Trident sees';
            throw new InvalidRequest(
                'Trident matches a pin by the request\'s host, so a pin on "' . ($host === '' ? '' : '*')
                . '" would never be used. Pin on the store\'s host as Trident sees it (with its port), '
                . 'as the scope and zone lists show it.'
            );
        }
    }

    /**
     * @param string|null $pathPrefix
     * @return string
     */
    private function prefix(?string $pathPrefix): string
    {
        return $pathPrefix !== null && $pathPrefix !== '' ? $pathPrefix : '/';
    }
}
