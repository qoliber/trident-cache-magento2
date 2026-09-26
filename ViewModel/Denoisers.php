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
use Magento\Store\Model\StoreManagerInterface;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\TridentClient;

class Denoisers implements ArgumentInterface
{
    public function __construct(
        private readonly TridentClient $tridentClient,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The store's host as Trident sees it — the Host header of a storefront
     * request, so with a port unless it is the scheme's default — the default
     * for the pin forms. Trident matches a pin by that host, never by "*".
     *
     * @return string
     */
    public function getStoreHost(): string
    {
        try {
            $url = (string) $this->storeManager->getDefaultStoreView()?->getBaseUrl();
        } catch (\Throwable $e) {
            return '';
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $default = ['http' => 80, 'https' => 443][$scheme] ?? null;
        return $host !== '' && is_int($port) && $port !== $default ? $host . ':' . $port : $host;
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
