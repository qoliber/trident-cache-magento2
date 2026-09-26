<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qoliber\Trident\Delivery\Instance;
use Qoliber\Trident\Testing\FakeTransport;
use Qoliber\TridentCache\Model\Admin\InstanceSelection;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\TridentClient;

/**
 * The client over qoliber/trident-php: what goes on the wire (method, URL,
 * token, body), what counts as an acknowledged purge, and which instance a
 * call goes to. The HTTP side is the library's FakeTransport, so these are the
 * requests the engine would really receive.
 */
class TridentClientTest extends TestCase
{
    private const PURGE_ACK = '{"purged":3,"mode":"hard","state":"applied","barrier":"delivery"}';

    private FakeTransport $http;

    /** @var list<Instance> */
    private array $instances;

    private bool $enabled = true;

    private bool $soft = false;

    private ?string $selected = null;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
        $this->instances = [new Instance('default', 'http://edge-1:9301', 'token-1')];
    }

    private function client(): TridentClient
    {
        $config = $this->createMock(Config::class);
        $config->method('isTridentEnabled')->willReturnCallback(fn (): bool => $this->enabled);
        $config->method('getInstances')->willReturnCallback(fn (): array => $this->instances);
        $config->method('isSoftPurgeEnabled')->willReturnCallback(fn (): bool => $this->soft);
        $selection = $this->createMock(InstanceSelection::class);
        $selection->method('selected')->willReturnCallback(fn (): ?string => $this->selected);

        return new TridentClient($this->http, new NullLogger(), $config, $selection);
    }

    private function twoEdges(): void
    {
        $this->instances = [
            new Instance('edge-1', 'http://edge-1:9301', 'token-1'),
            new Instance('edge-2', 'http://edge-2:9301', 'token-2'),
        ];
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: ?string}
     */
    private function only(): array
    {
        $this->assertCount(1, $this->http->requests);
        return $this->http->requests[0];
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(?string $body): array
    {
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ---- acknowledgement (X02) -------------------------------------------

    /**
     * @return array<string, array{int, string}>
     */
    public static function unacknowledged(): array
    {
        return [
            'unauthorized' => [401, '{"error":"unauthorized"}'],
            'rate limited' => [429, '{"error":"too many requests"}'],
            'unavailable' => [503, '{"error":"unavailable"}'],
            'server error' => [500, ''],
            'queue full' => [503, '{"error":"queue full"}'],
            'not json' => [200, '<html>proxy error</html>'],
            'error body with 200' => [200, '{"error":"something"}'],
            'missing purged' => [200, '{"mode":"hard","state":"applied"}'],
            'missing mode' => [200, '{"purged":1}'],
            'state not a string' => [200, '{"purged":1,"mode":"hard","state":7}'],
            'purged not a number' => [200, '{"purged":"3","mode":"hard","state":"applied"}'],
            'refused' => [200, '{"purged":0,"mode":"hard","state":"refused"}'],
            'redirect' => [302, ''],
        ];
    }

    #[DataProvider('unacknowledged')]
    public function testAnUnacknowledgedTagPurgeIsNotReportedAsDelivered(int $status, string $body): void
    {
        $this->http->answer('edge-1', $status, $body);
        $client = $this->client();

        $this->assertFalse($client->deliverTags(['cat_p_1']));
        $this->assertStringStartsWith('purge_tags: HTTP ' . $status, (string) $client->lastFailure());
    }

    public function testAnAcknowledgedTagPurgeIsDelivered(): void
    {
        $this->http->answer('edge-1', 200, self::PURGE_ACK);
        $client = $this->client();

        $this->assertSame(
            ['purged' => 3, 'mode' => 'hard', 'state' => 'applied', 'barrier' => 'delivery'],
            $client->purgeTags(['cat_p_1'])
        );
        $this->assertNull($client->lastFailure());
    }

    /**
     * 1.6/1.7 engines answer without `state`; they are still in production.
     */
    public function testAnOlderEngineAcknowledgementIsDelivered(): void
    {
        $this->http->answer('edge-1', 200, '{"purged":0,"mode":"soft","queued_refresh":0}');

        $this->assertTrue($this->client()->deliverTags(['cat_p_1']));
    }

    /**
     * Reflect mode queues the purge durably and answers 202 `recorded`;
     * retrying it would only flood the reflect queue.
     */
    public function testAPurgeDeferredByReflectModeIsDelivered(): void
    {
        $this->http->answer('edge-1', 202, '{"status":"deferred","state":"recorded","queued_purges":4}');

        $this->assertTrue($this->client()->deliverTags(['cat_p_1']));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function notADeferral(): array
    {
        return [
            'reflect queue full' => [503, '{"status":"refused","state":"refused"}'],
            'accepted without the state' => [202, '{"status":"deferred"}'],
            'accepted with an empty body' => [202, ''],
        ];
    }

    #[DataProvider('notADeferral')]
    public function testOnlyTheEnginesDeferralAnswerCountsAsDelivered(int $status, string $body): void
    {
        $this->http->answer('edge-1', $status, $body);

        $this->assertFalse($this->client()->deliverTags(['cat_p_1']));
    }

    public function testATransportFailureIsNotDelivered(): void
    {
        $this->http->down('edge-1');
        $client = $this->client();

        $this->assertFalse($client->deliverTags(['cat_p_1']));
        $this->assertStringStartsWith('purge_tags: no response', (string) $client->lastFailure());
    }

    public function testAFullClearNeedsClearedTrue(): void
    {
        $this->http->answer('edge-1', 200, '{"cleared":false}');

        $this->assertFalse($this->client()->deliverAll());
    }

    public function testAnAcknowledgedFullClearIsDelivered(): void
    {
        $this->http->answer('edge-1', 200, '{"cleared":true,"entries_removed":12}');

        $this->assertTrue($this->client()->deliverAll());
    }

    public function testWithoutATokenNothingIsSent(): void
    {
        $this->instances = [new Instance('default', 'http://edge-1:9301', '')];
        $client = $this->client();

        $this->assertFalse($client->isEnabled());
        $this->assertNull($client->purgeTags(['cat_p_1']));
        $this->assertNull($client->getStats());
        $this->assertSame([], $this->http->requests);
    }

    public function testWhenTridentIsNotTheCacheNothingIsSent(): void
    {
        $this->enabled = false;
        $client = $this->client();

        $this->assertNull($client->purgeAll());
        $this->assertNull($client->getHealth());
        $this->assertNull($client->warmerRun());
        $this->assertSame([], $this->http->requests);
    }

    // ---- what goes on the wire -------------------------------------------

    public function testAPurgeByTagsIsOneAuthenticatedJsonPost(): void
    {
        $this->client()->purgeTags(['cat_p_1', 'cat_p', 'cat_p_1']);

        $request = $this->only();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('http://edge-1:9301/admin/purge/tags', $request['url']);
        $this->assertSame('Bearer token-1', $request['headers']['Authorization']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame(['tags' => ['cat_p_1', 'cat_p'], 'mode' => 'hard'], self::json($request['body']));
    }

    public function testSoftPurgeAndExcludedTagsAreSent(): void
    {
        $this->soft = true;
        $this->client()->purgeTags(['cat_p_1'], ['cat_c_2', 'cat_c_2']);

        $this->assertSame(
            ['tags' => ['cat_p_1'], 'mode' => 'soft', 'exclude_tags' => ['cat_c_2']],
            self::json($this->only()['body'])
        );
    }

    public function testATagThatLooksNumericStaysAString(): void
    {
        $this->client()->purgeTags(['123']);

        $this->assertSame('{"tags":["123"],"mode":"hard"}', $this->only()['body']);
    }

    public function testNoTagsNoRequest(): void
    {
        $this->assertNull($this->client()->purgeTags([]));
        $this->assertSame([], $this->http->requests);
    }

    public function testAFullClearConfirms(): void
    {
        $this->http->answer('edge-1', 200, '{"cleared":true}');
        $this->client()->purgeAll();

        $request = $this->only();
        $this->assertSame('http://edge-1:9301/admin/cache/clear', $request['url']);
        $this->assertSame(['confirm' => true], self::json($request['body']));
    }

    public function testAUrlPatternPurge(): void
    {
        $this->http->answer('edge-1', 200, '{"purged":2,"mode":"hard"}');
        $this->client()->purgePattern('/catalog/*');

        $request = $this->only();
        $this->assertSame('http://edge-1:9301/admin/purge/urls', $request['url']);
        $this->assertSame(['pattern' => '/catalog/*', 'mode' => 'hard'], self::json($request['body']));
    }

    public function testATagPatternPurgeUsesTheEnginesField(): void
    {
        $this->http->answer('edge-1', 200, '{"purged":2,"mode":"soft"}');
        $this->soft = true;
        $this->client()->purgeTagPattern('^cat_c_\d+$', true);

        $this->assertSame(
            ['pattern' => '^cat_c_\d+$', 'pattern_type' => 'regex', 'mode' => 'soft'],
            self::json($this->only()['body'])
        );
    }

    public function testAReadIsAnAuthenticatedGetWithoutABody(): void
    {
        $this->http->answer('edge-1', 200, '{"status":"ok"}');

        $this->assertSame(['status' => 'ok'], $this->client()->getHealth());
        $request = $this->only();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('http://edge-1:9301/admin/health', $request['url']);
        $this->assertSame('Bearer token-1', $request['headers']['Authorization']);
        $this->assertNull($request['body']);
    }

    public function testListFiltersGoInTheQueryAndEmptyOnesAreLeftOut(): void
    {
        $this->http->answer('edge-1', 200, '{"entries":[]}');
        $client = $this->client();

        $client->getEntries(50, 25, 'cat_p_1', 'size');
        $client->getTags(0, 100, '');

        $this->assertSame(
            'http://edge-1:9301/admin/cache/entries?offset=50&limit=25&sort=size&tag=cat_p_1',
            $this->http->requests[0]['url']
        );
        $this->assertSame(
            'http://edge-1:9301/admin/cache/tags?offset=0&limit=100&sort=count',
            $this->http->requests[1]['url']
        );
    }

    public function testExplainSendsHeadersAsAJsonObject(): void
    {
        $this->http->answer('edge-1', 200, '{"cacheable":true}');
        $this->client()->explain('GET', '/gear.html');

        $this->assertSame(
            '{"method":"GET","url":"\/gear.html","headers":{},"detail":true}',
            $this->only()['body']
        );
    }

    /**
     * An empty body is `{}`: the engine's struct deserializers refuse `[]`,
     * which is what the module used to send for a POST without data.
     */
    public function testAPostWithoutDataSendsAnEmptyObject(): void
    {
        $this->http->answer('edge-1', 200, '{"cancelled":true}');
        $this->client()->warmerCancel();

        $this->assertSame('{}', $this->only()['body']);
    }

    public function testADiscoveryRefreshNamesTheBackendInTheQuery(): void
    {
        $this->http->answer('edge-1', 200, '{"success":true}');
        $this->client()->refreshDiscovery('php fpm');

        $request = $this->only();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('http://edge-1:9301/admin/discovery/refresh?name=php+fpm', $request['url']);
    }

    public function testDeletingABanIsADeleteAndTheNextCallKeepsItsOwnVerb(): void
    {
        $this->http->answer('edge-1', 204, '');
        $client = $this->client();

        $this->assertNotNull($client->deleteBan('ban/1'), 'a 204 with no body is a success');
        $client->createBan('/old/*', 'pattern');

        $this->assertSame('DELETE', $this->http->requests[0]['method']);
        $this->assertSame('http://edge-1:9301/admin/bans/ban%2F1', $this->http->requests[0]['url']);
        $this->assertSame('POST', $this->http->requests[1]['method']);
    }

    /**
     * An error body is not the data the screen asked for. The old client
     * returned the decoded 4xx body, so a disabled warmer's `{"code":
     * "WARMER_DISABLED"}` was reported as "Warmer started".
     */
    public function testAnErrorAnswerIsNullWithTheEnginesReason(): void
    {
        $this->http->answer('edge-1', 404, '{"error":"The cache warmer is not enabled","code":"WARMER_DISABLED"}');
        $client = $this->client();

        $this->assertNull($client->warmerRun());
        $this->assertSame(
            'POST /admin/warmer/run: HTTP 404: The cache warmer is not enabled',
            $client->lastFailure()
        );
    }

    public function testAnUnauthorizedReadIsNullAndSaysSo(): void
    {
        $this->http->answer('edge-1', 401, '{"error":"invalid token"}');
        $client = $this->client();

        $this->assertNull($client->getStatus());
        $this->assertStringContainsString('HTTP 401', (string) $client->lastFailure());
    }

    public function testTheAdminLimiterIsRetriedOnceWhenItSaysHowLongToWait(): void
    {
        $this->http->then('edge-1', 429, '{"error":"rate limited","retry_after_ms":1}');
        $this->http->then('edge-1', 200, '{"entries":7}');

        $this->assertSame(['entries' => 7], $this->client()->getStats());
        $this->assertCount(2, $this->http->requests);
    }

    // ---- which instance (X03) --------------------------------------------

    public function testATagPurgeGoesToEveryInstanceWithItsOwnToken(): void
    {
        $this->twoEdges();

        $result = $this->client()->purgeTags(['cat_p_1']);

        $this->assertSame([['cat_p_1']], $this->http->purgedTags('edge-1'));
        $this->assertSame([['cat_p_1']], $this->http->purgedTags('edge-2'));
        $this->assertSame('Bearer token-1', $this->http->requests[0]['headers']['Authorization']);
        $this->assertSame('Bearer token-2', $this->http->requests[1]['headers']['Authorization']);
        $this->assertSame(2, $result['purged'], 'counts are summed');
        $this->assertSame(['edge-1', 'edge-2'], array_keys($result['instances']));
    }

    public function testOneInstanceRefusingFailsTheWholePurgeAndNamesIt(): void
    {
        $this->twoEdges();
        $this->http->answer('edge-2', 401, '{"error":"unauthorized"}');
        $client = $this->client();

        $this->assertNull($client->purgeTags(['cat_p_1']));
        $this->assertSame('edge-2: purge_tags: HTTP 401 — unauthorized', $client->lastFailure());
    }

    public function testAFullClearGoesToEveryInstance(): void
    {
        $this->twoEdges();
        $this->http->answer('edge-1', 200, '{"cleared":true}')->answer('edge-2', 200, '{"cleared":true}');

        $this->assertTrue($this->client()->deliverAll());
        $this->assertSame(
            ['http://edge-1:9301/admin/cache/clear', 'http://edge-2:9301/admin/cache/clear'],
            array_column($this->http->requests, 'url')
        );
    }

    public function testAdminPurgesFanOutToo(): void
    {
        $this->twoEdges();
        $ack = '{"purged":1,"mode":"hard"}';
        $this->http->answer('edge-1', 200, $ack)->answer('edge-2', 200, $ack);
        $client = $this->client();

        $client->purgeUrl('/gear.html');
        $client->purgeHost('shop.example');
        $client->purgeVary('X-Magento-Vary', 'abc');
        $client->purgeTagPattern('cat_*');
        $client->purgePattern('/c/*');

        $this->assertCount(10, $this->http->requests);
    }

    public function testReadsGoToTheFirstInstanceOnly(): void
    {
        $this->twoEdges();
        $this->http->answer('edge-1', 200, '{"entries":1}');

        $this->client()->getStats();

        $this->assertSame('http://edge-1:9301/admin/stats', $this->only()['url']);
    }

    public function testBansStayOnOneInstance(): void
    {
        $this->twoEdges();
        $this->http->answer('edge-1', 200, '{"id":"b1"}');

        $this->client()->createBan('/x/*', 'pattern');

        $this->assertCount(1, $this->http->requests);
    }

    public function testABoundClientTalksToItsInstanceOnly(): void
    {
        $this->twoEdges();
        $this->http->answer('edge-2', 200, '{"purged":1,"mode":"hard"}');

        $bound = $this->client()->forInstance($this->instances[1]);
        $bound->purgeTags(['cat_p_1']);
        $bound->getStats();

        $this->assertSame(
            ['http://edge-2:9301/admin/purge/tags', 'http://edge-2:9301/admin/stats'],
            array_column($this->http->requests, 'url')
        );
    }

    public function testTheAdminsChosenInstanceIsTheOneShownAndActedOn(): void
    {
        $this->twoEdges();
        $this->selected = 'edge-2';
        $this->http->answer('edge-2', 200, '{"id":"b1"}');
        $client = $this->client();

        $client->getStats();
        $client->createBan('/x/*', 'pattern');

        $this->assertSame('edge-2', $client->target()?->name);
        $this->assertSame(['edge-2', 'edge-2'], array_map(
            fn (array $r): string => (string) parse_url($r['url'], PHP_URL_HOST),
            $this->http->requests
        ));
    }

    public function testAChoiceThatIsNoLongerConfiguredFallsBackToTheFirst(): void
    {
        $this->twoEdges();
        $this->selected = 'edge-9';

        $this->assertSame('edge-1', $this->client()->target()?->name);
    }

    public function testTheChoiceNeverNarrowsAnInvalidation(): void
    {
        $this->twoEdges();
        $this->selected = 'edge-2';

        $this->client()->purgeTags(['cat_p_1']);

        $this->assertCount(2, $this->http->requests);
    }

    public function testWithNoInstanceThereIsNoTargetAndNoRequest(): void
    {
        $this->instances = [];
        $client = $this->client();

        $this->assertNull($client->target());
        $this->assertFalse($client->isEnabled());
        $this->assertNull($client->getStats());
        $this->assertSame([], $this->http->requests);
    }
}
