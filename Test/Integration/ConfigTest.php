<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\Config;

class ConfigTest extends TestCase
{
    private Config $config;
    private ScopeConfigInterface&MockObject $scopeConfigMock;
    private EncryptorInterface&MockObject $encryptorMock;

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->encryptorMock = $this->createMock(EncryptorInterface::class);

        $this->config = new Config(
            $this->scopeConfigMock,
            $this->encryptorMock
        );
    }

    public function testIsTridentEnabledReturnsTrueWhenTypeIs3(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_CACHING_APPLICATION)
            ->willReturn('3');

        $this->assertTrue($this->config->isTridentEnabled());
    }

    public function testIsTridentEnabledReturnsFalseForBuiltInCache(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_CACHING_APPLICATION)
            ->willReturn('1');

        $this->assertFalse($this->config->isTridentEnabled());
    }

    public function testIsTridentEnabledReturnsFalseForVarnish(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_CACHING_APPLICATION)
            ->willReturn('2');

        $this->assertFalse($this->config->isTridentEnabled());
    }

    public function testGetApiUrlReturnsConfiguredUrl(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_API_URL)
            ->willReturn('http://127.0.0.1:6085');

        $this->assertEquals('http://127.0.0.1:6085', $this->config->getApiUrl());
    }

    public function testGetApiUrlReturnsDefaultWhenEmpty(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_API_URL)
            ->willReturn(null);

        $this->assertEquals('http://trident:9301', $this->config->getApiUrl());
    }

    public function testGetApiTokenReturnsDecryptedValue(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_API_TOKEN)
            ->willReturn('encrypted-token');

        $this->encryptorMock->method('decrypt')
            ->with('encrypted-token')
            ->willReturn('plain-token');

        $this->assertEquals('plain-token', $this->config->getApiToken());
    }

    public function testGetApiTokenReturnsEmptyWhenNotSet(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_API_TOKEN)
            ->willReturn('');

        $this->encryptorMock->expects($this->never())->method('decrypt');

        $this->assertEquals('', $this->config->getApiToken());
    }

    public function testSoftPurgeEnabledByDefault(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_SOFT_PURGE)
            ->willReturn('1');

        $this->assertTrue($this->config->isSoftPurgeEnabled());
    }

    public function testDebugDisabledByDefault(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_DEBUG)
            ->willReturn('0');

        $this->assertFalse($this->config->isDebugEnabled());
    }

    public function testGetTtlReturnsConfiguredValue(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_TTL)
            ->willReturn('3600');

        $this->assertEquals(3600, $this->config->getTtl());
    }

    public function testGetTtlReturnsDefaultWhenEmpty(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_TTL)
            ->willReturn(null);

        $this->assertEquals(86400, $this->config->getTtl());
    }

    public function testGetGracePeriodReturnsConfiguredValue(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_GRACE_PERIOD)
            ->willReturn('7200');

        $this->assertEquals(7200, $this->config->getGracePeriod());
    }

    public function testGetStaticTtlReturnsConfiguredValue(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_TTL_STATIC)
            ->willReturn('604800');

        $this->assertEquals(604800, $this->config->getStaticTtl());
    }

    public function testIsEsiEnabledReturnsFalseByDefault(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_ESI_ENABLED)
            ->willReturn('0');

        $this->assertFalse($this->config->isEsiEnabled());
    }

    public function testIsEsiEnabledReturnsTrue(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_ESI_ENABLED)
            ->willReturn('1');

        $this->assertTrue($this->config->isEsiEnabled());
    }

    public function testGetEsiMaxDepthReturnsConfiguredValue(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_ESI_MAX_DEPTH)
            ->willReturn('5');

        $this->assertEquals(5, $this->config->getEsiMaxDepth());
    }

    public function testGetEsiMaxDepthReturnsDefaultWhenEmpty(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(Config::XML_TRIDENT_ESI_MAX_DEPTH)
            ->willReturn(null);

        $this->assertEquals(3, $this->config->getEsiMaxDepth());
    }
}
