<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Cache;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class PurgeTags extends Action
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::purge';

    public function __construct(
        Context $context,
        private readonly TridentClient $tridentClient,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $tags = $this->getRequest()->getParam('tags', '');

        if (empty($tags)) {
            $this->messageManager->addErrorMessage(__('Please specify cache tags to purge.'));
            return $resultRedirect->setPath('trident/cache/purge');
        }

        $tagsArray = array_filter(array_map('trim', explode(',', $tags)));

        if (empty($tagsArray)) {
            $this->messageManager->addErrorMessage(__('Invalid cache tags format.'));
            return $resultRedirect->setPath('trident/cache/purge');
        }

        try {
            $result = $this->tridentClient->purgeTags($tagsArray);

            if ($result) {
                $this->messageManager->addSuccessMessage(
                    __('Cache tags purged successfully: %1', implode(', ', $tagsArray))
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to purge cache tags. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident purge tags error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error purging cache tags: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/cache/purge');
    }
}
