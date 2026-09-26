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
use GuzzleHttp\Psr7\Utils;
use Qoliber\Trident\Admin\Api;
use Qoliber\Trident\Delivery\Instance;

/**
 * One poll of an admin SSE stream (`/admin/events/*`) for the Live Events
 * screen: listen for a short window, then hang up and return what arrived.
 *
 * The engine keeps these streams open for as long as the client listens, so a
 * poll always ends on its timeout. It runs on libcurl (like every request of
 * the module, {@see HttpTransport::options()}), which writes the body into a
 * sink as it arrives; the timeout then ends the transfer with an error and no
 * response. The sink is the poller's own, referenced outside the request, so
 * Guzzle cannot close it with the failed transfer: it is read back after the
 * timeout, complete events parsed and a cut-off tail dropped.
 */
class EventPoller
{
    /**
     * @param Instance $instance
     * @param string $stream requests|cache|backends|errors
     * @param float $seconds How long to listen.
     * @return array{events: list<array<string, mixed>|string>, error: string|null}
     */
    public function poll(Instance $instance, string $stream, float $seconds): array
    {
        // php://temp, owned here: see the class comment.
        $sink = Utils::streamFor('');
        $status = null;
        $error = null;
        try {
            $status = (new Client())->request(
                'GET',
                $instance->apiUrl . '/admin/events/' . rawurlencode($stream),
                $this->options($instance, $seconds) + ['sink' => $sink]
            )->getStatusCode();
        } catch (\Throwable $e) {
            // The timeout that ends every poll, or no connection at all: what
            // arrived before it is in the sink.
            $error = $e->getMessage();
        }
        $sink->rewind();
        $received = $sink->getContents();
        $sink->close();
        if ($status !== null && $status !== 200) {
            return ['events' => [], 'error' => sprintf('HTTP %d', $status)];
        }
        if ($received !== '') {
            return ['events' => $this->parse($received), 'error' => null];
        }
        return ['events' => [], 'error' => $error];
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
        $transport = new HttpTransport($seconds, min($seconds, HttpTransport::CONNECT_TIMEOUT));
        return array_merge($transport->options(), [
            'http_errors' => false,
            'allow_redirects' => false,
            // The stream's own Accept replaces the library's JSON one.
            'headers' => array_merge(Api::headers($instance, false), ['Accept' => 'text/event-stream']),
        ]);
    }

    /**
     * The `data:` lines of complete SSE events, decoded. An event is complete
     * once its blank line has arrived; the tail cut off by the timeout is
     * dropped, not half-parsed.
     *
     * @param string $buffer
     * @return list<array<string, mixed>|string>
     */
    public function parse(string $buffer): array
    {
        $buffer = str_replace(["\r\n", "\r"], "\n", $buffer);
        $end = strrpos($buffer, "\n\n");
        if ($end === false) {
            return [];
        }
        $events = [];
        foreach (explode("\n", substr($buffer, 0, $end)) as $line) {
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
