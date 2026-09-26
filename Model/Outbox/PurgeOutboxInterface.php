<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Model\Outbox;

use Qoliber\Trident\Delivery\OutboxEntry;
use Qoliber\Trident\Delivery\OutboxStore;

/**
 * X02: committed invalidation intent, kept until Trident acknowledges it.
 *
 * The tag rows are qoliber/trident-php's {@see OutboxStore} — the contract
 * every Trident platform integration implements, and the one its Drainer
 * delivers, retries and backs off. Writes go through the connection the
 * entity was saved with, so inside a transaction the intent commits — or
 * rolls back — WITH the data. That is the point: a purge recorded only after
 * the commit is lost if the process dies between the two, and one recorded
 * before the commit is sent for data nobody can read yet.
 *
 * Two things only this module has sit on top:
 *
 * - a queued FULL CLEAR (cache:flush, "Flush Magento Cache"), durable like a
 *   tag purge and owed to each instance;
 * - rows written before X03 (instance NULL), owed to every instance and split
 *   among them before they are delivered.
 *
 * `record()` rows are due at once (`$dueAt = $now`): Magento saves inside one
 * transaction, so a row is invisible to every other drainer until the save
 * committed — the grace period other platforms need does not apply.
 */
interface PurgeOutboxInterface extends OutboxStore
{
    /**
     * Record a full clear owed to one instance.
     *
     * @param string $instance
     * @param int $now Unix time.
     * @return int Row id.
     * @throws \Throwable When the intent could not be recorded.
     */
    public function recordClear(string $instance, int $now): int;

    /**
     * Full clears whose next attempt is due, oldest first — the counterpart of
     * {@see OutboxStore::due()}, which returns tag rows only. Entries carry no
     * tags.
     *
     * @param int $limit
     * @param int $now
     * @param bool $ignoreBackoff
     * @param list<string> $instances Exact match.
     * @return list<OutboxEntry>
     */
    public function dueClears(int $limit, int $now, bool $ignoreBackoff, array $instances): array;

    /**
     * X03: hand at most `$limit` rows written before X03 (owed to every
     * instance) to each of `$instances`, keeping their kind, age, attempts,
     * backoff and last error, and drop the originals. Bounded, so a drain's
     * work stays bounded even when X02 left thousands behind.
     *
     * @param int $limit
     * @param list<string> $instances
     * @return int Original rows split.
     */
    public function splitLegacy(int $limit, array $instances): int;
}
