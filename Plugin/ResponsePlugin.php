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
     * Before sendResponse - set cache headers for Trident
     *
     * Magento generates proper cache tags in X-Magento-Tags header natively.
     * Trident reads X-Magento-Tags directly - no conversion needed.
     * We set s-maxage from admin config and add stale-while-revalidate from grace period.
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

        // Set s-maxage from admin config TTL if not already present
        if (!str_contains($cacheControlValue, 's-maxage')) {
            $ttl = $this->config->getTtl();
            $cacheControlValue .= ', s-maxage=' . $ttl;
        }

        // Add stale-while-revalidate from grace period config
        if (!str_contains($cacheControlValue, 'stale-while-revalidate')) {
            $gracePeriod = $this->config->getGracePeriod();
            if ($gracePeriod > 0) {
                $cacheControlValue .= ', stale-while-revalidate=' . $gracePeriod;
            }
        }

        $response->setHeader('Cache-Control', $cacheControlValue, true);

        // Set Surrogate-Control header for ESI processing
        if ($this->config->isEsiEnabled()) {
            $maxDepth = $this->config->getEsiMaxDepth();
            $response->setHeader(
                'Surrogate-Control',
                'content="ESI/1.0", max-depth=' . $maxDepth,
                true
            );
        }
    }
}
