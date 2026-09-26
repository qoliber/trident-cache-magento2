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
 * poll always ends on a deadline, and what arrived before it is the answer.
 * Guzzle streams the body only through its stream handler (allow_url_fopen);
 * its curl handler buffers until the transfer ends — never, for this stream —
 * so the request also carries an overall `timeout`, and a `sink` from which
 * what curl received is read back when that timeout ends the transfer.
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
        $sink = fopen('php://temp', 'w+');
        try {
            $response = (new Client())->request(
                'GET',
                $instance->apiUrl . '/admin/events/' . rawurlencode($stream),
                $this->options($instance, $seconds) + ['sink' => $sink]
            );
        } catch (\Throwable $e) {
            // The curl handler's timeout: what arrived is in the sink.
            $received = $this->drain($sink);
            return $received !== ''
                ? ['events' => $this->parse($received), 'error' => null]
                : ['events' => [], 'error' => $e->getMessage()];
        }
        if ($response->getStatusCode() !== 200) {
            $this->drain($sink);
            return ['events' => [], 'error' => sprintf('HTTP %d', $response->getStatusCode())];
        }

        $body = $response->getBody();
        $buffer = '';
        // Until the deadline: a quiet stream times out every read slice, which
        // PHP reports as end-of-file and Guzzle as a failed read — neither is
        // the end of the stream. Only a read that failed WITHOUT timing out is.
        while (microtime(true) < $deadline) {
            try {
                $buffer .= $body->read(8192);
            } catch (\Throwable $e) {
                if ($body->getMetadata('timed_out') !== true) {
                    break;
                }
                continue;
            }
            if ($body->eof() && $body->getMetadata('timed_out') !== true) {
                break;
            }
        }
        $body->close();
        $this->drain($sink);

        return ['events' => $this->parse($buffer), 'error' => null];
    }

    /**
     * The request options of one poll.
     *
     * @param Instance $instance
     * @param float $seconds
     * @return array<string, mixed>
     */
    public function options(Instance $instance, float $seconds): array
    {
        return [
            // The whole transfer: the only bound the curl handler has.
            'timeout' => $seconds + 1,
            'connect_timeout' => min($seconds, HttpTransport::CONNECT_TIMEOUT),
            'read_timeout' => self::READ_SLICE,
            'http_errors' => false,
            'allow_redirects' => false,
            'proxy' => HttpTransport::proxy(),
            // The stream's own Accept replaces the library's JSON one.
            'headers' => array_merge(Api::headers($instance, false), ['Accept' => 'text/event-stream']),
            'stream' => true,
        ];
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

    /**
     * Read and close the sink.
     *
     * @param resource|false $sink
     * @return string
     */
    private function drain($sink): string
    {
        if (!is_resource($sink)) {
            return '';
        }
        rewind($sink);
        $received = (string) stream_get_contents($sink);
        fclose($sink);
        return $received;
    }
}
