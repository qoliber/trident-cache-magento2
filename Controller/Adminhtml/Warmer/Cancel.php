<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Warmer;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class Cancel extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::warmer';

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
            $result = $this->tridentClient->warmerCancel();

            if ($result !== null) {
                $this->messageManager->addSuccessMessage(
                    __('Cache warmer run cancelled.')
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to cancel the cache warmer. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident warmer cancel error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error cancelling cache warmer: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/warmer/index');
    }
}
