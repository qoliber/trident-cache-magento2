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

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Qoliber\Trident\Delivery\Backoff;
use Qoliber\Trident\Delivery\OutboxEntry;

/**
 * The outbox in `qoliber_trident_purge_outbox`, on the `default` connection —
 * the connection {@see \Qoliber\TridentCache\Model\PurgeAfterCommit} checks
 * for an open transaction, so the row shares the entity save's transaction.
 *
 * Times cross this class as Unix seconds (the library's clock) and are stored
 * in the TIMESTAMP columns through FROM_UNIXTIME()/UNIX_TIMESTAMP(), which
 * convert through the same session time zone both ways.
 *
 * Instance names are compared with BINARY: the column's collation is
 * case-insensitive, instance names are not — "Edge-1" must not be handed to
 * "edge-1".
 */
class DbPurgeOutbox implements PurgeOutboxInterface
{
    public const TABLE = 'qoliber_trident_purge_outbox';

    /** A purge by tags. */
    public const KIND_TAGS = 'tags';

    /** A full clear (POST /admin/cache/clear). */
    public const KIND_ALL = 'all';

    /** Longest `last_error` kept. */
    private const MAX_ERROR = 1000;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function record(string $instance, array $tags, int $now, int $dueAt): int
    {
        return $this->insert(self::KIND_TAGS, array_values($tags), $instance, $now, $dueAt);
    }

    /**
     * @inheritDoc
     */
    public function recordClear(string $instance, int $now): int
    {
        return $this->insert(self::KIND_ALL, [], $instance, $now, $now);
    }

