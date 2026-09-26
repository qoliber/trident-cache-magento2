<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Backends;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class Restore extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::backends';

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
        $name = (string)$this->getRequest()->getParam('name', '');

        if ($name === '') {
            $this->messageManager->addErrorMessage(__('Please specify a backend to restore.'));
            return $resultRedirect->setPath('trident/backends/index');
        }

        try {
            $result = $this->tridentClient->restoreBackend($name);

            if ($result !== null) {
                $this->messageManager->addSuccessMessage(
                    __('Backend "%1" has been restored to the pool.', $name)
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to restore backend "%1". Please check the logs.', $name)
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident restore backend error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error restoring backend: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/backends/index');
    }
}
