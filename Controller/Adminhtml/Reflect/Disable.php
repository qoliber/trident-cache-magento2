<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Reflect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class Disable extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::reflect';

    private const ALLOWED_MODES = ['replay', 'hard'];

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

        $mode = (string) $this->getRequest()->getParam('mode', 'replay');

        if (!in_array($mode, self::ALLOWED_MODES, true)) {
            $mode = 'replay';
        }

        try {
            $result = $this->tridentClient->reflectDisable($mode);

            if ($result !== null) {
                if ($mode === 'hard') {
                    $this->messageManager->addSuccessMessage(
                        __('Reflect mode disabled. Deferred targets were soft-purged (marked stale for lazy revalidation).')
                    );
                } else {
                    $this->messageManager->addSuccessMessage(
                        __('Reflect mode disabled. Queued purges are being replayed.')
                    );
                }
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to disable reflect mode. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident reflect disable error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error disabling reflect mode: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/reflect/index');
    }
}
