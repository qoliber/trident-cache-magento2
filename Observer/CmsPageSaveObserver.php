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

use Magento\Cms\Model\Page;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qoliber\TridentCache\Model\TridentClient;

class CmsPageSaveObserver implements ObserverInterface
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

        /** @var Page $page */
        $page = $observer->getEvent()->getObject();

        if ($page === null) {
            return;
        }

        // Use native Magento cache tags (same format as X-Magento-Tags header)
        $tags = [
            'cms_p',
            'cms_p_' . $page->getId(),
        ];

        $this->tridentClient->purgeTags($tags);
    }
}
