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
use Qoliber\TridentCache\Model\TagBudget;

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
     * Trident reads X-Magento-Tags directly - no conversion needed, and the
     * header is passed through untouched unless it carries more tags than
     * Trident stores per entry: then it is bounded (see TagBudget), because
     * Trident does not cache a response over that limit at all.
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

        $this->boundTags($response, $magentoTags);

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

    /**
     * Keep X-Magento-Tags within the configured tag limit.
     *
     * The header is rewritten only when it is over the limit; the tags that
     * stay are byte for byte the ones Magento emitted (Trident matches tags
     * case-sensitively).
     *
     * @param HttpResponse $response
     * @param mixed $magentoTags The header as getHeader() returned it.
     * @return void
     */
    private function boundTags(HttpResponse $response, mixed $magentoTags): void
    {
        $maxTags = $this->config->getMaxTagsPerResponse();
        if ($maxTags <= 0 || !is_object($magentoTags) || !method_exists($magentoTags, 'getFieldValue')) {
            return;
        }

        $value = (string) $magentoTags->getFieldValue();
        $bounded = TagBudget::fitHeader($value, $maxTags);
        if ($bounded !== $value) {
            $response->setHeader('X-Magento-Tags', $bounded, true);
        }
    }
}
