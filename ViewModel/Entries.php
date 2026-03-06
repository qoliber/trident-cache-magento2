<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\TridentClient;

class Entries implements ArgumentInterface
{
    public function __construct(
        private readonly TridentClient $tridentClient,
        private readonly Config $config
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->tridentClient->isEnabled();
    }

    public function isTridentConfigured(): bool
    {
        return $this->config->isTridentEnabled();
    }

    /**
     * @param int $offset
     * @param int $limit
     * @param string|null $tagFilter
     * @param string $sort
     * @return array<string, mixed>|null
     */
    public function getEntries(int $offset = 0, int $limit = 50, ?string $tagFilter = null, string $sort = 'age'): ?array
    {
        return $this->tridentClient->getEntries($offset, $limit, $tagFilter, $sort);
    }

    public function getApiUrl(): string
    {
        return $this->config->getApiUrl();
    }

    /**
     * Parse a url_key like "GET:https:example.com:/products/123" into components
     *
     * @param string $urlKey
     * @return array{method: string, scheme: string, host: string, path: string}
     */
    public function parseUrlKey(string $urlKey): array
    {
        $parts = explode(':', $urlKey, 4);

        return [
            'method' => $parts[0] ?? 'GET',
            'scheme' => $parts[1] ?? 'https',
            'host' => $parts[2] ?? '',
            'path' => $parts[3] ?? '/',
        ];
    }

    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public function formatNumber(int|float $number): string
    {
        return number_format($number, 0, '.', ',');
    }
}
