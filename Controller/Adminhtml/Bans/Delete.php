<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Bans;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::bans';

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
        $id = trim((string)$this->getRequest()->getParam('id', ''));

        if ($id === '') {
            $this->messageManager->addErrorMessage(__('Please specify a ban to delete.'));
            return $resultRedirect->setPath('trident/bans/index');
        }

        try {
            $result = $this->tridentClient->deleteBan($id);

            if ($result !== null) {
                $this->messageManager->addSuccessMessage(
                    __('Ban deleted successfully: %1', $id)
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to delete ban. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident delete ban error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error deleting ban: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/bans/index');
    }
}
