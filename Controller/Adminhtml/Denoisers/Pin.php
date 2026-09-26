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
use Qoliber\Trident\Exception\InvalidRequest;
use Qoliber\TridentCache\Model\TridentClient;

class Pin extends Action implements HttpPostActionInterface
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
                    $this->messageManager->addErrorMessage(__('Host and path prefix are required to pin a path zone.'));
                    return $resultRedirect->setPath('trident/denoisers/index');
                }

                $status = trim((string)$this->getRequest()->getParam('status', ''));
                $result = $this->tridentClient->denoiserPathPin($host, $pathPrefix, $status);

                if ($result !== null) {
                    $this->messageManager->addSuccessMessage(
                        __('Path zone pinned %3: %1 %2', $host, $pathPrefix, $status)
                    );
                } else {
                    $this->messageManager->addErrorMessage(__('Failed to pin path zone. Please check the logs.'));
                }
            } elseif ($kind === 'query') {
                $param = trim((string)$this->getRequest()->getParam('param', ''));
                $pathPrefix = trim((string)$this->getRequest()->getParam('path_prefix', ''));

                if ($param === '') {
                    $this->messageManager->addErrorMessage(__('A query parameter name is required to pin a query scope.'));
                    return $resultRedirect->setPath('trident/denoisers/index');
                }

                $class = trim((string)$this->getRequest()->getParam('class', ''));
                // The scope's own host, as Trident sees it: a pin is matched by
                // the request's host, never by "*".
                $host = trim((string)$this->getRequest()->getParam('host', ''));
                $result = $this->tridentClient->denoiserQueryPin($param, $class, $host, $pathPrefix);

                if ($result !== null) {
                    $this->messageManager->addSuccessMessage(
                        __('Query parameter pinned as %2: %1 on %3%4', $param, $class, $host, $pathPrefix)
                    );
                } else {
                    $this->messageManager->addErrorMessage(__('Failed to pin query parameter. Please check the logs.'));
                }
            } else {
                $this->messageManager->addErrorMessage(__('Unknown denoiser kind: %1', $kind));
            }
        } catch (InvalidRequest $e) {
            // Refused before it was sent: the form's input, not a failure.
            $this->messageManager->addErrorMessage(__('Not pinned: %1', $e->getMessage()));
        } catch (\Exception $e) {
            $this->logger->error('Trident denoiser pin error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Error pinning denoiser entry: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('trident/denoisers/index');
    }
}
