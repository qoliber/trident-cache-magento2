<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\Config;

/**
 * Review I10: the tag limit of one response is a setting, 200 unless set.
 */
class ConfigMaxTagsTest extends TestCase
{
    private function config(mixed $maxTags): Config
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            fn (string $path): mixed => $path === Config::XML_TRIDENT_MAX_TAGS ? $maxTags : null
        );

        return new Config($scope, $this->createMock(EncryptorInterface::class));
    }

    public function testTheLimitIsTridentsDefaultWhenUnset(): void
    {
        $this->assertSame(200, $this->config(null)->getMaxTagsPerResponse());
        $this->assertSame(200, $this->config('')->getMaxTagsPerResponse());
    }

    public function testAConfiguredLimitIsUsed(): void
    {
        $this->assertSame(350, $this->config('350')->getMaxTagsPerResponse());
        $this->assertSame(64, $this->config(64)->getMaxTagsPerResponse());
    }

    public function testZeroSwitchesTheCapOff(): void
    {
        $this->assertSame(0, $this->config('0')->getMaxTagsPerResponse());
        $this->assertSame(0, $this->config('-5')->getMaxTagsPerResponse(), 'a negative limit is no limit either');
    }

    public function testAValueThatIsNotANumberFallsBackToTheDefault(): void
    {
        $this->assertSame(200, $this->config('many')->getMaxTagsPerResponse());
    }
}
