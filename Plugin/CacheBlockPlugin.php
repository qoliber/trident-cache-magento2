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

use Magento\Backend\Block\Cache;
use Magento\Framework\AuthorizationInterface;
use Qoliber\TridentCache\Model\Config;

class CacheBlockPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    public function afterSetLayout(Cache $subject, Cache $result): Cache
    {
        if (!$this->config->isTridentEnabled()) {
            return $result;
        }

        if (!$this->authorization->isAllowed('Qoliber_TridentCache::purge')) {
            return $result;
        }

        $buttonList = $subject->getButtonList();
        if ($buttonList === null) {
            return $result;
        }

        $mode = $this->config->isSoftPurgeEnabled() ? __('Soft Purge') : __('Hard Purge');
        $message = $subject->escapeJs(
            $subject->escapeHtml(
                __('This will purge all entries from Trident cache (%1). Continue?', $mode)
            )
        );

        $buttonList->add(
            'flush_trident',
            [
                'label' => __('Purge Trident Cache'),
                'onclick' => sprintf(
                    "confirmSetLocation('%s', '%s')",
                    $message,
                    $subject->getUrl('trident/cache/purgeAll')
                ),
                'class' => 'primary flush-cache-trident',
            ],
            0,
            20
        );

        return $result;
    }
}
