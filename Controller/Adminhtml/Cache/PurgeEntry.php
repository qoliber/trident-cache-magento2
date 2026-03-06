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

class PurgeEntry extends Action implements HttpPostActionInterface
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

            $url = $data['url'] ?? '';
            $host = $data['host'] ?? null;

            if (empty($url)) {
                return $resultJson->setData(['success' => false, 'message' => 'URL is required']);
            }

            $result = $this->tridentClient->purgeUrl($url, $host);

            if ($result !== null) {
                return $resultJson->setData(['success' => true, 'message' => 'Entry purged successfully']);
            }

            return $resultJson->setData(['success' => false, 'message' => 'Failed to purge entry']);
        } catch (\Exception $e) {
            $this->logger->error('Trident purge entry error: ' . $e->getMessage());
            return $resultJson->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
