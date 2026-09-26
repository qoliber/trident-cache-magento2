<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Events;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\EventPoller;
use Qoliber\TridentCache\Model\TridentClient;

class Poll extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::events';

    /** @var array<int, string> */
    private const STREAMS = ['requests', 'cache', 'backends', 'errors'];

    /** Seconds one poll listens. */
    private const POLL_WINDOW = 2.0;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param EventPoller $poller
     * @param TridentClient $tridentClient
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly EventPoller $poller,
        private readonly TridentClient $tridentClient,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * @return Json
     */
    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        if (!$this->config->isTridentEnabled()) {
            return $resultJson->setData(['events' => [], 'error' => 'Trident is not configured.']);
        }

        $stream = (string) $this->getRequest()->getParam('stream', 'requests');
        if (!in_array($stream, self::STREAMS, true)) {
            $stream = 'requests';
        }

        // X03: the instance the Trident screens are showing.
        $instance = $this->tridentClient->target();
        if ($instance === null) {
            return $resultJson->setData(['events' => [], 'error' => 'No Trident instance is configured.']);
        }
        $result = $this->poller->poll($instance, $stream, self::POLL_WINDOW);
        if ($result['error'] !== null) {
            $this->logger->error('Trident events poll failed', [
                'instance' => $instance->name,
                'stream' => $stream,
                'error' => $result['error'],
            ]);
        }

        return $resultJson->setData(array_filter([
            'events' => $result['events'],
            'stream' => $stream,
            'instance' => $instance->name,
            'error' => $result['error'],
        ], static fn ($v): bool => $v !== null));
    }
}
