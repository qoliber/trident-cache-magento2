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
        $config->method('getPurgeMode')->willReturnCallback(fn (): string => $this->soft ? 'soft' : 'hard');
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

        $this->assertNull($client->purgeTags(['cat_p_1']));
        $this->assertStringStartsWith('purge_tags: HTTP ' . $status, (string) $client->lastFailure());
        $this->assertNotSame([], $this->http->requests);
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

        $this->assertNotNull($this->client()->purgeTags(['cat_p_1']));
    }

    /**
     * Reflect mode queues the purge durably and answers 202 `recorded`;
     * retrying it would only flood the reflect queue.
     */
    public function testAPurgeDeferredByReflectModeIsDelivered(): void
    {
        $this->http->answer('edge-1', 202, '{"status":"deferred","state":"recorded","queued_purges":4}');

        $this->assertNotNull($this->client()->purgeTags(['cat_p_1']));
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

        $this->assertNull($this->client()->purgeTags(['cat_p_1']));
    }

    public function testATransportFailureIsNotDelivered(): void
    {
        $this->http->down('edge-1');
        $client = $this->client();

        $this->assertNull($client->purgeTags(['cat_p_1']));
        $this->assertStringStartsWith('purge_tags: Failed to connect to Trident', (string) $client->lastFailure());
    }

    public function testAFullClearNeedsClearedTrue(): void
    {
        $this->http->answer('edge-1', 200, '{"cleared":false}');

        $this->assertNull($this->client()->purgeAll());
    }

    public function testAnAcknowledgedFullClearIsDelivered(): void
    {
        $this->http->answer('edge-1', 200, '{"cleared":true,"entries_removed":12}');

        $this->assertNotNull($this->client()->purgeAll());
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
        $this->assertSame(['tags' => ['cat_p_1', 'cat_p'], 'match_mode' => 'any', 'mode' => 'hard'], self::json($request['body']));
    }

    public function testSoftPurgeAndExcludedTagsAreSent(): void
    {
        $this->soft = true;
        $this->client()->purgeTags(['cat_p_1'], ['cat_c_2', 'cat_c_2']);

        $this->assertSame(
            ['tags' => ['cat_p_1'], 'match_mode' => 'any', 'mode' => 'soft', 'exclude_tags' => ['cat_c_2']],
            self::json($this->only()['body'])
        );
    }

    public function testATagThatLooksNumericStaysAString(): void
    {
        $this->client()->purgeTags(['123']);

        $this->assertSame('{"tags":["123"],"match_mode":"any","mode":"hard"}', $this->only()['body']);
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
        // The library's route; the engine serves /admin/purge/urls and
        // /admin/purge/pattern with the same handler.
        $this->assertSame('http://edge-1:9301/admin/purge/pattern', $request['url']);
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
            'http://edge-1:9301/admin/cache/entries?limit=25&offset=50&sort=size&tag=cat_p_1',
            $this->http->requests[0]['url']
        );
        $this->assertSame(
            'http://edge-1:9301/admin/cache/tags?limit=100&offset=0&sort=count',
            $this->http->requests[1]['url']
        );
    }

    /**
     * A POST without data carries no body at all (the library's Api); the
     * module used to send `[]`, which the engine's parsers refuse.
     */
    public function testAPostWithoutDataSendsNoArray(): void
    {
        $this->http->answer('edge-1', 200, '{"cancelled":true}');
        $this->client()->warmerCancel();

        $this->assertNull($this->only()['body']);
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
        $this->assertSame('POST /admin/warmer/run: HTTP 404: The cache warmer is not enabled', $client->lastFailure());
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
        $this->assertSame('edge-2: purge_tags: HTTP 401: unauthorized', $client->lastFailure());
    }

    public function testAFullClearGoesToEveryInstance(): void
    {
        $this->twoEdges();
        $this->http->answer('edge-1', 200, '{"cleared":true}')->answer('edge-2', 200, '{"cleared":true}');

        $this->assertNotNull($this->client()->purgeAll());
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

    // ---- review fixes -----------------------------------------------------

    /**
     * Review #2: the admin plugins gate every purge on isEnabled(). Basing it
     * on the SELECTED instance meant that showing an instance without a token
     * switched off all admin purges (cache flush, cache clean).
     */
    public function testChoosingAnInstanceWithoutATokenDoesNotSwitchTheClientOff(): void
    {
        $this->instances = [
            new Instance('edge-1', 'http://edge-1:9301', 'token-1'),
            new Instance('edge-2', 'http://edge-2:9301', ''),
        ];
        $this->selected = 'edge-2';
        $client = $this->client();

        $this->assertTrue($client->isEnabled(), 'edge-1 has a token');
        $this->assertNull($client->getStats(), 'but edge-2, which the screen shows, cannot be asked');
        $this->assertSame('instance "edge-2" has no API token', $client->lastFailure());
        $this->assertSame([], $this->http->requests, 'no empty-Bearer request');
    }

    public function testNoTokenAnywhereIsDisabled(): void
    {
        $this->instances = [
            new Instance('edge-1', 'http://edge-1:9301', ''),
            new Instance('edge-2', 'http://edge-2:9301', ''),
        ];

        $this->assertFalse($this->client()->isEnabled());
    }

    public function testABoundClientIsEnabledByItsOwnToken(): void
    {
        $this->instances = [
            new Instance('edge-1', 'http://edge-1:9301', 'token-1'),
            new Instance('edge-2', 'http://edge-2:9301', ''),
        ];

        $this->assertFalse($this->client()->forInstance($this->instances[1])->isEnabled());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function notTheAdminApi(): array
    {
        return [
            'redirect to a login page' => [302, ''],
            'proxy error page with 200' => [200, '<html>Bad gateway</html>'],
        ];
    }

    /**
     * Review #1 (qoliber/trident-php 1.4.1): an answer that is not the admin
     * API's is not data — screens reported success when Trident never answered.
     */
    #[DataProvider('notTheAdminApi')]
    public function testAnAnswerThatIsNotTheAdminApiIsNotData(int $status, string $body): void
    {
        $this->http->answer('edge-1', $status, $body);
        $client = $this->client();

        $this->assertNull($client->getStats());
        $this->assertNull($client->reflectEnable());
        $this->assertNotNull($client->lastFailure());
    }

    /**
     * The engine's warmer run takes no body; a URL list sent there was ignored
     * and the configured sources warmed instead.
     */
    public function testWarmingGivenUrlsQueuesThem(): void
    {
        $this->http->answer('edge-1', 200, '{"queued":2}');
        $this->client()->warmerRun(['/a.html', '/b.html']);

        $request = $this->only();
        $this->assertSame('http://edge-1:9301/admin/warmer/queue', $request['url']);
        $this->assertSame(['urls' => ['/a.html', '/b.html']], self::json($request['body']));
    }

    public function testWarmingWithoutUrlsRunsTheConfiguredSources(): void
    {
        $this->http->answer('edge-1', 202, '{"status":"started","queued":10}');

        $this->assertSame(['status' => 'started', 'queued' => 10], $this->client()->warmerRun());
        $this->assertSame('http://edge-1:9301/admin/warmer/run', $this->only()['url']);
    }

    /**
     * Endpoints the library returns raw go through its typed client; the
     * screens get the engine's answer unchanged.
     */
    public function testDelegatedCallsSendWhatTheEngineExpects(): void
    {
        $this->http->answer('edge-1', 200, '{"ok":true,"extra":{"kept":1}}');
        $client = $this->client();

        $this->assertSame(['ok' => true, 'extra' => ['kept' => 1]], $client->getReflectStatus());
        $client->reflectEnable('selective', '10m', 'deploy');
        $client->cacheCoverage(['/a.html'], 'shop.example', 'https');
        $client->denoiserQueryUnpin('utm_x');
        $client->denoiserPathReset();

        $sent = array_map(fn (array $r): array => [$r['method'], $r['url'], $r['body']], $this->http->requests);
        $this->assertSame([
            ['GET', 'http://edge-1:9301/admin/reflect/status', null],
            ['POST', 'http://edge-1:9301/admin/reflect/enable', '{"level":"selective","duration":"10m","reason":"deploy"}'],
            ['POST', 'http://edge-1:9301/admin/cache/coverage', '{"urls":["\\/a.html"],"scheme":"https","method":"GET","host":"shop.example"}'],
            ['POST', 'http://edge-1:9301/admin/denoisers/query/unpin', '{"param":"utm_x","host":"*","path_prefix":"\\/"}'],
            ['POST', 'http://edge-1:9301/admin/denoisers/path/reset', null],
        ], $sent, 'a GET and a bare POST carry no body at all — not {} or []');
    }

    public function testATagPatternPurgeIsExplicitAboutItsMode(): void
    {
        $this->http->answer('edge-1', 200, '{"purged":1,"mode":"hard"}');
        $this->client()->purgeTagPattern('cat_*');

        $this->assertSame(
            ['pattern' => 'cat_*', 'pattern_type' => 'wildcard', 'mode' => 'hard'],
            self::json($this->only()['body'])
        );
    }

    /**
     * The one clear path, as the outbox delivers it: an instance that did not
     * answer is marked unreachable, so the drain does not try it again.
     */
    public function testAClearOfAnInstanceIsJudgedAndSaysWhenNothingAnswered(): void
    {
        $this->twoEdges();
        $this->http->answer('edge-1', 200, '{"cleared":true}')->down('edge-2');
        $client = $this->client();

        $this->assertTrue($client->purgeClient($this->instances[0])->clear()->acknowledged());
        $down = $client->purgeClient($this->instances[1])->clear();
        $this->assertFalse($down->acknowledged());
        $this->assertTrue($down->unreachable);
    }

    /**
     * Review: the coverage method (the engine's CacheCoverageRequest.method)
     * was dropped, so every check looked at GET entries.
     */
    public function testCoverageSendsTheMethod(): void
    {
        $this->http->answer('edge-1', 200, '{"coverage_percent":50}');
        $this->client()->cacheCoverage(['/a.html'], null, 'https', 'HEAD');

        $this->assertSame(['urls' => ['/a.html'], 'scheme' => 'https', 'method' => 'HEAD'], self::json($this->only()['body']));
    }

    /**
     * Review: a bound client for an instance without a token said "disabled"
     * with no reason, and logged the global "purges and admin calls are
     * disabled" warning while the other instances worked.
     */
    public function testABoundClientWithoutATokenSaysWhichInstanceAndWarnsNoOneElse(): void
    {
        $this->instances = [
            new Instance('edge-1', 'http://edge-1:9301', 'token-1'),
            new Instance('edge-2', 'http://edge-2:9301', ''),
        ];
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $config = $this->createMock(Config::class);
        $config->method('isTridentEnabled')->willReturn(true);
        $config->method('getInstances')->willReturnCallback(fn (): array => $this->instances);
        // The warning is logged once per process: start from "not yet".
        (new \ReflectionProperty(TridentClient::class, 'tokenMissingLogged'))->setValue(null, false);
        $bound = (new TridentClient($this->http, $logger, $config))->forInstance($this->instances[1]);

        $this->assertFalse($bound->isEnabled());
        $this->assertNull($bound->getStats());
        $this->assertSame('instance "edge-2" has no API token', $bound->lastFailure());
        $this->assertNull($bound->purgeTags(['cat_p_1']));
        $this->assertSame('instance "edge-2" has no API token', $bound->lastFailure());
        $this->assertSame([], $logger->warnings);
        $this->assertSame([], $this->http->requests);
    }

    // ---- qoliber/trident-php 1.5.0 ----------------------------------------

    /**
     * @return array<string, array{bool, string}>
     */
    public static function storeModes(): array
    {
        return ['store purges soft' => [true, 'soft'], 'store purges hard' => [false, 'hard']];
    }

    /**
     * In 1.5.0 a PurgeRequest without a mode takes the engine's
     * default_purge_mode (soft). The store's own setting must reach Trident
     * on every kind of purge, in both directions.
     */
    #[DataProvider('storeModes')]
    public function testEveryPurgeSendsTheStoresModeExplicitly(bool $soft, string $mode): void
    {
        $this->soft = $soft;
        $this->http->answer('edge-1', 200, '{"purged":1,"mode":"' . $mode . '"}');
        $client = $this->client();

        $client->purgeTags(['cat_p_1']);
        $client->purgeUrl('/gear.html', 'shop.example');
        $client->purgePattern('/c/*');
        $client->purgeHost('shop.example');
        $client->purgeVary('X-Magento-Vary', 'abc');
        $client->purgeTagPattern('cat_*');

        $this->assertCount(6, $this->http->requests);
        foreach ($this->http->requests as $request) {
            $this->assertSame($mode, self::json($request['body'])['mode'] ?? null, $request['url']);
        }
    }

    public function testAUrlPurgeOnANamedHostSendsNoScheme(): void
    {
        $this->http->answer('edge-1', 200, '{"purged":1,"mode":"hard"}');
        $this->client()->purgeUrl('/gear.html?color=red', 'shop.example');

        $this->assertSame(
            ['url' => '/gear.html?color=red', 'host' => 'shop.example', 'mode' => 'hard'],
            self::json($this->only()['body'])
        );
    }

    public function testPurgeCountsAreWhatTridentReported(): void
    {
        $this->twoEdges();
        $this->http->answer('edge-1', 200, '{"purged":3,"mode":"hard"}')
            ->answer('edge-2', 200, '{"purged":4,"mode":"hard"}');
        $client = $this->client();

        $this->assertSame(7, $client->purgedCount((array) $client->purgeTags(['cat_p_1'])), 'summed over instances');
        $this->assertSame(12, $client->purgedCount(['cleared' => true, 'entries_removed' => 12]));
        $this->assertNull($client->purgedCount(['status' => 'deferred', 'state' => 'recorded']), 'a deferred purge said nothing');
    }

    public function testDenoiserPinsSendTheClassAndStatusTheEngineRequires(): void
    {
        $this->http->answer('edge-1', 200, '{"pinned":true}');
        $client = $this->client();

        $client->denoiserQueryPin('utm_x', 'noise', 'shop.example:8080', '/c/');
        $client->denoiserPathPin('shop.example:8080', '/old/', 'dead');

        $this->assertSame(
            ['param' => 'utm_x', 'class' => 'noise', 'host' => 'shop.example:8080', 'path_prefix' => '/c/'],
            self::json($this->http->requests[0]['body'])
        );
        $this->assertSame(
            ['status' => 'dead', 'host' => 'shop.example:8080', 'path_prefix' => '/old/'],
            self::json($this->http->requests[1]['body'])
        );
    }

    /**
     * Input the library refuses to send is the form's error to show, not an
     * admin API failure hidden behind "check the logs".
     */
    public function testAnInvalidPinIsRefusedBeforeAnythingIsSent(): void
    {
        $client = $this->client();

        try {
            $client->denoiserPathPin('shop.example', '/old/', 'zombie');
            $this->fail('an invalid status must be refused');
        } catch (\Qoliber\Trident\Exception\InvalidRequest $e) {
            $this->assertStringContainsString('POST /admin/denoisers/path/pin', (string) $client->lastFailure());
        }
        $this->assertSame([], $this->http->requests);
    }

    public function testTheWafExportIsTheLibrarysAnswerAsSent(): void
    {
        $this->http->answer('edge-1', 200, '{"format":"trident-waf-v1","dead_zones":[{"zone_key":"*|/old/","action":"deny"}]}');

        $this->assertSame(
            ['format' => 'trident-waf-v1', 'dead_zones' => [['zone_key' => '*|/old/', 'action' => 'deny']]],
            $this->client()->getDenoiserWafExport()
        );
        $this->assertSame('http://edge-1:9301/admin/denoisers/export/waf', $this->only()['url']);
    }

    public function testTypedReadsKeepFieldsTheLibraryDoesNotModel(): void
    {
        $this->http->answer('edge-1', 200, '{"entries":7,"hits":3,"misses":1,"hit_ratio":75.0,"new_engine_field":{"x":1}}');

        $this->assertSame(
            ['entries' => 7, 'hits' => 3, 'misses' => 1, 'hit_ratio' => 75.0, 'new_engine_field' => ['x' => 1]],
            $this->client()->getStats()
        );
    }

    // ---- review of #7 -------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function inertHosts(): array
    {
        return ['star' => ['*'], 'empty' => [''], 'star with spaces' => [' * ']];
    }

    /**
     * Trident keys a scope and a zone on the literal "host|prefix" and looks
     * them up with the request's real host, with no "*" fallback: a pin on "*"
     * is never consulted. It is refused, with nothing sent.
     */
    #[DataProvider('inertHosts')]
    public function testAPinOnAHostTridentNeverMatchesIsRefused(string $host): void
    {
        $client = $this->client();
        foreach ([
            fn () => $client->denoiserQueryPin('utm_x', 'noise', $host, '/gear.html'),
            fn () => $client->denoiserPathPin($host, '/old/', 'dead'),
        ] as $pin) {
            try {
                $pin();
                $this->fail('a pin on "' . $host . '" must be refused');
            } catch (\Qoliber\Trident\Exception\InvalidRequest $e) {
                $this->assertStringContainsString("request's host", $e->getMessage());
            }
        }
        $this->assertSame([], $this->http->requests);
    }

    /** Unpinning "*" stays possible: it removes the inert scopes old pins created. */
    public function testAnUnpinMayStillNameTheStarScope(): void
    {
        $this->http->answer('edge-1', 200, '{"unpinned":true}');
        $client = $this->client();

        $client->denoiserQueryUnpin('utm_x', '/', '*');
        $client->denoiserQueryUnpin('utm_x', '/gear.html', 'localhost:8380');

        $this->assertSame('*', self::json($this->http->requests[0]['body'])['host']);
        $this->assertSame('localhost:8380', self::json($this->http->requests[1]['body'])['host']);
    }

    /**
     * A total only when every instance reported a count: one instance's count
     * is not the total, and a purge Reflect mode deferred has purged nothing.
     */
    public function testAFanOutWithADeferredInstanceShowsNoPartialTotal(): void
    {
        $this->twoEdges();
        $this->http->answer('edge-1', 200, '{"purged":3,"mode":"hard"}')
            ->answer('edge-2', 202, '{"status":"deferred","state":"recorded"}');
        $client = $this->client();

        $answer = (array) $client->purgeTags(['cat_p_1']);

        $this->assertArrayNotHasKey('purged', $answer, 'not edge-1\'s 3 presented as the total');
        $this->assertNull($client->purgedCount($answer));
        $this->assertSame('applied on 1 of 2 instances (1 deferred by Reflect mode)', $client->describePurge($answer));
    }

    public function testASoftPurgeIsDescribedAsMarkingStaleNotRemoving(): void
    {
        $client = $this->client();
        $this->assertSame('3 entries purged', $client->describePurge(['purged' => 3, 'mode' => 'hard']));

        $this->soft = true;
        $this->assertStringContainsString('marked stale', $this->client()->describePurge(['purged' => 3, 'mode' => 'soft']));
        $this->assertSame('12 entries removed', $client->describePurge(['cleared' => true, 'entries_removed' => 12]));
        $this->assertSame(
            'deferred by Reflect mode, applied when it ends',
            $client->describePurge(['status' => 'deferred', 'state' => 'recorded'])
        );
    }
}
