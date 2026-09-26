<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Dns;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class Refresh extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::dns';

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
            // /admin/discovery/refresh refreshes a single target by name, so
            // re-resolve every discovered backend in turn.
            $discovery = $this->tridentClient->getDiscovery();
            $backends = (is_array($discovery) && isset($discovery['backends']) && is_array($discovery['backends']))
                ? $discovery['backends']
                : [];

            if (empty($backends)) {
                $this->messageManager->addErrorMessage(
                    __('No discovered backends are available to refresh.')
                );
                return $resultRedirect->setPath('trident/dns/index');
            }

            $refreshed = 0;
            $failed = 0;
            foreach ($backends as $backend) {
                $name = is_array($backend) ? (string)($backend['backend_name'] ?? '') : '';
                if ($name === '') {
                    continue;
                }
                $result = $this->tridentClient->refreshDiscovery($name);
                if ($result !== null && ($result['success'] ?? true) !== false) {
                    $refreshed++;
                } else {
                    $failed++;
                }
            }

            if ($failed === 0) {
                $this->messageManager->addSuccessMessage(
                    __('DNS discovery refreshed for %1 target(s).', $refreshed)
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __('Refreshed %1 target(s), %2 failed. Please check the logs.', $refreshed, $failed)
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident DNS discovery refresh error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error refreshing DNS discovery: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/dns/index');
    }
}
