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
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class PurgeTag extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::purge';

    public function __construct(
        Context $context,
        private readonly TridentClient $tridentClient,
        private readonly JsonFactory $resultJsonFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $body = $this->getRequest()->getContent();
            $data = json_decode($body, true);

            $tag = $data['tag'] ?? '';

            if (empty($tag)) {
                return $resultJson->setData(['success' => false, 'message' => 'Tag is required']);
            }

            $result = $this->tridentClient->purgeTags([$tag]);

            if ($result !== null) {
                return $resultJson->setData(['success' => true, 'message' => 'Tag purged successfully']);
            }

            return $resultJson->setData(['success' => false, 'message' => 'Failed to purge tag']);
        } catch (\Exception $e) {
            $this->logger->error('Trident purge tag error: ' . $e->getMessage());
            return $resultJson->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
