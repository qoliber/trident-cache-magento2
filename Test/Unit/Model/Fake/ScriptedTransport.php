<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model\Fake;

use Qoliber\Trident\Delivery\Transport;
use Qoliber\Trident\Testing\FakeTransport;

/**
 * A Trident admin API per host, for delivery tests: purges and clears are
 * acknowledged unless the test scripted otherwise (per host, in order), and
 * every request is recorded. Unlike the library's FakeTransport it answers by
 * PATH too — a clear needs `{"cleared":true}`, a purge the purge schema.
 */
class ScriptedTransport implements Transport
{
    public const CLEAR_ACK = '{"cleared":true,"entries_removed":1}';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var array<string, list<array{int, string}>> per host: answers still to give */
    private array $script = [];

    /** @var array<string, true> hosts that do not answer */
    private array $down = [];

    /** @var (\Closure(string $url): void)|null runs while a request is "on the wire" */
    public ?\Closure $whileSending = null;

    /**
     * The next requests to `$host` fail with this answer, once each.
     */
    public function refuse(
        string $host,
        int $times = 1,
        int $status = 503,
        string $body = '{"error":"unavailable"}'
    ): self {
        for ($i = 0; $i < $times; $i++) {
            $this->script[$host][] = [$status, $body];
        }
        return $this;
    }

    public function down(string $host): self
    {
        $this->down[$host] = true;
        return $this;
    }

    public function up(string $host): self
    {
        unset($this->down[$host]);
        return $this;
    }

    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->whileSending !== null) {
            ($this->whileSending)($url);
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (isset($this->down[$host])) {
            return ['status' => 0, 'body' => '', 'error' => 'cURL error 7: Failed to connect'];
        }
        if (!empty($this->script[$host])) {
            [$status, $answer] = array_shift($this->script[$host]);
            return ['status' => $status, 'body' => $answer, 'error' => null];
        }
        $ack = str_ends_with($url, '/admin/cache/clear') ? self::CLEAR_ACK : FakeTransport::ACK;
        return ['status' => 200, 'body' => $ack, 'error' => null];
    }

    /**
     * @return list<list<string>> The tags of each purge sent to `$host`.
     */
    public function purged(string $host = 'edge-1'): array
    {
        $out = [];
        foreach ($this->requests as $r) {
            if (parse_url($r['url'], PHP_URL_HOST) === $host && str_ends_with($r['url'], '/admin/purge/tags')) {
                $out[] = (array) (json_decode((string) $r['body'], true)['tags'] ?? []);
            }
        }
        return $out;
    }

    /** Full clears sent to `$host`. */
    public function clears(string $host = 'edge-1'): int
    {
        $n = 0;
        foreach ($this->requests as $r) {
            $n += (int) (parse_url($r['url'], PHP_URL_HOST) === $host
                && str_ends_with($r['url'], '/admin/cache/clear'));
        }
        return $n;
    }
}
