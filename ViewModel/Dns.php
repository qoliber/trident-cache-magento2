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

class Dns implements ArgumentInterface
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
    public function getDiscovery(): ?array
    {
        return $this->tridentClient->getDiscovery();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBackends(): array
    {
        $discovery = $this->getDiscovery();

        if (!is_array($discovery) || empty($discovery['backends']) || !is_array($discovery['backends'])) {
            return [];
        }

        return array_values(array_filter($discovery['backends'], 'is_array'));
    }

    public function getApiUrl(): string
    {
        // X03: the instance this screen shows.
        return $this->tridentClient->target()?->apiUrl ?? $this->config->getApiUrl();
    }
}
