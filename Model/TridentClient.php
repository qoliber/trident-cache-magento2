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
use Qoliber\Trident\Delivery\Acknowledgement;
use Qoliber\Trident\Delivery\Instance;
use Qoliber\Trident\Delivery\Transport;
use Qoliber\Trident\Exception\TridentException;
use Qoliber\TridentCache\Model\Admin\InstanceSelection;

/**
 * The admin API of the store's Trident instances, as the module's screens,
 * plugins and observers use it: decoded JSON arrays, or null when there is
 * no answer to show.
 *
 * Every request goes through qoliber/trident-php — {@see Api} (bearer token,
 * JSON, the admin limiter's 429 retry, error mapping) over the module's
 * {@see HttpTransport} — and every purge or clear answer is judged by the
 * library's {@see Acknowledgement}. What stays here is the module's API: the
 * endpoint each screen reads, and which instance a call goes to.
 *
 * X03: invalidations go to every instance; everything else (dashboard reads,
 * the warmer, launch, reflect, bans …) to one — the instance the admin chose
 * with the switcher on the Trident screens ({@see InstanceSelection}),
 * otherwise the first. A client bound with {@see forInstance()} is always
 * that one instance.
 */
class TridentClient
{
    /** X02: why the last purge was not acknowledged; null after a success. */
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
     * Whether an invalidation from this client must go to several instances.
     *
     * @return bool
     */
    private function fansOut(): bool
    {
        return $this->instance === null && count($this->instances()) > 1;
    }

