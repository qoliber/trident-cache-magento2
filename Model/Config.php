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
use Magento\PageCache\Model\Config as PageCacheConfig;

class Config
{
    public const TRIDENT = 3;

    public const XML_TRIDENT_ENABLED = 'system/full_page_cache/trident/enabled';
    public const XML_TRIDENT_API_URL = 'system/full_page_cache/trident/api_url';
    public const XML_TRIDENT_API_TOKEN = 'system/full_page_cache/trident/api_token';
    public const XML_TRIDENT_SOFT_PURGE = 'system/full_page_cache/trident/soft_purge';
    public const XML_TRIDENT_DEBUG = 'system/full_page_cache/trident/debug';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PageCacheConfig $pageCacheConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isTridentEnabled(): bool
    {
        return $this->pageCacheConfig->getType() === self::TRIDENT;
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
}
