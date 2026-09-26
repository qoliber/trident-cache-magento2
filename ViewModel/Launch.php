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

class Launch implements ArgumentInterface
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
    public function getStatus(): ?array
    {
        return $this->tridentClient->getLaunchStatus();
    }

    public function getApiUrl(): string
    {
        // X03: the instance this screen shows.
        return $this->tridentClient->target()?->apiUrl ?? $this->config->getApiUrl();
    }

    public function formatNumber(int|float $number): string
    {
        return number_format($number, 0, '.', ',');
    }

    public function formatPercentage(float $value): string
    {
        return number_format($value, 2) . '%';
    }
}
