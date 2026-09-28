<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Warmer;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class Run extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::warmer';

    public function __construct(
        Context $context,
        private readonly TridentClient $tridentClient,
        private readonly LoggerInterface $logger,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        $urlsRaw = (string) $this->getRequest()->getParam('urls', '');
        $urls = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', $urlsRaw) ?: []
        )));

        try {
            $sitemaps = $this->ownSitemaps((string) $this->getRequest()->getParam('sitemaps', ''));
            $result = $this->tridentClient->warmerRun($urls, $sitemaps);

            if ($result !== null) {
                if ($sitemaps !== []) {
                    $this->messageManager->addSuccessMessage(
                        __('Cache warmer started from %1 sitemap(s): %2 URL(s) queued.', count($sitemaps), (int) ($result['queued'] ?? 0))
                    );
                } elseif (empty($urls)) {
                    $this->messageManager->addSuccessMessage(
                        __('Cache warmer started for configured sources.')
                    );
                } else {
                    $this->messageManager->addSuccessMessage(
                        __('Cache warmer started for %1 URL(s).', count($urls))
                    );
                }
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to start the cache warmer. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident warmer run error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error starting cache warmer: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/warmer/index');
    }

    /**
     * The sitemaps an admin typed, as absolute URLs on this installation's own
     * store hosts. A path resolves on the default store. On a shared Trident a
     * store may not warm another site's sitemap, so any other host is refused.
     *
     * @return array<int, string>
     * @throws \InvalidArgumentException naming the first foreign or malformed entry
     */
    private function ownSitemaps(string $raw): array
    {
        $hosts = [];
        foreach ($this->storeManager->getStores() as $store) {
            foreach ([false, true] as $secure) {
                $base = (string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, $secure);
                $host = parse_url($base, PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    $port = parse_url($base, PHP_URL_PORT);
                    $hosts[strtolower($host) . ($port ? ':' . $port : '')] = true;
                }
            }
        }
        $default = rtrim((string) $this->storeManager->getDefaultStoreView()?->getBaseUrl(), '/');
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('#^https?://#i', $line)) {
                $out[] = $default . '/' . ltrim($line, '/');
                continue;
            }
            $host = strtolower((string) parse_url($line, PHP_URL_HOST));
            $port = parse_url($line, PHP_URL_PORT);
            if ($host === '' || !isset($hosts[$host . ($port ? ':' . $port : '')])) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a sitemap of this installation', $line));
            }
            $out[] = $line;
        }
        return $out;
    }
}
