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

class PurgePattern extends Action implements HttpPostActionInterface
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
        $pattern = trim((string) $this->getRequest()->getParam('pattern', ''));

        if ($pattern === '') {
            $this->messageManager->addErrorMessage(__('Please specify a URL pattern to purge.'));
            return $resultRedirect->setPath('trident/cache/purge');
        }

        try {
            $result = $this->tridentClient->purgePattern($pattern);

            if ($result) {
                $done = $this->tridentClient->describePurge($result);
                $this->messageManager->addSuccessMessage($done === ''
                    ? __('Cache purged for pattern: %1', $pattern)
                    : __('Cache purged for pattern: %1 — %2.', $pattern, $done));
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to purge by pattern. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident purge pattern error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error purging by pattern: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/cache/purge');
    }
}
