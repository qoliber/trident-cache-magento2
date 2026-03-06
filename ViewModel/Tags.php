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

class Tags implements ArgumentInterface
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
     * @param string|null $prefix
     * @param string $sort
     * @return array<string, mixed>|null
     */
    public function getTags(int $offset = 0, int $limit = 100, ?string $prefix = null, string $sort = 'count'): ?array
    {
        return $this->tridentClient->getTags($offset, $limit, $prefix, $sort);
    }

    public function getApiUrl(): string
    {
        return $this->config->getApiUrl();
    }

    public function formatNumber(int|float $number): string
    {
        return number_format($number, 0, '.', ',');
    }
}
