<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Plugin;

use Magento\PageCache\Model\Config;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Plugin\PageCache\ConfigTypePlugin;

class ConfigTypePluginTest extends TestCase
{
    private ConfigTypePlugin $plugin;

    protected function setUp(): void
    {
        $this->plugin = new ConfigTypePlugin();
    }

    public function testTridentTypeMappedToVarnish(): void
    {
        $configMock = $this->createMock(Config::class);

        $result = $this->plugin->afterGetType($configMock, 3);

        $this->assertEquals(Config::VARNISH, $result);
    }

    public function testVarnishTypePassesThrough(): void
    {
        $configMock = $this->createMock(Config::class);

        $result = $this->plugin->afterGetType($configMock, 2);

        $this->assertEquals(Config::VARNISH, $result);
    }

    public function testBuiltInTypePassesThrough(): void
    {
        $configMock = $this->createMock(Config::class);

        $result = $this->plugin->afterGetType($configMock, 1);

        $this->assertEquals(Config::BUILT_IN, $result);
    }
}
