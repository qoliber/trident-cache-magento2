<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Model;

use GuzzleHttp\Client;
use Qoliber\Trident\Admin\Api;
use Qoliber\Trident\Delivery\Instance;

/**
 * One poll of an admin SSE stream (`/admin/events/*`) for the Live Events
 * screen: read what arrives within a short window, then hang up.
 *
 * The engine keeps these streams open for as long as the client listens, so a
 * poll always ends on the deadline. What arrived before it is the answer —
 * Magento's Curl client threw it away with the timeout error, which is why the
 * screen used to show nothing but "Operation timed out".
 */
class EventPoller
{
    /** Longest wait for a single read, seconds — keeps the deadline honest. */
    private const READ_SLICE = 0.25;

    /**
     * @param Instance $instance
     * @param string $stream requests|cache|backends|errors
     * @param float $seconds How long to listen.
     * @return array{events: list<array<string, mixed>|string>, error: string|null}
     */
    public function poll(Instance $instance, string $stream, float $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        try {
            $response = (new Client([
                'connect_timeout' => min($seconds, HttpTransport::CONNECT_TIMEOUT),
                'read_timeout' => self::READ_SLICE,
                'http_errors' => false,
                'allow_redirects' => false,
            ]))->request('GET', $instance->apiUrl . '/admin/events/' . rawurlencode($stream), [
                'headers' => Api::headers($instance, false) + ['Accept' => 'text/event-stream'],
                'stream' => true,
            ]);
        } catch (\Throwable $e) {
            return ['events' => [], 'error' => $e->getMessage()];
        }
        if ($response->getStatusCode() !== 200) {
            return ['events' => [], 'error' => sprintf('HTTP %d', $response->getStatusCode())];
        }

        $body = $response->getBody();
        $buffer = '';
        while (microtime(true) < $deadline && !$body->eof()) {
            try {
                $buffer .= $body->read(8192);
            } catch (\Throwable $e) {
                break;
            }
        }
        $body->close();

        return ['events' => $this->parse($buffer), 'error' => null];
    }

    /**
     * The `data:` lines of an SSE buffer, decoded; a line cut off by the
     * deadline is dropped, not half-parsed.
     *
     * @param string $buffer
     * @return list<array<string, mixed>|string>
     */
    public function parse(string $buffer): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $buffer) ?: [];
        if ($buffer !== '' && !preg_match('/[\r\n]$/', $buffer)) {
            array_pop($lines);
        }
        $events = [];
        foreach ($lines as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = trim(substr($line, 5));
            if ($payload === '') {
                continue;
            }
            $decoded = json_decode($payload, true);
            $events[] = is_array($decoded) ? $decoded : $payload;
        }
        return $events;
    }
}
