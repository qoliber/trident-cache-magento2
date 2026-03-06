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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    public const TRIDENT = 3;

    public const XML_CACHING_APPLICATION = 'system/full_page_cache/caching_application';
    public const XML_TRIDENT_ENABLED = 'system/full_page_cache/trident/enabled';
    public const XML_TRIDENT_API_URL = 'system/full_page_cache/trident/api_url';
    public const XML_TRIDENT_API_TOKEN = 'system/full_page_cache/trident/api_token';
    public const XML_TRIDENT_SOFT_PURGE = 'system/full_page_cache/trident/soft_purge';
    public const XML_TRIDENT_DEBUG = 'system/full_page_cache/trident/debug';
    public const XML_TRIDENT_TTL = 'system/full_page_cache/trident/ttl';
    public const XML_TRIDENT_GRACE_PERIOD = 'system/full_page_cache/trident/grace_period';
    public const XML_TRIDENT_TTL_STATIC = 'system/full_page_cache/trident/ttl_static';
    public const XML_TRIDENT_ESI_ENABLED = 'system/full_page_cache/trident/esi_enabled';
    public const XML_TRIDENT_ESI_MAX_DEPTH = 'system/full_page_cache/trident/esi_max_depth';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isTridentEnabled(): bool
    {
        return (int) $this->scopeConfig->getValue(self::XML_CACHING_APPLICATION) === self::TRIDENT;
    }

    public function getApiUrl(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_TRIDENT_API_URL) ?: 'http://trident:9301';
    }

    public function getApiToken(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_TRIDENT_API_TOKEN);

        return $value !== '' ? $this->encryptor->decrypt($value) : '';
    }

    public function isSoftPurgeEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_TRIDENT_SOFT_PURGE);
    }

    public function isDebugEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_TRIDENT_DEBUG);
    }

    public function getTtl(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_TRIDENT_TTL) ?: 86400);
    }

    public function getGracePeriod(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_TRIDENT_GRACE_PERIOD) ?: 86400);
    }

    public function getStaticTtl(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_TRIDENT_TTL_STATIC) ?: 2592000);
    }

    public function isEsiEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_TRIDENT_ESI_ENABLED);
    }

    public function getEsiMaxDepth(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_TRIDENT_ESI_MAX_DEPTH) ?: 3);
    }
}
