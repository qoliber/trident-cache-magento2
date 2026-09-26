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

class Backends implements ArgumentInterface
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

    public function getApiUrl(): string
    {
        // X03: the instance this screen shows.
        return $this->tridentClient->target()?->apiUrl ?? $this->config->getApiUrl();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBackends(): ?array
    {
        return $this->tridentClient->getBackends();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConnections(): ?array
    {
        return $this->tridentClient->getConnections();
    }

    /**
     * @param string $name
     * @return array<string, mixed>|null
     */
    public function getDetail(string $name): ?array
    {
        if ($name === '') {
            return null;
        }

        return $this->tridentClient->getBackendDetail($name);
    }

    public function formatNumber(int|float $number): string
    {
        return number_format($number, 0, '.', ',');
    }

    public function formatMs(int|float $milliseconds): string
    {
        return number_format((float)$milliseconds, 2) . ' ms';
    }
}
