<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\HttpTransport;

/**
 * Review #7: the proxy is what libcurl (and so the module before) used — the
 * lowercase variables — never Guzzle's uppercase ones, which under CGI can be
 * set by a request header ("httpoxy") and would route the admin token away.
 */
class HttpTransportTest extends TestCase
{
    private const VARIABLES = ['HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'no_proxy'];

    /** @var array<string, string|false> */
    private array $saved = [];

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

    public function testUppercaseProxyVariablesAreIgnored(): void
    {
        putenv('HTTP_PROXY=http://attacker:8080');
        putenv('HTTPS_PROXY=http://attacker:8080');

        $this->assertSame([], HttpTransport::proxy());
        $this->assertSame([], (new HttpTransport())->options()['proxy'], 'explicit, so Guzzle adds none of its own');
    }

    public function testLowercaseProxyVariablesAreHonoured(): void
    {
        putenv('http_proxy=http://proxy:3128');
        putenv('https_proxy=http://proxy:3129');
        putenv('no_proxy=trident, localhost ,');

        $this->assertSame(
            ['http' => 'http://proxy:3128', 'https' => 'http://proxy:3129', 'no' => ['trident', 'localhost']],
            HttpTransport::proxy()
        );
    }

    public function testTimeoutsAreConfigurable(): void
    {
        $options = (new HttpTransport(2, 1))->options();

        $this->assertSame([2.0, 1.0], [$options['timeout'], $options['connect_timeout']]);
    }
}
