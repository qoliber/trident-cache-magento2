<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qoliber\Trident\Delivery\Instance;
use Qoliber\TridentCache\Model\EventPoller;
use Qoliber\TridentCache\Test\Unit\Model\Fake\LocalServer;

/**
 * Against a real SSE stream that stays open, as Trident's does: a poll ends
 * on its timeout and returns the events that arrived — on libcurl, whatever
 * `allow_url_fopen` says. Each poll runs in its own PHP process, because
 * `allow_url_fopen` cannot be changed at run time.
 */
class EventPollerTest extends TestCase
{
    private static ?LocalServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new LocalServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server = null;
    }

    /**
     * @return array{events: list<mixed>, error: ?string, seconds: float}
     */
    private static function pollIn(string $allowUrlFopen, string $stream, float $window): array
    {
        $module = dirname(__DIR__, 3);
        $autoload = self::autoload();
        $script = sprintf(
            '<?php require %s; require %s; require %s; $t = microtime(true);'
            . ' $r = (new Qoliber\TridentCache\Model\EventPoller())->poll(new Qoliber\Trident\Delivery\Instance("e", %s, "token"), %s, %F);'
            . ' $r["seconds"] = microtime(true) - $t; echo json_encode($r);',
            var_export($autoload, true),
            var_export($module . '/Model/HttpTransport.php', true),
            var_export($module . '/Model/EventPoller.php', true),
            var_export(self::$server->url(), true),
            var_export($stream, true),
            $window
        );
        $file = tempnam(sys_get_temp_dir(), 'poll');
        file_put_contents($file, $script);
        // A separate PHP process: allow_url_fopen is fixed at start-up.
        // phpcs:ignore Magento2.Security.InsecureFunction
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d allow_url_fopen=' . $allowUrlFopen . ' ' . escapeshellarg($file));
        unlink($file);
        $result = json_decode((string) $out, true);
        self::assertIsArray($result, 'poll output: ' . $out);
        return $result;
    }

    private static function autoload(): string
    {
        $loader = (string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        return dirname($loader, 2) . '/autoload.php';
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowUrlFopen(): array
    {
        return ['allow_url_fopen on' => ['1'], 'allow_url_fopen off' => ['0']];
    }

    /**
     * Review (5a882ae): on the curl path the events were lost — Guzzle closed
     * the sink with the timed-out transfer, and the poll returned nothing.
     */
    #[DataProvider('allowUrlFopen')]
    public function testEventsThatArrivedBeforeTheTimeoutAreReturned(string $allowUrlFopen): void
    {
        $result = self::pollIn($allowUrlFopen, 'requests', 1.0);

        $this->assertSame([['url' => '/a'], ['url' => '/b'], ['url' => '/c']], $result['events'], 'the cut-off fourth is dropped');
        $this->assertNull($result['error']);
        $this->assertLessThan(2.5, $result['seconds'], 'ends on the timeout, not when the server hangs up');
    }

    public function testAnErrorAnswerIsReportedNotParsed(): void
    {
        $result = self::pollIn('1', 'denied', 1.0);

        $this->assertSame([], $result['events']);
        $this->assertSame('HTTP 401', $result['error']);
    }

    public function testAPollAsksForTheEventStreamWithTheToken(): void
    {
        $options = (new EventPoller())->options(new Instance('edge-1', 'http://edge-1:9301', 'token'), 2.0);

        $this->assertSame(2.0, $options['timeout']);
        $this->assertSame('text/event-stream', $options['headers']['Accept'], 'not the JSON Accept of the library');
        $this->assertSame('Bearer token', $options['headers']['Authorization']);
    }

    public function testOnlyCompleteEventsAreParsed(): void
    {
        $events = (new EventPoller())->parse("event: request\ndata: {\"url\":\"/a\"}\n\ndata: plain\r\n\r\ndata: {\"url\":\"/b\"}\n");

        $this->assertSame([['url' => '/a'], 'plain'], $events, 'the last event has no blank line yet');
    }
}
