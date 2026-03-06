<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Plugin;

use Magento\Framework\App\Response\Http as HttpResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Plugin\ResponsePlugin;

class ResponsePluginTest extends TestCase
{
    private ResponsePlugin $plugin;
    private Config&MockObject $configMock;
    private HttpResponse&MockObject $responseMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(Config::class);
        $this->plugin = new ResponsePlugin($this->configMock);
        $this->responseMock = $this->createMock(HttpResponse::class);
    }

    public function testSmaxageSetFromAdminConfig(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getTtl')->willReturn(3600);
        $this->configMock->method('getGracePeriod')->willReturn(86400);
        $this->configMock->method('isEsiEnabled')->willReturn(false);

        $cacheControlHeader = $this->createHeaderMock('public, max-age=120');
        $tagsHeader = $this->createHeaderMock('cat_p_1,cat_p_2');

        $this->responseMock->method('getHeader')
            ->willReturnMap([
                ['Cache-Control', $cacheControlHeader],
                ['X-Magento-Tags', $tagsHeader],
            ]);

        $this->responseMock->expects($this->once())
            ->method('setHeader')
            ->with(
                'Cache-Control',
                'public, max-age=120, s-maxage=3600, stale-while-revalidate=86400',
                true
            );

        $this->plugin->beforeSendResponse($this->responseMock);
    }

    public function testSmaxageNotAddedWhenAlreadyPresent(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getGracePeriod')->willReturn(86400);
        $this->configMock->method('isEsiEnabled')->willReturn(false);

        $cacheControlHeader = $this->createHeaderMock('public, max-age=120, s-maxage=600');
        $tagsHeader = $this->createHeaderMock('cat_p_1');

        $this->responseMock->method('getHeader')
            ->willReturnMap([
                ['Cache-Control', $cacheControlHeader],
                ['X-Magento-Tags', $tagsHeader],
            ]);

        // s-maxage already present, should only add stale-while-revalidate
        $this->responseMock->expects($this->once())
            ->method('setHeader')
            ->with(
                'Cache-Control',
                'public, max-age=120, s-maxage=600, stale-while-revalidate=86400',
                true
            );

        $this->plugin->beforeSendResponse($this->responseMock);
    }

    public function testNonPublicResponsesNotModified(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);

        $cacheControlHeader = $this->createHeaderMock('private, no-cache');

        $this->responseMock->method('getHeader')
            ->willReturnMap([
                ['Cache-Control', $cacheControlHeader],
            ]);

        $this->responseMock->expects($this->never())->method('setHeader');

        $this->plugin->beforeSendResponse($this->responseMock);
    }

    public function testNoModificationWhenTridentDisabled(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(false);

        $this->responseMock->expects($this->never())->method('getHeader');
        $this->responseMock->expects($this->never())->method('setHeader');

        $this->plugin->beforeSendResponse($this->responseMock);
    }

    public function testNoModificationWhenNoCacheControlHeader(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);

        $this->responseMock->method('getHeader')
            ->willReturnMap([
                ['Cache-Control', false],
            ]);

        $this->responseMock->expects($this->never())->method('setHeader');

        $this->plugin->beforeSendResponse($this->responseMock);
    }

    public function testNoModificationWhenNoMagentoTags(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);

        $cacheControlHeader = $this->createHeaderMock('public, max-age=120');

        $this->responseMock->method('getHeader')
            ->willReturnMap([
                ['Cache-Control', $cacheControlHeader],
                ['X-Magento-Tags', false],
            ]);

        $this->responseMock->expects($this->never())->method('setHeader');

        $this->plugin->beforeSendResponse($this->responseMock);
    }

    public function testEsiHeaderAddedWhenEnabled(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getTtl')->willReturn(86400);
        $this->configMock->method('getGracePeriod')->willReturn(0);
        $this->configMock->method('isEsiEnabled')->willReturn(true);
        $this->configMock->method('getEsiMaxDepth')->willReturn(3);

        $cacheControlHeader = $this->createHeaderMock('public, max-age=120');
        $tagsHeader = $this->createHeaderMock('cat_p_1');

        $this->responseMock->method('getHeader')
            ->willReturnMap([
                ['Cache-Control', $cacheControlHeader],
                ['X-Magento-Tags', $tagsHeader],
            ]);

        $setHeaderCalls = [];
        $this->responseMock->method('setHeader')
            ->willReturnCallback(function (string $name, string $value) use (&$setHeaderCalls) {
                $setHeaderCalls[] = [$name, $value];
            });

        $this->plugin->beforeSendResponse($this->responseMock);

        $this->assertCount(2, $setHeaderCalls);
        $this->assertEquals('Cache-Control', $setHeaderCalls[0][0]);
        $this->assertEquals('Surrogate-Control', $setHeaderCalls[1][0]);
        $this->assertEquals('content="ESI/1.0", max-depth=3', $setHeaderCalls[1][1]);
    }

    public function testStaleWhileRevalidateSkippedWhenGracePeriodZero(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getTtl')->willReturn(3600);
        $this->configMock->method('getGracePeriod')->willReturn(0);
        $this->configMock->method('isEsiEnabled')->willReturn(false);

        $cacheControlHeader = $this->createHeaderMock('public, max-age=120');
        $tagsHeader = $this->createHeaderMock('cat_p_1');

        $this->responseMock->method('getHeader')
            ->willReturnMap([
                ['Cache-Control', $cacheControlHeader],
                ['X-Magento-Tags', $tagsHeader],
            ]);

        $this->responseMock->expects($this->once())
            ->method('setHeader')
            ->with(
                'Cache-Control',
                'public, max-age=120, s-maxage=3600',
                true
            );

        $this->plugin->beforeSendResponse($this->responseMock);
    }

    private function createHeaderMock(string $value): object
    {
        $header = new class ($value) {
            public function __construct(private readonly string $value) {}
            public function getFieldValue(): string { return $this->value; }
        };

        return $header;
    }
}
