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

class PurgeVary extends Action implements HttpPostActionInterface
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
        $header = trim((string)$this->getRequest()->getParam('header', ''));
        $value = trim((string)$this->getRequest()->getParam('value', ''));

        if (empty($header) || empty($value)) {
            $this->messageManager->addErrorMessage(__('Please specify both a Vary header and value to purge.'));
            return $resultRedirect->setPath('trident/cache/purge');
        }

        try {
            $result = $this->tridentClient->purgeVary($header, $value);

            if ($result !== null) {
                $affected = $result['affected'] ?? $result['purged'] ?? null;

                if ($affected !== null) {
                    $this->messageManager->addSuccessMessage(
                        __('Vary "%1: %2" purged successfully: %3 entries affected.', $header, $value, (int)$affected)
                    );
                } else {
                    $this->messageManager->addSuccessMessage(
                        __('Vary "%1: %2" purged successfully.', $header, $value)
                    );
                }
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to purge by Vary. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident purge vary error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error purging by Vary: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/cache/purge');
    }
}
