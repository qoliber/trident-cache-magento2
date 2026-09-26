<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Outbox;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Qoliber\Trident\Delivery\OutboxEntry;
use Qoliber\TridentCache\Model\Outbox\DbPurgeOutbox;

/**
 * The outbox's SQL against the store's real database: what the unit tests'
 * in-memory double cannot prove — TIMESTAMP round trips through
 * FROM_UNIXTIME/UNIX_TIMESTAMP, the BINARY name match on a case-insensitive
 * column, the legacy split's INSERT … SELECT, and the stats queries.
 *
 * Runs inside an installed Magento with its database (tests/magento-e2e);
 * skipped anywhere else. It works on the live table, so it refuses to run
 * while real purges are pending.
 */
class DbPurgeOutboxTest extends TestCase
{
    private DbPurgeOutbox $store;
    private AdapterInterface $db;
    private string $table;
    private int $now;

    protected function setUp(): void
    {
        // Test/Integration/Outbox → the Magento root (app/code/Qoliber/TridentCache/…).
        $root = dirname(__DIR__, 7);
        if (!is_file($root . '/app/etc/env.php') || !class_exists(Bootstrap::class)) {
            $this->markTestSkipped('Needs an installed Magento with its database.');
        }
        require_once $root . '/app/bootstrap.php';
        $objectManager = Bootstrap::create($root, $_SERVER)->getObjectManager();
        $resource = $objectManager->get(ResourceConnection::class);
        $this->db = $resource->getConnection();
        $this->table = $resource->getTableName(DbPurgeOutbox::TABLE);
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->table) > 0) {
            $this->markTestSkipped('Real purges are pending in ' . $this->table . '; not touching them.');
        }
        $this->store = new DbPurgeOutbox($resource);
        $this->now = time();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->delete($this->table);
        }
    }

    /**
     * @param list<OutboxEntry> $entries
     * @return list<int>
     */
    private static function ids(array $entries): array
    {
        return array_map(fn (OutboxEntry $e): int => $e->id, $entries);
    }

    public function testRowsRoundTripAndInstanceNamesAreExact(): void
    {
        $a = $this->store->record('Edge-1', ['cat_p_1', '123'], $this->now, $this->now);
        $b = $this->store->record('edge-1', ['x'], $this->now, $this->now + 60);
        $clear = $this->store->recordClear('edge-1', $this->now);

        $this->assertSame([], $this->store->due(10, $this->now, false, ['edge-1']), '"Edge-1" is not "edge-1"; b is not due yet');
        $due = $this->store->due(10, $this->now + 60, false, ['edge-1', 'Edge-1']);
        $this->assertSame([$a, $b], self::ids($due), 'tag rows only, oldest first');
        $this->assertSame(['cat_p_1', '123'], $due[0]->tags);
        $this->assertSame('Edge-1', $due[0]->instance);
        $this->assertSame([$clear], self::ids($this->store->dueClears(10, $this->now, false, ['edge-1'])));
        $this->assertSame([$b], self::ids($this->store->byIds([$b, $clear, 999999])));
    }

    public function testAFailedRowBacksOffAndKeepsItsReason(): void
    {
        $a = $this->store->record('edge-1', ['cat_p_1'], $this->now, $this->now);
        $this->store->fail($this->store->due(10, $this->now, false, ['edge-1']), 'HTTP 503 — unavailable', $this->now);

        $this->assertSame([], $this->store->due(10, $this->now, false, ['edge-1']), 'backing off');
        $this->assertSame([$a], self::ids($this->store->due(10, $this->now, true, ['edge-1'])), 'operator drain');
        $this->assertSame([$a], self::ids($this->store->due(10, $this->now + 1, false, ['edge-1'])), 'due after Backoff::delay(1)');
        $row = $this->db->fetchRow(
            'SELECT attempts, last_error, UNIX_TIMESTAMP(next_attempt_at) AS n, UNIX_TIMESTAMP(last_error_at) AS e FROM '
            . $this->table . ' WHERE entity_id = ' . $a
        );
        $this->assertSame(
            ['attempts' => '1', 'last_error' => 'HTTP 503 — unavailable', 'n' => (string) ($this->now + 1), 'e' => (string) $this->now],
            $row
        );
        $stats = $this->store->stats($this->now + 5);
        $this->assertSame(1, $stats['pending']);
        $this->assertSame(5, $stats['oldest_age']);
        $this->assertSame('HTTP 503 — unavailable', $stats['last_error']);
        $this->assertSame($this->now, $stats['last_error_at']);
    }

    public function testAnOperatorDrainNeverTakesARowBeforeItIsDue(): void
    {
        $this->store->record('edge-1', ['cat_p_1'], $this->now, $this->now + 60);

        $this->assertSame([], $this->store->due(10, $this->now, true, ['edge-1']));
    }

    public function testLegacyRowsAreSplitWithinTheLimitKeepingTheirHistory(): void
    {
        $this->db->insert($this->table, ['kind' => 'tags', 'tags' => '["legacy"]', 'instance' => null, 'attempts' => 2]);
        $this->db->insert($this->table, ['kind' => 'all', 'tags' => '[]', 'instance' => null]);

        $this->assertSame(1, $this->store->splitLegacy(1, ['e1', 'e2']));
        $this->assertSame(
            ['e1:tags:2', 'e2:tags:2'],
            $this->db->fetchCol('SELECT CONCAT(instance, ":", kind, ":", attempts) FROM ' . $this->table . ' WHERE instance IS NOT NULL ORDER BY entity_id')
        );

        $this->store->splitLegacy(10, ['e1', 'e2']);
        $this->assertSame('0', $this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->table . ' WHERE instance IS NULL'));
        $this->assertCount(1, $this->store->dueClears(10, $this->now + 1000, false, ['e2']));
    }

    public function testStatsAndForgetAreCaseSensitive(): void
    {
        $this->store->record('Edge-1', ['a'], $this->now, $this->now);
        $this->store->record('edge-1', ['b'], $this->now, $this->now);
        $this->store->recordClear('edge-1', $this->now);

        $this->assertSame(['Edge-1' => 1, 'edge-1' => 2], $this->store->stats($this->now)['by_instance']);
        $this->assertSame(0, $this->store->forget('EDGE-1'));
        $this->assertSame(2, $this->store->forget('edge-1'));
        $this->store->remove(self::ids($this->store->due(10, $this->now, false, ['Edge-1'])));
        $this->assertSame(0, $this->store->stats($this->now)['pending']);
    }
}
