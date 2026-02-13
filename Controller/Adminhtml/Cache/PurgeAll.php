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

class PurgeAll extends Action
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

        try {
            $result = $this->tridentClient->purgeAll();

            if ($result) {
                $this->messageManager->addSuccessMessage(
                    __('Trident cache has been purged successfully.')
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to purge Trident cache. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident purge error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error purging Trident cache: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/cache/purge');
    }
}
