<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Plugin;

use Magento\Framework\App\Response\Http as HttpResponse;
use Qoliber\TridentCache\Model\Config;

class ResponsePlugin
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Before sendResponse - ensure X-Magento-Tags is present for Trident
     *
     * Magento generates proper cache tags in X-Magento-Tags header natively.
     * Trident reads X-Magento-Tags directly - no conversion needed.
     * We strip the header after Trident captures it to avoid exposing tags to clients.
     */
    public function beforeSendResponse(HttpResponse $response): void
    {
        if (!$this->config->isTridentEnabled()) {
            return;
        }

        $cacheControl = $response->getHeader('Cache-Control');
        if (!$cacheControl) {
            return;
        }

        $cacheControlValue = $cacheControl->getFieldValue();
        if (!str_contains($cacheControlValue, 'public')) {
            return;
        }

        $magentoTags = $response->getHeader('X-Magento-Tags');
        if (!$magentoTags) {
            return;
        }

        // Ensure s-maxage is set if only max-age is present
        if (!str_contains($cacheControlValue, 's-maxage') && preg_match('/max-age=(\d+)/', $cacheControlValue, $matches)) {
            $response->setHeader(
                'Cache-Control',
                $cacheControlValue . ', s-maxage=' . $matches[1],
                true
            );
        }
    }
}
