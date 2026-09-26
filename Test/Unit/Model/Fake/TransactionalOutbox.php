<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model\Fake;

use Qoliber\Trident\Delivery\Backoff;
use Qoliber\Trident\Delivery\OutboxEntry;
use Qoliber\TridentCache\Model\Outbox\DbPurgeOutbox;
use Qoliber\TridentCache\Model\Outbox\PurgeOutboxInterface;

/**
 * An outbox with InnoDB's visibility rules, for unit tests: a row written
 * while `$inTransaction()` is true is invisible until `commit()` and gone on
 * `rollBack()`. Ids are allocated at write time, like AUTO_INCREMENT — so a
 * row can carry a LOWER id than a row committed before it. Due times,
 * attempts and backoff follow the library's Backoff, as DbPurgeOutbox does.
 */
class TransactionalOutbox implements PurgeOutboxInterface
{
    /**
     * Committed rows by id.
     *
     * @var array<int, array{kind: string, instance: ?string, tags: list<string>, attempts: int,
     *     created_at: int, next_attempt_at: int, last_error: ?string, last_error_at: ?int}>
     */
    public array $rows = [];

    /** @var array<int|string, array<string, mixed>> written in open transactions */
    private array $uncommitted = [];

    /** @var list<string> every failure reason recorded, in order */
    public array $failures = [];

    public bool $broken = false;

    /** Match instance names the way the column's collation would without BINARY. */
    public bool $caseInsensitive = false;

    private int $nextId = 1;

    /** @var \Closure(): bool */
    private \Closure $inTransaction;

    public function __construct(callable $inTransaction)
    {
        $this->inTransaction = \Closure::fromCallable($inTransaction);
    }

    public function record(string $instance, array $tags, int $now, int $dueAt): int
    {
        return $this->write(DbPurgeOutbox::KIND_TAGS, $tags, $instance, $now, $dueAt);
    }

    public function recordClear(string $instance, int $now): int
    {
        return $this->write(DbPurgeOutbox::KIND_ALL, [], $instance, $now, $now);
    }

