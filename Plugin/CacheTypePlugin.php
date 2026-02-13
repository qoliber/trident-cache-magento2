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
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;
use Zend_Cache;

class CacheTypePlugin
{
    public function __construct(
        private readonly TridentClient $tridentClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * After full page cache is cleaned, also purge Trident
     *
     * @param PageCacheType $subject
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

        $this->logger->info('Trident CacheTypePlugin: afterClean called', ['mode' => $mode, 'tags' => $tags]);

        if ($mode === Zend_Cache::CLEANING_MODE_ALL) {
            $this->tridentClient->purgeAll();
        } elseif (!empty($tags)) {
            $this->tridentClient->purgeTags($tags);
        }

        return $result;
    }
}
