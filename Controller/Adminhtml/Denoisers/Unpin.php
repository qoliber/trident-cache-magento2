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

class Unpin extends Action implements HttpPostActionInterface
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
                $host = trim((string)$this->getRequest()->getParam('host', ''));
                $pathPrefix = trim((string)$this->getRequest()->getParam('path_prefix', ''));

                if ($host === '' || $pathPrefix === '') {
                    $this->messageManager->addErrorMessage(__('Host and path prefix are required to unpin a path zone.'));
                    return $resultRedirect->setPath('trident/denoisers/index');
                }

                $result = $this->tridentClient->denoiserPathUnpin($host, $pathPrefix);

                if ($result !== null) {
                    $this->messageManager->addSuccessMessage(
                        __('Path zone unpinned: %1 %2', $host, $pathPrefix)
                    );
                } else {
                    $this->messageManager->addErrorMessage(__('Failed to unpin path zone. Please check the logs.'));
                }
            } elseif ($kind === 'query') {
                $param = trim((string)$this->getRequest()->getParam('param', ''));
                $pathPrefix = trim((string)$this->getRequest()->getParam('path_prefix', ''));

                if ($param === '') {
                    $this->messageManager->addErrorMessage(__('A query parameter name is required to unpin a query scope.'));
                    return $resultRedirect->setPath('trident/denoisers/index');
                }

                // `*` stays accepted here: it removes the inert "*" scopes that
                // pins created before they were made on the real host.
                $host = trim((string)$this->getRequest()->getParam('host', '*'));
                $result = $this->tridentClient->denoiserQueryUnpin(
                    $param,
                    $pathPrefix !== '' ? $pathPrefix : null,
                    $host !== '' ? $host : '*'
                );

                if ($result !== null) {
                    $this->messageManager->addSuccessMessage(
                        __('Query parameter unpinned: %1', $param)
                    );
                } else {
                    $this->messageManager->addErrorMessage(__('Failed to unpin query parameter. Please check the logs.'));
                }
            } else {
                $this->messageManager->addErrorMessage(__('Unknown denoiser kind: %1', $kind));
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident denoiser unpin error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Error unpinning denoiser entry: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('trident/denoisers/index');
    }
}
