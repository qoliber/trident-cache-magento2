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

use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qoliber\TridentCache\Model\TridentClient;

class CategorySaveObserver implements ObserverInterface
{
    public function __construct(
        private readonly TridentClient $tridentClient
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->tridentClient->isEnabled()) {
            return;
        }

        /** @var Category $category */
        $category = $observer->getEvent()->getCategory();

        if ($category === null) {
            return;
        }

        // Use native Magento cache tags (same format as X-Magento-Tags header)
        $tags = [
            'cat_c',
            'cat_c_' . $category->getId(),
            'cat_c_p_' . $category->getId(),
        ];

        // Add parent category tags
        $parentIds = $category->getParentIds();
        foreach ($parentIds as $parentId) {
            if ($parentId > 1) { // Skip root category
                $tags[] = 'cat_c_' . $parentId;
            }
        }

        $this->tridentClient->purgeTags($tags);
    }
}
