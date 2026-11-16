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
use Magento\Framework\App\Config\ValueInterface;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Qoliber\TridentCache\Model\PurgeStrategy;
use Qoliber\TridentCache\Model\TagBudget;

/**
 * The event fires from AbstractModel::afterSave(), inside the save
 * transaction. Tags are resolved now, while the entity still knows what
 * changed; the purge itself waits for the commit (see PurgeAfterCommit).
 */
class FlushCacheByTagsObserver implements ObserverInterface
{
    public function __construct(
        private readonly PurgeAfterCommit $purgeAfterCommit,
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
            // As resolved, never lowercased: Trident matches tags
            // case-sensitively and stores them exactly as X-Magento-Tags
            // carries them (ResponsePlugin passes that header through
            // untouched). A lowercased `FPC` or `CAT_P_42` purges nothing.
            // With the overflow tags: a page that carried more tags than
            // Trident stores dropped some of them (see TagBudget) and is
            // reached through its overflow tag instead.
            $this->purgeAfterCommit->purgeTags(TagBudget::withOverflow(array_values(array_unique($tags))));
        }

        // A config value's own identities are held until the configuration is
        // reloaded — see PurgeAfterCommit::purgeConfigTags().
        $identities = $this->configValueIdentities($object);
        if (!empty($identities)) {
            $this->purgeAfterCommit->purgeConfigTags(
                TagBudget::withOverflow(array_values(array_unique($identities)))
            );
        }
    }

    /**
     * A config value's own cache tags, which the resolver drops.
     *
     * `Cache\Tag\Strategy\Factory` returns exactly ONE strategy, and a
     * custom strategy registered for a type wins over the identifier one.
     * `Magento_Store` registers a custom strategy for
     * `App\Config\ValueInterface` that returns only the GraphQL
     * store-config tags, so the value's own `getIdentities()` never reaches
     * the purge — for any consumer of the resolver, Varnish included.
     *
     * That matters because some config values ARE page identities. Saving
     * the robots settings produces `robots_<storeId>`, which is exactly the
     * tag `X-Magento-Tags` puts on `/robots.txt`; without it the cached
     * robots.txt survives the change for its whole `max-age` (24 h on a
     * default install). Measured on 2.4.x: the purge arrives and matches
     * nothing.
     *
     * Scoped to config values on purpose. The other custom strategies —
     * customer, address, subscriber — also replace identities with a
     * resolver-cache tag, and widening this to them would start purging the
     * shared cache on every customer save.
     *
     * @param object $object
     * @return array<string>
     */
    private function configValueIdentities(object $object): array
    {
        if (!$object instanceof ValueInterface || !$object instanceof IdentityInterface) {
            return [];
        }

        return $object->getIdentities();
    }
}