    /**
     * A committed row as the module wrote it (tests seed with this). A null
     * instance is a row written before X03.
     *
     * @param list<string> $tags
     */
    public function seed(string $kind, array $tags, ?string $instance = 'default', int $at = 0): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = self::row($kind, $tags, $instance, $at, $at);
        return $id;
    }

    /** Reserve an id for another connection's still-open write. */
    public function reserveForOtherTransaction(string $kind, array $tags, ?string $instance = 'default'): int
    {
        $id = $this->nextId++;
        $this->uncommitted['other:' . $id] = self::row($kind, $tags, $instance, 0, 0);
        return $id;
    }

    public function commitOther(int $id): void
    {
        $this->rows[$id] = $this->uncommitted['other:' . $id];
        unset($this->uncommitted['other:' . $id]);
        ksort($this->rows);
    }

    public function commit(): void
    {
        foreach ($this->uncommitted as $key => $row) {
            if (is_int($key)) {
                $this->rows[$key] = $row;
                unset($this->uncommitted[$key]);
            }
        }
        ksort($this->rows);
    }

    public function rollBack(): void
    {
        $this->uncommitted = array_filter($this->uncommitted, fn ($k): bool => !is_int($k), ARRAY_FILTER_USE_KEY);
    }

    public function byIds(array $ids): array
    {
        return $this->entries(array_intersect_key($this->rows, array_flip($ids)), DbPurgeOutbox::KIND_TAGS);
    }

    public function due(int $limit, int $now, bool $ignoreBackoff, array $instances): array
    {
        return $this->dueOf(DbPurgeOutbox::KIND_TAGS, $limit, $now, $ignoreBackoff, $instances);
    }

    public function dueClears(int $limit, int $now, bool $ignoreBackoff, array $instances): array
    {
        return $this->dueOf(DbPurgeOutbox::KIND_ALL, $limit, $now, $ignoreBackoff, $instances);
    }

    public function remove(array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->rows[$id]);
        }
    }

    public function fail(array $entries, string $reason, int $now): void
    {
        $this->failures[] = $reason;
        foreach ($entries as $entry) {
            if (!isset($this->rows[$entry->id])) {
                continue;
            }
            $failures = $entry->attempts + 1;
            $this->rows[$entry->id]['attempts'] = $failures;
            $this->rows[$entry->id]['next_attempt_at'] = Backoff::nextAttemptAt($failures, $now);
            $this->rows[$entry->id]['last_error'] = $reason;
            $this->rows[$entry->id]['last_error_at'] = $now;
        }
    }

    public function forget(string $instance): int
    {
        $before = count($this->rows);
        $this->rows = array_filter($this->rows, fn (array $r): bool => $r['instance'] !== $instance);
        return $before - count($this->rows);
    }

    public function splitLegacy(int $limit, array $instances): int
    {
        if ($this->broken) {
            throw new \RuntimeException('Base table or view not found');
        }
        $legacy = array_slice(array_filter($this->rows, fn (array $r): bool => $r['instance'] === null), 0, $limit, true);
        foreach ($legacy as $id => $row) {
            foreach ($instances as $instance) {
                $this->rows[$this->nextId++] = ['instance' => $instance] + $row;
            }
            unset($this->rows[$id]);
        }
        return count($legacy);
    }

    public function stats(int $now): array
    {
        $by = [];
        foreach ($this->rows as $row) {
            $key = $row['instance'] ?? '';
            $by[$key] = ($by[$key] ?? 0) + 1;
        }
        ksort($by);
        return [
            'pending' => count($this->rows),
            'oldest_age' => $this->rows === [] ? null : $now - min(array_column($this->rows, 'created_at')),
            'last_error' => $this->failures === [] ? null : end($this->failures),
            'last_error_at' => null,
            'by_instance' => $by,
        ];
    }

    /**
     * @return list<array{id: int, kind: string, instance: ?string, tags: list<string>, attempts: int}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->rows as $id => $row) {
            $out[] = [
                'id' => $id,
                'kind' => $row['kind'],
                'instance' => $row['instance'],
                'tags' => $row['tags'],
                'attempts' => $row['attempts'],
            ];
        }
        return $out;
    }

    /**
     * @param list<string> $tags
     */
    private function write(string $kind, array $tags, string $instance, int $now, int $dueAt): int
    {
        if ($this->broken) {
            throw new \RuntimeException('Base table or view not found: qoliber_trident_purge_outbox');
        }
        $id = $this->nextId++;
        $row = self::row($kind, array_values($tags), $instance, $now, $dueAt);
        if (($this->inTransaction)()) {
            $this->uncommitted[$id] = $row;
        } else {
            $this->rows[$id] = $row;
        }
        return $id;
    }

    /**
     * @param list<string> $instances
     * @return list<OutboxEntry>
     */
    private function dueOf(string $kind, int $limit, int $now, bool $ignoreBackoff, array $instances): array
    {
        if ($this->broken) {
            throw new \RuntimeException('Base table or view not found');
        }
        $names = $this->caseInsensitive ? array_map('strtolower', $instances) : $instances;
        $due = array_filter($this->rows, function (array $r) use ($names, $now, $ignoreBackoff): bool {
            if ($r['instance'] === null) {
                return false;
            }
            $name = $this->caseInsensitive ? strtolower($r['instance']) : $r['instance'];
            return in_array($name, $names, true)
                && ($r['next_attempt_at'] <= $now || ($ignoreBackoff && $r['attempts'] > 0));
        });
        return array_slice($this->entries($due, $kind), 0, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<OutboxEntry>
     */
    private function entries(array $rows, string $kind): array
    {
        ksort($rows);
        $out = [];
        foreach ($rows as $id => $row) {
            if ($row['kind'] === $kind && $row['instance'] !== null) {
                $out[] = new OutboxEntry($id, $row['instance'], $row['tags'], $row['attempts']);
            }
        }
        return $out;
    }

    /**
     * @param list<string> $tags
     * @return array{kind: string, instance: ?string, tags: list<string>, attempts: int,
     *     created_at: int, next_attempt_at: int, last_error: ?string, last_error_at: ?int}
     */
    private static function row(string $kind, array $tags, ?string $instance, int $now, int $dueAt): array
    {
        return [
            'kind' => $kind,
            'instance' => $instance,
            'tags' => array_values($tags),
            'attempts' => 0,
            'created_at' => $now,
            'next_attempt_at' => $dueAt,
            'last_error' => null,
            'last_error_at' => null,
        ];
    }
}
