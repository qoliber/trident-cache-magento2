<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Plugin;

use Magento\Framework\Phrase;
use Magento\PageCache\Model\System\Config\Source\Application;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Plugin\ApplicationPlugin;

class ApplicationPluginTest extends TestCase
{
    private ApplicationPlugin $plugin;
    private Application $subject;

    protected function setUp(): void
    {
        $this->plugin = new ApplicationPlugin();
        $this->subject = $this->createMock(Application::class);
    }

    public function testDropdownContainsTridentOption(): void
    {
        $existingOptions = [
            ['value' => 1, 'label' => new Phrase('Built-in Application')],
            ['value' => 2, 'label' => new Phrase('Varnish Caching')],
        ];

        $result = $this->plugin->afterToOptionArray($this->subject, $existingOptions);

        $this->assertCount(3, $result);

        $tridentOption = $result[2];
        $this->assertEquals(Config::TRIDENT, $tridentOption['value']);
        $this->assertEquals(3, $tridentOption['value']);
    }

    public function testDropdownLabelShowsRecommended(): void
    {
        $result = $this->plugin->afterToOptionArray($this->subject, []);

        $tridentOption = $result[0];
        $this->assertStringContainsString('Trident Cache (Recommended)', (string) $tridentOption['label']);
    }

    public function testArrayContainsTridentOption(): void
    {
        $existingArray = [
            1 => new Phrase('Built-in Application'),
            2 => new Phrase('Varnish Caching'),
        ];

        $result = $this->plugin->afterToArray($this->subject, $existingArray);

        $this->assertCount(3, $result);
        $this->assertArrayHasKey(Config::TRIDENT, $result);
        $this->assertStringContainsString('Trident Cache (Recommended)', (string) $result[Config::TRIDENT]);
    }
}
