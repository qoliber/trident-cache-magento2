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

use Magento\PageCache\Model\System\Config\Source\Application;
use Qoliber\TridentCache\Model\Config;

class ApplicationPlugin
{
    /**
     * @param \Magento\PageCache\Model\System\Config\Source\Application $subject
     * @param array<int, array{value: int, label: \Magento\Framework\Phrase}> $result
     * @return array<int, array{value: int, label: \Magento\Framework\Phrase}>
     */
    public function afterToOptionArray(Application $subject, array $result): array
    {
        $result[] = [
            'value' => Config::TRIDENT,
            'label' => __('Trident Cache (Recommended)')
        ];

        return $result;
    }

    /**
     * @param \Magento\PageCache\Model\System\Config\Source\Application $subject
     * @param array<int, \Magento\Framework\Phrase> $result
     * @return array<int, \Magento\Framework\Phrase>
     */
    public function afterToArray(Application $subject, array $result): array
    {
        $result[Config::TRIDENT] = __('Trident Cache (Recommended)');

        return $result;
    }
}
