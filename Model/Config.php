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
use Qoliber\Trident\Delivery\Instance;
use Qoliber\Trident\Delivery\Instances;

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
    public const XML_TRIDENT_INSTANCES = 'system/full_page_cache/trident/instances';

    /** The name of the single instance configured in the admin. */
    public const DEFAULT_INSTANCE = Instances::DEFAULT_NAME;

    /** @var array{0: list<Instance>, 1: list<string>}|null */
    private ?array $instances = null;

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

    /**
     * X03: every Trident edge this store invalidates.
     *
     * Configured in app/etc/env.php, which Magento layers over the values
     * saved in the admin — so env.php takes precedence, and anything an
     * instance leaves out (today: its token) is taken from the admin setting.
     * An `api_token` given here is plain text — env.php is already the file
     * that holds the store's secrets:
     *
     *     'system' => ['default' => ['system' => ['full_page_cache' => ['trident' => [
     *         'instances' => [
     *             'edge-1' => ['api_url' => 'http://10.0.0.11:9301'],
     *             'edge-2' => ['api_url' => 'http://10.0.0.12:9301', 'api_token' => '...'],
     *         ],
     *     ]]]]],
     *
     * After changing the list, run `bin/magento app:config:import` — as after
     * any change to the `system` section of env.php. Until then Magento
     * answers every storefront request with a 500 ("The configuration file
     * has changed").
     *
     * Without `instances`, the admin's single API URL and token are the one
     * instance, exactly as before. The list is parsed by qoliber/trident-php
     * ({@see Instances::parse()}), the same rules every Trident platform
     * integration uses: a name is 1-64 characters of `A-Z a-z 0-9 . _ -`
     * (it is stored with each pending purge, compared case-sensitively), and
     * `api_url` must be an http(s) URL. Entries that break a rule are skipped
     * and reported by {@see getInstanceErrors()} (and `trident:purge:status`);
     * if none is usable, the admin setting is used rather than nothing.
     *
     * @return list<Instance> Empty only when the admin API URL itself is not
     *         an http(s) URL; otherwise the first is the dashboard's default.
     */
    public function getInstances(): array
    {
        return $this->readInstances()[0];
    }

    /**
     * X03: why configured instances were skipped.
     *
     * @return list<string>
     */
    public function getInstanceErrors(): array
    {
        return $this->readInstances()[1];
    }

    /**
     * @return array{0: list<Instance>, 1: list<string>}
     */
    private function readInstances(): array
    {
        // Read once per process: the client asks several times per request.
        // A change needs app:config:import anyway, and cron runs a new process.
        return $this->instances ??= $this->parseInstances();
    }

    /**
     * @return array{0: list<Instance>, 1: list<string>}
     */
    private function parseInstances(): array
    {
        $token = $this->getApiToken();
        [$instances, $errors] = Instances::parse(
            $this->scopeConfig->getValue(self::XML_TRIDENT_INSTANCES),
            $this->getApiUrl(),
            $token
        );
        if ($instances === []) {
            // A list with no usable entry falls back to the admin setting,
            // whose own problem (if any) is reported alongside.
            [$instances, $fallbackErrors] = Instances::parse(null, $this->getApiUrl(), $token);
            $errors = array_values(array_unique([...$errors, ...$fallbackErrors]));
        }

        return [$instances, $errors];
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
