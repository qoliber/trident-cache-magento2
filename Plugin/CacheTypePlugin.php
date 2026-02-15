<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Plugin;

use Magento\PageCache\Model\Cache\Type as PageCacheType;
use Qoliber\TridentCache\Model\TridentClient;
use Zend_Cache;

/**
 * Intercepts all PageCache Type::clean() calls — both full flushes and tag-based cleans.
 *
 * This covers programmatic Type::clean(tags) calls that bypass the clean_cache_by_tags event
 * (e.g. direct cache invalidation from custom modules, third-party extensions).
 *
 * For entity saves, FlushCacheByTagsObserver also fires (via clean_cache_by_tags event),
 * resulting in a duplicate purge — this is harmless (idempotent, local HTTP call).
 */
class CacheTypePlugin
{
    /** @var string Magento-internal FPC tag, not stored by Trident */
    private const FPC_TAG = 'FPC';

    public function __construct(
        private readonly TridentClient $tridentClient
    ) {
    }

    /**
     * @param \Magento\PageCache\Model\Cache\Type $subject
     * @param bool $result
     * @param string $mode
     * @param array<string> $tags
     * @return bool
     */
    public function afterClean(PageCacheType $subject, bool $result, string $mode = Zend_Cache::CLEANING_MODE_ALL, array $tags = []): bool
    {
        if (!$this->tridentClient->isEnabled()) {
            return $result;
        }

        if ($mode === Zend_Cache::CLEANING_MODE_ALL) {
            $this->tridentClient->purgeAll();
        } elseif (!empty($tags)) {
            $tags = array_values(array_diff($tags, [self::FPC_TAG]));

            if (!empty($tags)) {
                $this->tridentClient->purgeTags($tags);
            }
        }

        return $result;
    }
}
