<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model\Fake;

use Qoliber\TridentCache\Model\Clock;

/**
 * Time that moves only when a test says so.
 */
class FixedClock extends Clock
{
    public function __construct(public int $now = 1_800_000_000)
    {
    }

    public function now(): int
    {
        return $this->now;
    }
}
