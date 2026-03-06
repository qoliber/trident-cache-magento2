<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Plugin\PageCache;

use Magento\PageCache\Model\Config;
use Qoliber\TridentCache\Model\Config as TridentConfig;

/**
 * Maps Trident cache type (3) to Varnish (2) so all core FPC plugins
 * (VarnishPlugin, ProcessLayoutRenderElement, LayoutPlugin) activate
 * for Trident without patching core code.
 */
class ConfigTypePlugin
{
    public function afterGetType(Config $subject, int $result): int
    {
        if ($result === TridentConfig::TRIDENT) {
            return Config::VARNISH;
        }

        return $result;
    }
}
