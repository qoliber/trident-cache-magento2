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

class Enable extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::reflect';

    private const ALLOWED_LEVELS = ['full', 'selective', 'ttl_extension'];

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

        $level = (string) $this->getRequest()->getParam('level', 'full');
        $duration = trim((string) $this->getRequest()->getParam('duration', ''));
        $reason = trim((string) $this->getRequest()->getParam('reason', ''));

        if (!in_array($level, self::ALLOWED_LEVELS, true)) {
            $this->messageManager->addErrorMessage(__('Invalid reflect mode level.'));
            return $resultRedirect->setPath('trident/reflect/index');
        }

        try {
            $result = $this->tridentClient->reflectEnable(
                $level,
                $duration !== '' ? $duration : null,
                $reason !== '' ? $reason : null
            );

            if ($result !== null) {
                $this->messageManager->addSuccessMessage(
                    __('Reflect mode enabled (level: %1). The origin is now shielded.', $level)
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to enable reflect mode. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident reflect enable error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error enabling reflect mode: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/reflect/index');
    }
}
