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

use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class TridentClient
{
    public function __construct(
        private readonly Curl $curl,
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isTridentEnabled() && !empty($this->config->getApiUrl());
    }

    /**
     * Purge cache by tags (soft or hard purge based on config)
     *
     * @param array<string> $tags
     * @return array<string, mixed>|null
     */
    /**
     * @param array<string> $tags
     * @param array<string> $excludeTags
     * @return array<string, mixed>|null
     */
    public function purgeTags(array $tags, array $excludeTags = []): ?array
    {
        if (!$this->isEnabled() || empty($tags)) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
                'Content-Type' => 'application/json',
            ]);

            $data = [
                'tags' => array_values(array_unique($tags)),
                'mode' => $this->config->isSoftPurgeEnabled() ? 'soft' : 'hard',
            ];

            if (!empty($excludeTags)) {
                $data['exclude_tags'] = array_values(array_unique($excludeTags));
            }

            $payload = json_encode($data);

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->post($apiUrl . '/admin/purge/tags', $payload);

            $response = $this->curl->getBody();
            $result = json_decode($response, true);

            if ($this->config->isDebugEnabled()) {
                $this->logger->info('Trident cache purge', [
                    'tags' => $tags,
                    'exclude_tags' => $excludeTags,
                    'mode' => $this->config->isSoftPurgeEnabled() ? 'soft' : 'hard',
                    'result' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Trident cache purge failed', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Purge all cache entries
     *
     * @return array<string, mixed>|null
     */
    public function purgeAll(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
                'Content-Type' => 'application/json',
            ]);

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->post($apiUrl . '/admin/cache/clear', json_encode(['confirm' => true]));

            $response = $this->curl->getBody();
            $result = json_decode($response, true);

            if ($this->config->isDebugEnabled()) {
                $this->logger->info('Trident cache cleared', ['result' => $result]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Trident cache clear failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get cache statistics
     *
     * @return array<string, mixed>|null
     */
    public function getStats(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/stats');

            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->logger->error('Trident get stats failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get cache rules
     *
     * @return array<string, mixed>|null
     */
    public function getRules(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/rules');

            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->logger->error('Trident get rules failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get Trident health status
     *
     * @return array<string, mixed>|null
     */
    public function getHealth(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/health');

            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->logger->error('Trident health check failed', ['error' => $e->getMessage()]);
            return null;
        }
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
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $params = ['offset' => $offset, 'limit' => $limit, 'sort' => $sort];
            if ($tag !== null && $tag !== '') {
                $params['tag'] = $tag;
            }

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/cache/entries?' . http_build_query($params));

            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->logger->error('Trident get entries failed', ['error' => $e->getMessage()]);
            return null;
        }
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
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $params = ['offset' => $offset, 'limit' => $limit, 'sort' => $sort];
            if ($prefix !== null && $prefix !== '') {
                $params['prefix'] = $prefix;
            }

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/cache/tags?' . http_build_query($params));

            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->logger->error('Trident get tags failed', ['error' => $e->getMessage()]);
            return null;
        }
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
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $params = ['limit' => $limit, 'sort' => $sort];

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/stats/top?' . http_build_query($params));

            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->logger->error('Trident get top urls failed', ['error' => $e->getMessage()]);
            return null;
        }
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
        if (!$this->isEnabled() || empty($url)) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
                'Content-Type' => 'application/json',
            ]);

            $data = [
                'url' => $url,
                'mode' => $this->config->isSoftPurgeEnabled() ? 'soft' : 'hard',
            ];

            if ($host !== null && $host !== '') {
                $data['host'] = $host;
            }

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->post($apiUrl . '/admin/purge/url', json_encode($data));

            $response = $this->curl->getBody();
            $result = json_decode($response, true);

            if ($this->config->isDebugEnabled()) {
                $this->logger->info('Trident cache purge url', [
                    'url' => $url,
                    'host' => $host,
                    'result' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Trident cache purge url failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Purge cache by URL pattern
     *
     * @param string $pattern
     * @return array<string, mixed>|null
     */
    public function purgePattern(string $pattern): ?array
    {
        if (!$this->isEnabled() || empty($pattern)) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
                'Content-Type' => 'application/json',
            ]);

            $payload = json_encode([
                'pattern' => $pattern,
                'mode' => $this->config->isSoftPurgeEnabled() ? 'soft' : 'hard',
            ]);

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->post($apiUrl . '/admin/purge/urls', $payload);

            $response = $this->curl->getBody();
            $result = json_decode($response, true);

            if ($this->config->isDebugEnabled()) {
                $this->logger->info('Trident cache purge by pattern', [
                    'pattern' => $pattern,
                    'result' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Trident cache purge by pattern failed', [
                'pattern' => $pattern,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
