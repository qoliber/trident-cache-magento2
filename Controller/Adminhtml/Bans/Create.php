<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Bans;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class Create extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::bans';

    /** @var array<int, string> Trident BanType enum (single-target kinds exposed in the UI). */
    private const ALLOWED_TYPES = ['url', 'tag', 'pattern'];

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
        $pattern = trim((string)$this->getRequest()->getParam('pattern', ''));
        $type = (string)$this->getRequest()->getParam('type', 'url');

        if ($pattern === '') {
            $this->messageManager->addErrorMessage(__('Please specify a ban pattern.'));
            return $resultRedirect->setPath('trident/bans/index');
        }

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $this->messageManager->addErrorMessage(__('Invalid ban type. Allowed types: url, tag, pattern.'));
            return $resultRedirect->setPath('trident/bans/index');
        }

        try {
            $result = $this->tridentClient->createBan($pattern, $type);

            if ($result !== null) {
                $this->messageManager->addSuccessMessage(
                    __('Ban created successfully for pattern: %1', $pattern)
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to create ban. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident create ban error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error creating ban: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/bans/index');
    }
}
