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
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class PurgeHost extends Action implements HttpPostActionInterface
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
        $host = trim((string)$this->getRequest()->getParam('host', ''));

        if (empty($host)) {
            $this->messageManager->addErrorMessage(__('Please specify a host to purge.'));
            return $resultRedirect->setPath('trident/cache/purge');
        }

        try {
            $result = $this->tridentClient->purgeHost($host);

            if ($result !== null) {
                $affected = $result['affected'] ?? $result['purged'] ?? null;

                if ($affected !== null) {
                    $this->messageManager->addSuccessMessage(
                        __('Host "%1" purged successfully: %2 entries affected.', $host, (int)$affected)
                    );
                } else {
                    $this->messageManager->addSuccessMessage(
                        __('Host "%1" purged successfully.', $host)
                    );
                }
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to purge host. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident purge host error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error purging host: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/cache/purge');
    }
}