    /**
     * @inheritDoc
     */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        return $this->entries(
            $this->select(self::KIND_TAGS)
                ->where('entity_id IN (?)', $ids)
                ->where('instance IS NOT NULL')
        );
    }

    /**
     * @inheritDoc
     */
    public function due(int $limit, int $now, bool $ignoreBackoff, array $instances): array
    {
        return $this->dueOf(self::KIND_TAGS, $limit, $now, $ignoreBackoff, $instances);
    }

    /**
     * @inheritDoc
     */
    public function dueClears(int $limit, int $now, bool $ignoreBackoff, array $instances): array
    {
        return $this->dueOf(self::KIND_ALL, $limit, $now, $ignoreBackoff, $instances);
    }

    /**
     * @inheritDoc
     */
    public function remove(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }
        $this->connection()->delete($this->table(), ['entity_id IN (?)' => $ids]);
    }

    /**
     * @inheritDoc
     */
    public function fail(array $entries, string $reason, int $now): void
    {
        // One UPDATE per attempt count: each entry keeps its own schedule.
        $byAttempts = [];
        foreach ($entries as $entry) {
            $byAttempts[$entry->attempts][] = $entry->id;
        }
        foreach ($byAttempts as $attempts => $ids) {
            $failures = (int) $attempts + 1;
            $this->connection()->update(
                $this->table(),
                [
                    'attempts' => $failures,
                    'next_attempt_at' => $this->at(Backoff::nextAttemptAt($failures, $now)),
                    'last_error' => mb_substr($reason, 0, self::MAX_ERROR),
                    'last_error_at' => $this->at($now),
                ],
                ['entity_id IN (?)' => $ids]
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function forget(string $instance): int
    {
        return (int) $this->connection()->delete($this->table(), ['BINARY instance = ?' => $instance]);
    }

    /**
     * @inheritDoc
     */
    public function splitLegacy(int $limit, array $instances): int
    {
        if ($instances === [] || $limit <= 0) {
            return 0;
        }
        $connection = $this->connection();
        $ids = array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->table(), ['entity_id'])
                ->where('instance IS NULL')
                ->order('entity_id ASC')
                ->limit($limit)
        ));
        if ($ids === []) {
            return 0;
        }
        $columns = ['kind', 'tags', 'attempts', 'created_at', 'next_attempt_at', 'last_error', 'last_error_at'];
        foreach ($instances as $instance) {
            $select = $connection->select()
                ->from($this->table(), $columns)
                ->columns(['instance' => new Expression($connection->quote($instance))])
                ->where('entity_id IN (?)', $ids)
                ->where('instance IS NULL')
                ->order('entity_id ASC');
            $connection->query($connection->insertFromSelect($select, $this->table(), [...$columns, 'instance']));
        }
        $connection->delete($this->table(), ['entity_id IN (?)' => $ids, 'instance IS NULL']);
        return count($ids);
    }

    /**
     * @inheritDoc
     */
    public function stats(int $now): array
    {
        $connection = $this->connection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->table(), [
                'pending' => new Expression('COUNT(*)'),
                'oldest' => new Expression('UNIX_TIMESTAMP(MIN(created_at))'),
            ])
        ) ?: [];
        $failure = $connection->fetchRow(
            $connection->select()
                ->from($this->table(), [
                    'last_error',
                    'last_error_at' => new Expression('UNIX_TIMESTAMP(last_error_at)'),
                ])
                ->where('last_error IS NOT NULL')
                ->order(['last_error_at DESC', 'entity_id DESC'])
                ->limit(1)
        ) ?: [];
        $byInstance = [];
        foreach ($connection->fetchPairs(
            $connection->select()->from($this->table(), [
                'instance' => new Expression("CAST(COALESCE(instance, '') AS BINARY)"),
                'pending' => new Expression('COUNT(*)'),
            ])->group(new Expression("CAST(COALESCE(instance, '') AS BINARY)"))
        ) as $instance => $count) {
            $byInstance[(string) $instance] = (int) $count;
        }
        ksort($byInstance);

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'oldest_age' => isset($row['oldest']) ? max(0, $now - (int) $row['oldest']) : null,
            'last_error' => isset($failure['last_error']) ? (string) $failure['last_error'] : null,
            'last_error_at' => isset($failure['last_error_at']) ? (int) $failure['last_error_at'] : null,
            'by_instance' => $byInstance,
        ];
    }

    /**
     * @param string $kind
     * @param list<string> $tags
     * @param string $instance
     * @param int $now
     * @param int $dueAt
     * @return int
     */
    private function insert(string $kind, array $tags, string $instance, int $now, int $dueAt): int
    {
        $connection = $this->connection();
        $connection->insert($this->table(), [
            'kind' => $kind,
            'tags' => (string) json_encode($tags),
            'instance' => $instance,
            'created_at' => $this->at($now),
            'next_attempt_at' => $this->at($dueAt),
        ]);
        return (int) $connection->lastInsertId($this->table());
    }

    /**
     * @param string $kind
     * @param int $limit
     * @param int $now
     * @param bool $ignoreBackoff
     * @param list<string> $instances
     * @return list<OutboxEntry>
     */
    private function dueOf(string $kind, int $limit, int $now, bool $ignoreBackoff, array $instances): array
    {
        if ($instances === [] || $limit <= 0) {
            return [];
        }
        $select = $this->select($kind)
            ->where('BINARY instance IN (?)', $instances)
            ->limit($limit);
        $due = $this->connection()->quoteInto('next_attempt_at <= ?', $this->at($now));
        // An operator's "deliver now" skips a FAILED row's backoff — never a
        // row still waiting to become due (attempts = 0).
        $select->where($ignoreBackoff ? '(attempts > 0 OR ' . $due . ')' : $due);
        return $this->entries($select);
    }

    /**
     * @param string $kind
     * @return Select
     */
    private function select(string $kind): Select
    {
        return $this->connection()->select()
            ->from($this->table(), ['entity_id', 'tags', 'attempts', 'instance'])
            ->where('kind = ?', $kind)
            ->order('entity_id ASC');
    }

    /**
     * @param Select $select
     * @return list<OutboxEntry>
     */
    private function entries(Select $select): array
    {
        $entries = [];
        foreach ($this->connection()->fetchAll($select) as $row) {
            $tags = json_decode((string) $row['tags'], true);
            $entries[] = new OutboxEntry(
                (int) $row['entity_id'],
                (string) $row['instance'],
                is_array($tags) ? array_values(array_map('strval', $tags)) : [],
                (int) $row['attempts']
            );
        }
        return $entries;
    }

    /**
     * A Unix time as a TIMESTAMP value.
     *
     * @param int $unix
     * @return Expression
     */
    private function at(int $unix): Expression
    {
        return new Expression('FROM_UNIXTIME(' . $unix . ')');
    }

    /**
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }

    /**
     * @return string
     */
    private function table(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