    /**
     * X03: an invalidation on every instance. It counts as done only when
     * every instance answered: a purge that reached edge-1 but not edge-2
     * leaves edge-2 serving the old page, so it is reported as failed, with
     * the instances that failed and why in {@see lastFailure()}. Counts
     * (`purged`, `affected` …) are summed; per-instance answers are under
     * `instances`.
     *
     * @param callable(self): (array<string, mixed>|null) $call
     * @return array<string, mixed>|null
     */
    private function onEveryInstance(callable $call): ?array
    {
        $results = [];
        $failed = [];
        foreach ($this->instances() as $instance) {
            $client = $this->forInstance($instance);
            $result = $call($client);
            $results[$instance->name] = $result;
            if ($result === null) {
                $failed[] = $instance->name . ': ' . ($client->lastFailure() ?? 'no acknowledgement');
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
     * @return bool
     */
    public function isEnabled(): bool
    {
        // Require an API token: the admin API is Bearer-authenticated, so calling
        // it without one just fires empty-Bearer 401s. Gate them out cleanly and
        // surface a distinct, debug-independent warning once so the
        // misconfiguration is visible.
        $target = $this->config->isTridentEnabled() ? $this->target() : null;
        if ($target === null) {
            return false;
        }
        if ($target->apiToken === '') {
            if (!self::$tokenMissingLogged) {
                self::$tokenMissingLogged = true;
                $this->logger->warning(
                    'Trident FPC is enabled (engine 3) but no admin api_token is set — '
                    . 'purges and admin calls are disabled until a token is configured.'
                );
            }
            return false;
        }

        return true;
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
            'mode' => $this->mode(),
        ];
        if (!empty($excludeTags)) {
            $data['exclude_tags'] = array_values(array_unique($excludeTags));
        }
        return $this->acknowledged('/admin/purge/tags', $data, 'purge_tags');
    }

    /**
     * X02: whether Trident acknowledged a purge of `$tags`.
     *
     * @param array<string> $tags
     * @return bool
     */
    public function deliverTags(array $tags): bool
    {
        return $tags !== [] && $this->purgeTags($tags) !== null;
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
        return $this->acknowledged('/admin/cache/clear', ['confirm' => true], 'cache_clear');
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
     * X02: whether Trident acknowledged a full clear.
     *
     * @return bool
     */
    public function deliverAll(): bool
    {
        return $this->purgeAll() !== null;
    }

    /**
     * Get cache statistics
     *
     * @return array<string, mixed>|null
     */
    public function getStats(): ?array
    {
        return $this->apiGet('/admin/stats');
    }

    /**
     * Get cache rules
     *
     * @return array<string, mixed>|null
     */
    public function getRules(): ?array
    {
        return $this->apiGet('/admin/rules');
    }

    /**
     * Get Trident health status
     *
     * @return array<string, mixed>|null
     */
    public function getHealth(): ?array
    {
        return $this->apiGet('/admin/health');
    }

    /**
     * Trident 1.5.0 license / degraded-mode status: GET /admin/status.
     *
     * Returns ['status','version','license','mode'] where `mode` is 'licensed'
     * (caching active), 'degraded' (license check failed → pass-through, no
     * caching), or 'unknown'. Distinct from getHealth() (mere reachability).
     *
     * @return array<string, mixed>|null
     */
    public function getStatus(): ?array
    {
        return $this->apiGet('/admin/status');
    }

    /**
     * Trident 1.5.0 dry-run cacheability diagnostic: POST /admin/explain.
     *
     * Answers "will this request cache, and if not, why?" without mutating the
     * cache. Useful for debugging why a Magento page/URL is not being cached.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>|null
     */
    public function explain(string $method, string $url, array $headers = [], bool $detail = true): ?array
    {
        return $this->apiPost('/admin/explain', [
            'method' => $method,
            'url' => $url,
            // Cast so an empty header set serialises as {} (object), not [] —
            // Trident's ExplainRequest.headers is a map.
            'headers' => (object) $headers,
            'detail' => $detail,
        ]);
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
        return $this->apiGet('/admin/cache/entries', [
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
        return $this->apiGet('/admin/cache/tags', [
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
        return $this->apiGet('/admin/stats/top', ['limit' => $limit, 'sort' => $sort]);
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
        $data = ['url' => $url, 'mode' => $this->mode()];
        if ($host !== null && $host !== '') {
            $data['host'] = $host;
        }
        return $this->apiPost('/admin/purge/url', $data);
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
        return $this->apiPost('/admin/purge/urls', ['pattern' => $pattern, 'mode' => $this->mode()]);
    }

    // =========================================================================
    // Cache Warmer
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getWarmerStatus(): ?array
    {
        return $this->apiGet('/admin/warmer/status');
    }

    /**
     * @param array<int, string> $urls Optional explicit URL list; empty = warm configured sources.
     * @return array<string, mixed>|null
     */
    public function warmerRun(array $urls = []): ?array
    {
        $data = [];
        if (!empty($urls)) {
            $data['urls'] = array_values($urls);
        }

        return $this->apiPost('/admin/warmer/run', $data);
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, mixed>|null
     */
    public function warmerQueue(array $urls): ?array
    {
        return $this->apiPost('/admin/warmer/queue', ['urls' => array_values($urls)]);
    }

    /** @return array<string, mixed>|null */
    public function warmerCancel(): ?array
    {
        return $this->apiPost('/admin/warmer/cancel');
    }

    // =========================================================================
    // Cache Coverage
    // =========================================================================

    /**
     * Batch cache-membership check: per-URL cached/not + aggregate percentage.
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
        $payload = [
            'urls' => array_values($urls),
            'scheme' => $scheme,
            'method' => $method,
        ];

        if ($host !== null && $host !== '') {
            $payload['host'] = $host;
        }

        return $this->apiPost('/admin/cache/coverage', $payload);
    }

    // =========================================================================
    // Launch Mode
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getLaunchStatus(): ?array
    {
        return $this->apiGet('/admin/launch/status');
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function launchStart(array $options = []): ?array
    {
        return $this->apiPost('/admin/launch/start', $options);
    }

    /** @return array<string, mixed>|null */
    public function launchComplete(): ?array
    {
        return $this->apiPost('/admin/launch/complete');
    }

    /** @return array<string, mixed>|null */
    public function launchAbort(?string $reason = null): ?array
    {
        $data = [];
        if ($reason !== null && $reason !== '') {
            $data['reason'] = $reason;
        }

        return $this->apiPost('/admin/launch/abort', $data);
    }

    // =========================================================================
    // Reflect Mode
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getReflectStatus(): ?array
    {
        return $this->apiGet('/admin/reflect/status');
    }

    /** @return array<string, mixed>|null */
    public function getReflectQueue(): ?array
    {
        return $this->apiGet('/admin/reflect/queue');
    }

    /**
     * @param string $level full|selective|ttl_extension
     * @return array<string, mixed>|null
     */
    public function reflectEnable(string $level = 'full', ?string $duration = null, ?string $reason = null): ?array
    {
        $data = ['level' => $level];
        if ($duration !== null && $duration !== '') {
            $data['duration'] = $duration;
        }
        if ($reason !== null && $reason !== '') {
            $data['reason'] = $reason;
        }

        return $this->apiPost('/admin/reflect/enable', $data);
    }

    /**
     * @param string $mode replay|hard
     * @return array<string, mixed>|null
     */
    public function reflectDisable(string $mode = 'replay'): ?array
    {
        return $this->apiPost('/admin/reflect/disable', ['mode' => $mode]);
    }

    // =========================================================================
    // Denoisers
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getDenoiserReport(): ?array
    {
        return $this->apiGet('/admin/denoisers/report');
    }

    /** @return array<string, mixed>|null */
    public function getDenoiserQueryScopes(): ?array
    {
        return $this->apiGet('/admin/denoisers/query/scopes');
    }

    /** @return array<string, mixed>|null */
    public function getDenoiserPathZones(): ?array
    {
        return $this->apiGet('/admin/denoisers/path/zones');
    }

    /** @return array<string, mixed>|null */
    public function denoiserQueryPin(string $param, ?string $pathPrefix = null): ?array
    {
        $data = ['param' => $param];
        if ($pathPrefix !== null && $pathPrefix !== '') {
            $data['path_prefix'] = $pathPrefix;
        }

        return $this->apiPost('/admin/denoisers/query/pin', $data);
    }

    /** @return array<string, mixed>|null */
    public function denoiserQueryUnpin(string $param, ?string $pathPrefix = null): ?array
    {
        $data = ['param' => $param];
        if ($pathPrefix !== null && $pathPrefix !== '') {
            $data['path_prefix'] = $pathPrefix;
        }

        return $this->apiPost('/admin/denoisers/query/unpin', $data);
    }

    /** @return array<string, mixed>|null */
    public function denoiserQueryReset(): ?array
    {
        return $this->apiPost('/admin/denoisers/query/reset');
    }

    /** @return array<string, mixed>|null */
    public function denoiserPathPin(string $host, string $pathPrefix): ?array
    {
        return $this->apiPost('/admin/denoisers/path/pin', ['host' => $host, 'path_prefix' => $pathPrefix]);
    }

    /** @return array<string, mixed>|null */
    public function denoiserPathUnpin(string $host, string $pathPrefix): ?array
    {
        return $this->apiPost('/admin/denoisers/path/unpin', ['host' => $host, 'path_prefix' => $pathPrefix]);
    }

    /** @return array<string, mixed>|null */
    public function denoiserPathReset(): ?array
    {
        return $this->apiPost('/admin/denoisers/path/reset');
    }

    /** @return array<string, mixed>|null */
    public function getDenoiserWafExport(): ?array
    {
        return $this->apiGet('/admin/denoisers/export/waf');
    }

    // =========================================================================
    // Bans (soft purge)
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getBans(): ?array
    {
        return $this->apiGet('/admin/bans');
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

        // The /admin/bans request body field is `type` (serde rename of ban_type).
        return $this->apiPost('/admin/bans', ['pattern' => $pattern, 'type' => $type]);
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
        return $this->apiGet('/admin/backends');
    }

    /** @return array<string, mixed>|null */
    public function getBackendDetail(string $name): ?array
    {
        return $this->apiGet('/admin/backends/detail', ['name' => $name]);
    }

    /** @return array<string, mixed>|null */
    public function getConnections(): ?array
    {
        return $this->apiGet('/admin/connections');
    }

    /** @return array<string, mixed>|null */
    public function drainBackend(string $name): ?array
    {
        return $this->apiPost('/admin/backends/drain', ['name' => $name]);
    }

    /** @return array<string, mixed>|null */
    public function restoreBackend(string $name): ?array
    {
        return $this->apiPost('/admin/backends/restore', ['name' => $name]);
    }

    // =========================================================================
    // DNS discovery
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getDiscovery(): ?array
    {
        return $this->apiGet('/admin/discovery');
    }

    /** @return array<string, mixed>|null */
    public function getDiscoveryDetail(string $name): ?array
    {
        return $this->apiGet('/admin/discovery/detail', ['name' => $name]);
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
        return $this->apiGet('/admin/stats/latency');
    }

    /** @return array<string, mixed>|null */
    public function getErrorStats(int $limit = 20): ?array
    {
        return $this->apiGet('/admin/stats/errors', ['limit' => $limit]);
    }

    /** @return array<string, mixed>|null */
    public function getProtectionStats(): ?array
    {
        return $this->apiGet('/admin/stats/protection');
    }

    /** @return array<string, mixed>|null */
    public function getMemory(): ?array
    {
        return $this->apiGet('/admin/memory');
    }

    /** @return array<string, mixed>|null */
    public function getRefreshQueue(): ?array
    {
        return $this->apiGet('/admin/refresh/queue');
    }

    // =========================================================================
    // Extended purge (host / vary)
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function purgeHost(string $host): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeHost($host));
        }

        return $this->apiPost('/admin/purge/host', ['host' => $host, 'mode' => $this->mode()]);
    }

    /** @return array<string, mixed>|null */
    public function purgeVary(string $header, string $value): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeVary($header, $value));
        }

        return $this->apiPost('/admin/purge/vary', [
            'header' => $header,
            'value' => $value,
            'mode' => $this->mode(),
        ]);
    }

    /**
     * Trident 1.5.0 tag-pattern purge: POST /admin/purge/tag/pattern.
     *
     * Purge every entry whose tag matches a wildcard (default) or regex pattern
     * — e.g. `catalog_product_*` or `cat_c_*`. Distinct from purgePattern(),
     * which matches URL globs via /admin/purge/urls.
     *
     * @return array<string, mixed>|null
     */
    public function purgeTagPattern(string $pattern, bool $regex = false): ?array
    {
        if ($this->fansOut()) {
            return $this->onEveryInstance(fn (self $client): ?array => $client->purgeTagPattern($pattern, $regex));
        }

        return $this->apiPost('/admin/purge/tag/pattern', [
            'pattern' => $pattern,
            // The engine's field (PurgeTagPatternRequest.pattern_type).
            'pattern_type' => $regex ? 'regex' : 'wildcard',
            'mode' => $this->mode(),
        ]);
    }

    // =========================================================================
    // Requests
    // =========================================================================

    /**
     * @param string $path
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>|null
     */
    private function apiGet(string $path, array $query = []): ?array
    {
        return $this->call('GET', $path, $query);
    }

    /**
     * @param string $path
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function apiPost(string $path, array $data = []): ?array
    {
        return $this->call('POST', $path, [], $data);
    }

    /**
     * One admin call through the library's {@see Api}: the decoded body of a
     * 2xx answer, or null — with the reason in {@see lastFailure()} and the
     * log — for an error status or no answer at all. (An error body is never
     * handed to a caller as if it were the data it asked for.)
     *
     * @param string $method
     * @param string $path
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null
     */
    private function call(string $method, string $path, array $query = [], ?array $body = null): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $target = $this->target();
        if ($target === null) {
            return null;
        }
        try {
            $data = (new Api($target, $this->transport))->call($method, $path, $query, $body)['data'];
        } catch (TridentException $e) {
            $reason = $e instanceof ApiError ? $e->reason() : $e->getMessage();
            $this->lastFailure = sprintf('%s %s: %s', $method, $path, $reason);
            $this->logger->error('Trident admin request failed', [
                'instance' => $target->name,
                'request' => $method . ' ' . $path,
                'reason' => $reason,
            ]);
            return null;
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
     * A purge or clear: the decoded acknowledgement, or null when Trident did
     * not acknowledge it — judged by {@see Acknowledgement}: HTTP 200 with the
     * engine's schema for the endpoint, or (a purge) Reflect mode's 202
     * `recorded`. Anything else — 401 (token), 429 (admin limiter), 5xx or a
     * full queue, a proxy's HTML error page, an error object sent with a
     * 200 — is not a purge that happened, and is logged as the reason it did
     * not.
     *
     * @param string $path
     * @param array<string, mixed> $data
     * @param string $context
     * @return array<string, mixed>|null
     */
    private function acknowledged(string $path, array $data, string $context): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $target = $this->target();
        if ($target === null) {
            return null;
        }
        $response = $this->transport->request(
            'POST',
            $target->apiUrl . $path,
            Api::headers($target),
            (string) json_encode($data)
        );
        if ($response['status'] === 0) {
            $error = (string) ($response['error'] ?? '');
            $failure = 'no response' . ($error !== '' ? ' — ' . $error : '');
        } elseif ($context === 'cache_clear') {
            $failure = Acknowledgement::clearFailure($response['status'], $response['body']);
        } else {
            $failure = Acknowledgement::purgeFailure($response['status'], $response['body']);
        }
        $decoded = json_decode($response['body'], true);

        if ($this->config->isDebugEnabled()) {
            $this->logger->info('Trident ' . $context, [
                'instance' => $target->name,
                'data' => $data,
                'status' => $response['status'],
                'result' => $decoded,
            ]);
        }
        if ($failure === null) {
            $this->lastFailure = null;
            return is_array($decoded) ? $decoded : [];
        }
        $this->lastFailure = $context . ': ' . $failure;
        $this->logger->error('Trident did not acknowledge the request', [
            'instance' => $target->name,
            'context' => $context,
            'error' => $failure,
        ]);
        return null;
    }

    /**
     * @return string soft|hard
     */
    private function mode(): string
    {
        return $this->config->isSoftPurgeEnabled() ? 'soft' : 'hard';
    }
}
