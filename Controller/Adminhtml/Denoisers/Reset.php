<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Denoisers;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class Reset extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::denoisers';

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
        $kind = (string)$this->getRequest()->getParam('kind', '');

        try {
            if ($kind === 'path') {
                $result = $this->tridentClient->denoiserPathReset();

                if ($result !== null) {
                    $this->messageManager->addSuccessMessage(__('Path denoiser zones have been reset.'));
                } else {
                    $this->messageManager->addErrorMessage(__('Failed to reset path denoiser. Please check the logs.'));
                }
            } elseif ($kind === 'query') {
                $result = $this->tridentClient->denoiserQueryReset();

                if ($result !== null) {
                    $this->messageManager->addSuccessMessage(__('Query denoiser scopes have been reset.'));
                } else {
                    $this->messageManager->addErrorMessage(__('Failed to reset query denoiser. Please check the logs.'));
                }
            } else {
                $this->messageManager->addErrorMessage(__('Unknown denoiser kind: %1', $kind));
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident denoiser reset error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Error resetting denoiser: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('trident/denoisers/index');
    }
}
