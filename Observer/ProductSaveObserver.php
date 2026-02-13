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

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qoliber\TridentCache\Model\PurgeStrategy;
use Qoliber\TridentCache\Model\TridentClient;

class ProductSaveObserver implements ObserverInterface
{
    public function __construct(
        private readonly TridentClient $tridentClient,
        private readonly PurgeStrategy $purgeStrategy
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->tridentClient->isEnabled()) {
            return;
        }

        /** @var Product $product */
        $product = $observer->getEvent()->getProduct();

        if ($product === null) {
            return;
        }

        $tags = ['cat_p_' . $product->getId()];

        // When listing attributes changed (price, name, etc.), also purge category pages
        // that contain this product
        $excludeTags = $this->purgeStrategy->getExcludeTags($product);

        if (empty($excludeTags)) {
            /** @var int[] $categoryIds */
            $categoryIds = $product->getCategoryIds();

            foreach ($categoryIds as $categoryId) {
                $tags[] = 'cat_c_' . $categoryId;
            }
        }

        $this->tridentClient->purgeTags($tags, $excludeTags);
    }
}
