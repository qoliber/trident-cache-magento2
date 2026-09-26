<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Cron;

use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\Outbox\PurgeOutboxInterface;
use Qoliber\TridentCache\Model\Clock;
use Qoliber\TridentCache\Model\PurgeAfterCommit;

/**
 * X02: retry every purge Trident has not acknowledged yet.
 *
 * The commit that recorded a purge sends it at once; this job is what sends
 * it again after a refusal, a timeout or a dead process. If cron is not
 * running, nothing retries — which is why an old entry is logged as a
 * warning here and reported by `bin/magento trident:purge:status`.
 */
class DrainPurgeOutbox
{
    /** Entries per run. */
    private const LIMIT = 500;

    /** Oldest pending age, seconds, after which delivery is reported stuck. */
    public const STALE_AFTER = 900;

    /**
     * @param PurgeAfterCommit $purgeAfterCommit
     * @param PurgeOutboxInterface $outbox
     * @param LoggerInterface $logger
     * @param Clock $clock
     */
    public function __construct(
        private readonly PurgeAfterCommit $purgeAfterCommit,
        private readonly PurgeOutboxInterface $outbox,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->purgeAfterCommit->drain(self::LIMIT);
        $stats = $this->outbox->stats($this->clock->now());
        if (($stats['oldest_age'] ?? 0) > self::STALE_AFTER) {
            $this->logger->warning('Trident purges are not being acknowledged', $stats);
        }
    }
}
