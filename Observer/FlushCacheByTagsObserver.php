<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Observer;

use Magento\Framework\App\Cache\Tag\Resolver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\PurgeStrategy;
use Qoliber\TridentCache\Model\TridentClient;

class FlushCacheByTagsObserver implements ObserverInterface
{
    public function __construct(
        private readonly TridentClient $tridentClient,
        private readonly Config $config,
        private readonly Resolver $tagResolver,
        private readonly PurgeStrategy $purgeStrategy
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isTridentEnabled()) {
            return;
        }

        $object = $observer->getEvent()->getObject();
        if (!is_object($object)) {
            return;
        }

        $tags = $this->tagResolver->getTags($object);

        if (!empty($tags)) {
            $tags = $this->purgeStrategy->filterTags($object, $tags);
            $normalizedTags = array_unique(array_map('strtolower', $tags));
            $this->tridentClient->purgeTags($normalizedTags);
        }
    }
}
