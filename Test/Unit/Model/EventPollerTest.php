<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Qoliber\Trident\Delivery\Instance;
use Qoliber\TridentCache\Model\EventPoller;

class EventPollerTest extends TestCase
{
    /**
     * Review #3: the curl handler (allow_url_fopen off) does not stream; only
     * an overall timeout ends a poll of an endless SSE stream.
     */
    public function testAPollHasAnOverallTimeoutAndAsksForTheEventStream(): void
    {
        $options = (new EventPoller())->options(new Instance('edge-1', 'http://edge-1:9301', 'token'), 2.0);

        $this->assertSame(3.0, $options['timeout']);
        $this->assertSame('text/event-stream', $options['headers']['Accept'], 'not the JSON Accept of the library');
        $this->assertSame('Bearer token', $options['headers']['Authorization']);
        $this->assertArrayHasKey('proxy', $options);
    }

    public function testDataLinesAreDecodedAndACutOffLineIsDropped(): void
    {
        $events = (new EventPoller())->parse("event: request\ndata: {\"url\":\"/a\"}\n\ndata: plain\n\ndata: {\"url\":\"/b");

        $this->assertSame([['url' => '/a'], 'plain'], $events);
    }
}
