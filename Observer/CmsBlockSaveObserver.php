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

use Magento\Cms\Model\Block;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qoliber\TridentCache\Model\TridentClient;

class CmsBlockSaveObserver implements ObserverInterface
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

        /** @var Block $block */
        $block = $observer->getEvent()->getObject();

        if ($block === null) {
            return;
        }

        // Use native Magento cache tags (same format as X-Magento-Tags header)
        $tags = [
            'cms_b',
            'cms_b_' . $block->getIdentifier(),
        ];

        $this->tridentClient->purgeTags($tags);
    }
}
