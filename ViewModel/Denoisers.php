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

class Denoisers implements ArgumentInterface
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
    public function getReport(): ?array
    {
        return $this->tridentClient->getDenoiserReport();
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getQueryScopes(): ?array
    {
        return $this->tridentClient->getDenoiserQueryScopes();
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getPathZones(): ?array
    {
        return $this->tridentClient->getDenoiserPathZones();
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
}
