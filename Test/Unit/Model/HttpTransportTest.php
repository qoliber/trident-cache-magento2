<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\HttpTransport;
use Qoliber\TridentCache\Test\Unit\Model\Fake\LocalServer;

/**
 * Proxy: libcurl's own rules, as the module had through Magento's Curl — so
 * lowercase `http_proxy` is used and uppercase `HTTP_PROXY` (httpoxy) is not.
 * Real requests to a local server: that lowercase `http_proxy` takes effect at
 * all shows the request went through libcurl (Guzzle's stream handler ignores
 * the environment), and that `HTTP_PROXY` does not shows Guzzle added no proxy
 * of its own.
 */
class HttpTransportTest extends TestCase
{
    private const VARIABLES = ['HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'no_proxy', 'all_proxy'];

    /** A proxy that refuses every connection. */
    private const DEAD_PROXY = 'http://127.0.0.1:9';

    private static ?LocalServer $server = null;

    /** @var array<string, string|false> */
    private array $saved = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = new LocalServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server = null;
    }

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    private function get(): int
    {
        return (new HttpTransport(3, 1))->request('GET', self::$server->url() . '/ok', [], null)['status'];
    }

    public function testWithoutAProxyTheRequestGoesStraightThrough(): void
    {
        $this->assertSame(200, $this->get());
    }

    public function testUppercaseHttpProxyIsNotUsed(): void
    {
        putenv('HTTP_PROXY=' . self::DEAD_PROXY);

        $this->assertSame(200, $this->get(), 'Guzzle would have sent it to the dead proxy');
    }

    public function testLowercaseHttpProxyIsUsed(): void
    {
        putenv('http_proxy=' . self::DEAD_PROXY);

        $this->assertSame(0, $this->get(), 'libcurl honours http_proxy: the dead proxy refuses');
    }

    public function testNoProxyExemptsAHost(): void
    {
        putenv('http_proxy=' . self::DEAD_PROXY);
        putenv('no_proxy=127.0.0.0/8');

        $this->assertSame(200, $this->get(), 'libcurl matches no_proxy, CIDR included');
    }

    public function testGuzzleIsGivenNoProxyAndTheConfiguredTimeouts(): void
    {
        $options = (new HttpTransport(2, 1))->options();

        $this->assertSame([], $options['proxy']);
        $this->assertSame([2.0, 1.0], [$options['timeout'], $options['connect_timeout']]);
    }
}
