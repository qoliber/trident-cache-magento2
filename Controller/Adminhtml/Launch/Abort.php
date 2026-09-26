<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Launch;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class Abort extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::launch';

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
        $reason = trim((string)$this->getRequest()->getParam('reason', ''));

        try {
            $result = $this->tridentClient->launchAbort($reason !== '' ? $reason : null);

            if ($result !== null) {
                $this->messageManager->addSuccessMessage(__('Launch mode aborted.'));
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to abort launch mode. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident launch abort error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error aborting launch mode: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/launch/index');
    }
}
