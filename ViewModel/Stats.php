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

class Stats implements ArgumentInterface
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
     * @return array<string, mixed>|null
     */
    public function getStats(): ?array
    {
        return $this->tridentClient->getStats();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRules(): ?array
    {
        return $this->tridentClient->getRules();
    }

    public function getApiUrl(): string
    {
        return $this->config->getApiUrl();
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

    public function isSoftPurgeEnabled(): bool
    {
        return $this->config->isSoftPurgeEnabled();
    }

    public function formatPercentage(float $value): string
    {
        return number_format($value, 2) . '%';
    }
}
