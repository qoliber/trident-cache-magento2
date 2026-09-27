<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Launch;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class Start extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::launch';

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
        $options = [];

        $urls = (string)$this->getRequest()->getParam('urls', '');
        $urlList = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $urls) ?: [])));
        if (!empty($urlList)) {
            $options['urls'] = $urlList;
        }

        $maintenancePage = trim((string)$this->getRequest()->getParam('maintenance_page', ''));
        if ($maintenancePage !== '') {
            $options['maintenance_page'] = $maintenancePage;
        }

        if ((bool)$this->getRequest()->getParam('auto_complete', false)) {
            $options['auto_complete'] = true;
        }

        $bypassIps = (string)$this->getRequest()->getParam('bypass_ips', '');
        $bypassList = array_values(array_filter(array_map('trim', explode(',', $bypassIps))));
        if (!empty($bypassList)) {
            $options['bypass_ips'] = $bypassList;
        }

        try {
            $result = $this->tridentClient->launchStart($options);

            if ($result !== null) {
                $this->messageManager->addSuccessMessage(__('Launch mode started.'));
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to start launch mode. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident launch start error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error starting launch mode: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/launch/index');
    }
}
