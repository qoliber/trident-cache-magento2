<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration;

use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\TridentClient;

class TridentClientTest extends TestCase
{
    private TridentClient $client;
    private Curl&MockObject $curlMock;
    private Config&MockObject $configMock;
    private LoggerInterface&MockObject $loggerMock;

    protected function setUp(): void
    {
        $this->curlMock = $this->createMock(Curl::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->configMock = $this->createMock(Config::class);

        $this->client = new TridentClient(
            $this->curlMock,
            $this->loggerMock,
            $this->configMock
        );
    }

    public function testPurgeTagsSendsCorrectRequest(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isSoftPurgeEnabled')->willReturn(true);
        $this->configMock->method('isDebugEnabled')->willReturn(false);

        $this->curlMock->expects($this->once())
            ->method('setHeaders')
            ->with([
                'Authorization' => 'Bearer test-token',
                'Content-Type' => 'application/json',
            ]);

        $expectedPayload = json_encode([
            'tags' => ['cat_p_1', 'cat_p_2'],
            'mode' => 'soft',
        ]);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with('http://127.0.0.1:6085/admin/purge/tags', $expectedPayload);

        $this->curlMock->method('getBody')
            ->willReturn('{"purged": 5}');

        $result = $this->client->purgeTags(['cat_p_1', 'cat_p_2']);

        $this->assertIsArray($result);
        $this->assertEquals(5, $result['purged']);
    }

    public function testPurgeTagsWithExcludeTags(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isSoftPurgeEnabled')->willReturn(false);
        $this->configMock->method('isDebugEnabled')->willReturn(false);

        $expectedPayload = json_encode([
            'tags' => ['cat_p_1'],
            'mode' => 'hard',
            'exclude_tags' => ['cat_c_1'],
        ]);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with('http://127.0.0.1:6085/admin/purge/tags', $expectedPayload);

        $this->curlMock->method('getBody')->willReturn('{"purged": 1}');

        $result = $this->client->purgeTags(['cat_p_1'], ['cat_c_1']);

        $this->assertIsArray($result);
    }

    public function testPurgeAllSendsCorrectRequest(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isDebugEnabled')->willReturn(false);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with(
                'http://127.0.0.1:6085/admin/cache/clear',
                json_encode(['confirm' => true])
            );

        $this->curlMock->method('getBody')->willReturn('{"cleared": true}');

        $result = $this->client->purgeAll();

        $this->assertIsArray($result);
        $this->assertTrue($result['cleared']);
    }

    public function testPurgePatternSendsCorrectRequest(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isSoftPurgeEnabled')->willReturn(true);
        $this->configMock->method('isDebugEnabled')->willReturn(false);

        $expectedPayload = json_encode([
            'pattern' => '/catalog/product/*',
            'mode' => 'soft',
        ]);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with('http://127.0.0.1:6085/admin/purge/urls', $expectedPayload);

        $this->curlMock->method('getBody')->willReturn('{"purged": 3}');

        $result = $this->client->purgePattern('/catalog/product/*');

        $this->assertIsArray($result);
    }

    public function testGetHealthSendsCorrectRequest(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');

        $this->curlMock->expects($this->once())
            ->method('get')
            ->with('http://127.0.0.1:6085/admin/health');

        $this->curlMock->method('getBody')
            ->willReturn('{"status": "ok", "version": "0.1.0"}');

        $result = $this->client->getHealth();

        $this->assertIsArray($result);
        $this->assertEquals('ok', $result['status']);
        $this->assertEquals('0.1.0', $result['version']);
    }

    public function testDisabledClientMakesNoHttpCalls(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(false);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');

        $this->curlMock->expects($this->never())->method('post');
        $this->curlMock->expects($this->never())->method('get');

        $this->assertNull($this->client->purgeTags(['cat_p_1']));
        $this->assertNull($this->client->purgeAll());
        $this->assertNull($this->client->purgePattern('/test'));
        $this->assertNull($this->client->getStats());
        $this->assertNull($this->client->getRules());
        $this->assertNull($this->client->getHealth());
    }

    public function testPurgeTagsReturnsNullOnEmptyTags(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');

        $this->curlMock->expects($this->never())->method('post');

        $this->assertNull($this->client->purgeTags([]));
    }

    public function testPurgeTagsReturnsNullOnException(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isSoftPurgeEnabled')->willReturn(true);
        $this->configMock->method('isDebugEnabled')->willReturn(false);

        $this->curlMock->method('post')
            ->willThrowException(new \Exception('Connection refused'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Trident cache purge failed', $this->anything());

        $result = $this->client->purgeTags(['cat_p_1']);

        $this->assertNull($result);
    }

    public function testPurgeTagsDeduplicatesTags(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isSoftPurgeEnabled')->willReturn(true);
        $this->configMock->method('isDebugEnabled')->willReturn(false);

        $expectedPayload = json_encode([
            'tags' => ['cat_p_1', 'cat_p_2'],
            'mode' => 'soft',
        ]);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with('http://127.0.0.1:6085/admin/purge/tags', $expectedPayload);

        $this->curlMock->method('getBody')->willReturn('{}');

        $this->client->purgeTags(['cat_p_1', 'cat_p_2', 'cat_p_1']);
    }
}
