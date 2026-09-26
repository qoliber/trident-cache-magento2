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

/**
 * Unix time for the purge outbox — injectable, so a test can step through a
 * backoff schedule without waiting it out.
 */
class Clock
{
    /**
     * @return int
     */
    public function now(): int
    {
        return time();
    }
}
