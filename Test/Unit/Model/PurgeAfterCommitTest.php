<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\CallbackPool;
use Magento\Framework\Model\ExecuteCommitCallbacks;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qoliber\Trident\Delivery\Instance;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\Outbox\DbPurgeOutbox;
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Qoliber\TridentCache\Model\TridentClient;
use Qoliber\TridentCache\Test\Unit\Model\Fake\FixedClock;
use Qoliber\TridentCache\Test\Unit\Model\Fake\ScriptedTransport;
use Qoliber\TridentCache\Test\Unit\Model\Fake\TransactionalOutbox;

/**
 * Commits and rollbacks go through Magento's own `execute_commit_callbacks`
 * plugin rather than a copy of its loop, so a framework change that alters
 * when callbacks run breaks these tests instead of passing them. Delivery is
 * qoliber/trident-php's, over a scripted admin API: the assertions are the
 * requests Trident would receive.
 */
class PurgeAfterCommitTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private ResourceConnection&MockObject $resource;
    private Config&MockObject $config;
    private PurgeAfterCommit $purge;
    private ExecuteCommitCallbacks $commitCallbacks;
    private TransactionalOutbox $outbox;
    private ScriptedTransport $http;
    private FixedClock $clock;
    private int $level = 0;
    private bool $soft = false;
    private bool $trident = true;

    /** @var list<Instance> */
    private array $instances;

    protected function setUp(): void
    {
        $this->instances = [new Instance('default', 'http://edge-1:9301', 'token')];
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('getTransactionLevel')->willReturnCallback(fn (): int => $this->level);
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->config = $this->createMock(Config::class);
        $this->config->method('isTridentEnabled')->willReturnCallback(fn (): bool => $this->trident);
        $this->config->method('getInstances')->willReturnCallback(fn (): array => $this->instances);
        $this->config->method('isSoftPurgeEnabled')->willReturnCallback(fn (): bool => $this->soft);
        $this->config->method('getPurgeMode')->willReturnCallback(fn (): string => $this->soft ? 'soft' : 'hard');
        $this->outbox = new TransactionalOutbox(fn (): bool => $this->level > 0);
        $this->http = new ScriptedTransport();
        $this->clock = new FixedClock();
        $this->purge = $this->newProcess();
        $this->commitCallbacks = new ExecuteCommitCallbacks(new NullLogger());
        CallbackPool::clear(spl_object_hash($this->connection));
    }

    protected function tearDown(): void
    {
        CallbackPool::clear(spl_object_hash($this->connection));
    }

    public function testOutsideATransactionThePurgeGoesOutAtOnce(): void
    {
        $this->purge->purgeTags(['cat_p_1', 'cat_p']);

        $this->assertSame([['cat_p_1', 'cat_p']], $this->http->purged());
        $this->assertSame([], $this->outbox->rows, 'acknowledged, so removed');
    }

    /**
     * The defect this class exists for: the purge left while the save
     * transaction was still open, so Trident re-fetched the old page.
     */
    public function testInsideATransactionNothingIsSentBeforeTheCommit(): void
    {
        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);

        $this->assertSame([], $this->http->requests);
    }

    public function testAnInnerCommitSendsNothing(): void
    {
        $this->level = 2;
        $this->purge->purgeTags(['cat_p_1']);
        $this->commitTo(1);

        $this->assertSame([], $this->http->requests);
    }

    public function testTheOutermostCommitSendsThePendingTagsOnce(): void
    {
        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1', 'cat_p']);
        $this->purge->purgeTags(['cat_p', 'cat_c_3']);
        $this->assertSame([], $this->http->requests, 'held until the commit');

        $this->commitTo(0);

        $this->assertSame([['cat_p_1', 'cat_p', 'cat_c_3']], $this->http->purged(), 'one request, merged and deduplicated');
    }

    public function testATagThatLooksNumericStaysAString(): void
    {
        $this->level = 1;
        $this->purge->purgeTags(['123', 'cat_p_1']);
        $this->commitTo(0);

        $this->assertSame([['123', 'cat_p_1']], $this->http->purged());
        $this->assertStringContainsString('"tags":["123","cat_p_1"]', (string) $this->http->requests[0]['body']);
    }

    /**
     * Merging a whole transaction into one request must not produce a body the
     * admin API refuses with 413 — that would lose every purge of the commit.
     */
    public function testALargeTransactionIsSentInRequestsOfAtMostAThousandTags(): void
    {
        $tags = array_map(fn (int $i): string => "cat_p_$i", range(1, 2500));

        $this->level = 1;
        $this->purge->purgeTags($tags);
        $this->commitTo(0);

        $this->assertSame([1000, 1000, 500], array_map('count', $this->http->purged()));
        $this->assertSame($tags, array_merge(...$this->http->purged()), 'every tag sent exactly once, in order');
    }

    public function testOutsideATransactionALargePurgeIsChunkedToo(): void
    {
        $this->purge->purgeTags(array_map(fn (int $i): string => "cat_p_$i", range(1, 1001)));

        $this->assertSame([1000, 1], array_map('count', $this->http->purged()));
    }

    public function testAfterARollbackTheNextTransactionStillFlushes(): void
    {
        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->rollBack();

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_2']);
        $this->commitTo(0);

        $this->assertSame([['cat_p_2']], $this->http->purged(), 'the committed change is purged; the rolled-back one is not');
    }

    /**
     * The same tags again after a rollback — the retried save — must be
     * recorded again: the rolled-back rows are gone. (qoliber/trident-php's
     * Purger remembers what a process recorded to skip duplicates; that
     * memory would outlive the rollback and lose this purge.)
     */
    public function testARetriedSaveAfterARollbackIsPurged(): void
    {
        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->rollBack();

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->commitTo(0);

        $this->assertSame([['cat_p_1']], $this->http->purged());
    }

    public function testARollbackAloneSendsNothing(): void
    {
        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->rollBack();

        $this->assertSame([], $this->http->requests);
    }

    public function testPurgeAllInsideATransactionSupersedesPendingTags(): void
    {
        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->purge->purgeAll();
        $this->commitTo(0);

        $this->assertSame([], $this->http->purged());
        $this->assertSame(1, $this->http->clears());
        $this->assertSame([], $this->outbox->rows);
    }

    public function testPurgeAllOutsideATransactionGoesOutAtOnce(): void
    {
        $this->purge->purgeAll();

        $this->assertSame(1, $this->http->clears());
        $this->assertSame(['confirm' => true], json_decode((string) $this->http->requests[0]['body'], true));
    }

    public function testASecondFlushSendsNothing(): void
    {
        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->commitTo(0);
        $this->purge->flush();

        $this->assertCount(1, $this->http->requests);
    }

    /**
     * A config value is only readable after the configuration reloads, so its
     * purge must not go out with the commit — it would be spent re-storing the
     * old value, which is how the edge ended up one change behind on 2.4.x.
     */
    public function testConfigTagsAreNotSentByTheCommit(): void
    {
        $this->level = 1;
        $this->purge->purgeConfigTags(['robots_1']);
        $this->commitTo(0);

        $this->assertSame([], $this->http->requests);
    }

    public function testConfigTagsGoOutWhenTheConfigurationReloads(): void
    {
        $this->level = 1;
        $this->purge->purgeConfigTags(['robots_1']);
        $this->purge->purgeConfigTags(['robots_2']);
        $this->commitTo(0);

        $this->purge->flushConfigTags();

        $this->assertSame([['robots_1', 'robots_2']], $this->http->purged());
    }

    public function testASecondReloadSendsNothing(): void
    {
        $this->purge->purgeConfigTags(['robots_1']);
        $this->purge->flushConfigTags();
        $this->purge->flushConfigTags();

        $this->assertCount(1, $this->http->requests);
    }

    public function testPurgeAllSupersedesHeldConfigTags(): void
    {
        $this->level = 1;
        $this->purge->purgeConfigTags(['robots_1']);
        $this->purge->purgeAll();
        $this->commitTo(0);
        $this->purge->flushConfigTags();

        $this->assertSame([], $this->http->purged());
        $this->assertSame(1, $this->http->clears());
    }

    public function testSoftPurgeIsWhatTheEngineIsAskedFor(): void
    {
        $this->soft = true;
        $this->purge->purgeTags(['cat_p_1']);

        $this->assertSame('soft', json_decode((string) $this->http->requests[0]['body'], true)['mode']);
    }

    public function testWhenTridentIsNotTheCacheNothingIsRecorded(): void
    {
        $this->trident = false;
        $this->purge->purgeTags(['cat_p_1']);
        $this->purge->purgeAll();

        $this->assertSame([], $this->outbox->rows);
        $this->assertSame([], $this->http->requests);
    }

    // ---- X02: durable delivery ------------------------------------------

    /**
     * The defect: pending state was cleared before delivery was checked, so a
     * purge Trident refused (401 after a token rotation, 429, 503, timeout)
     * was simply gone. It must stay and go out with the next drain.
     */
    public function testAnUnacknowledgedPurgeIsKeptAndResentByTheNextDrain(): void
    {
        $this->http->refuse('edge-1');

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->commitTo(0);
        $this->assertCount(1, $this->outbox->rows, 'refused, so still pending');
        $this->assertSame(['HTTP 503 — unavailable'], $this->outbox->failures, 'and the reason is recorded');

        $this->clock->now += 1; // the backoff has passed
        $this->purge->drain(50);

        $this->assertSame([['cat_p_1'], ['cat_p_1']], $this->http->purged());
        $this->assertSame([], $this->outbox->rows, 'acknowledged, so removed');
    }

    /**
     * The transaction commits and the process dies before the commit
     * callback runs — the purge must survive in the database and be sent by
     * the next process (cron or the next commit).
     */
    public function testAPurgeSurvivesTheProcessDyingAfterTheCommit(): void
    {
        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->commitAndDie();
        $this->assertSame([], $this->http->requests, 'nothing was sent before the death');

        $this->newProcess()->drain(500);

        $this->assertSame([['cat_p_1']], $this->http->purged());
        $this->assertSame([], $this->outbox->rows);
    }

    /** Sent, then the process died before removing the entry: sent again. */
    public function testDyingBetweenSendAndRemoveCostsADuplicateNotALoss(): void
    {
        $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ['cat_p_1']);
        $this->http->whileSending = function (): void {
            $this->http->whileSending = null;
            throw new \LogicException('process killed with the request on the wire');
        };
        try {
            $this->newProcess()->drain(500);
            $this->fail('the process was meant to die');
        } catch (\LogicException $e) {
            $this->assertCount(1, $this->outbox->rows, 'sent, never removed');
        }

        $this->newProcess()->drain(500);

        $this->assertSame([['cat_p_1'], ['cat_p_1']], $this->http->purged(), 'idempotent re-delivery');
        $this->assertSame([], $this->outbox->rows);
    }

    public function testARollBackLeavesNoIntentBehind(): void
    {
        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->rollBack();
        $this->newProcess()->drain(500);

        $this->assertSame([], $this->outbox->rows);
        $this->assertSame([], $this->http->requests);
    }

    /**
     * A full clear supersedes only the entries its drain READ before sending
     * it. A row an open transaction wrote with a LOWER id, committed after
     * the clear went out, describes a change the clear never saw.
     */
    public function testAClearRemovesOnlyTheEntriesItRead(): void
    {
        $late = $this->outbox->reserveForOtherTransaction(DbPurgeOutbox::KIND_TAGS, ['cat_p_late']);
        $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ['cat_p_1']);
        $this->outbox->seed(DbPurgeOutbox::KIND_ALL, []);
        $this->http->whileSending = function (string $url) use ($late): void {
            if (str_ends_with($url, '/admin/cache/clear')) {
                // The other transaction commits while the clear is on the wire.
                $this->outbox->commitOther($late);
            }
        };

        $this->newProcess()->drain(500);
        $this->assertSame([], $this->http->purged(), 'cat_p_1 was covered by the clear');
        $this->assertArrayHasKey($late, $this->outbox->rows, 'the late row survives the clear');

        $this->newProcess()->drain(500);
        $this->assertSame([['cat_p_late']], $this->http->purged());
    }

    /**
     * A clear Trident refused stays, with the rows it would have covered —
     * and an instance that did not answer is not tried again in this drain.
     */
    public function testARefusedClearKeepsItsRowsAndADeadInstanceIsNotRetried(): void
    {
        $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ['cat_p_1']);
        $clear = $this->outbox->seed(DbPurgeOutbox::KIND_ALL, []);
        $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ['cat_p_2']);
        $this->http->down('edge-1');

        $this->assertSame(0, $this->newProcess()->drain(500));

        $this->assertCount(1, $this->http->requests, 'the clear only');
        $this->assertCount(3, $this->outbox->rows);
        $this->assertSame(1, $this->outbox->rows[$clear]['attempts']);
        $this->assertStringStartsWith('no response', (string) $this->outbox->rows[$clear]['last_error']);

        $this->http->up('edge-1');
        $this->clock->now += 1;
        $this->assertSame(3, $this->newProcess()->drain(500));
        $this->assertSame([['cat_p_2']], $this->http->purged(), 'cat_p_1 rode on the clear');
    }

    public function testADrainStopsAfterThreeConsecutiveFailures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, array_map(fn (int $n): string => "t{$i}_$n", range(1, 1000)));
        }
        $this->http->refuse('edge-1', 5);

        $this->newProcess()->drain(500);

        $this->assertCount(3, $this->http->requests, 'a refusing edge is not waited out five times');
        $this->assertCount(5, $this->outbox->rows, 'nothing is dropped');
    }

    public function testEntriesAreMergedIntoRequestsOfAtMostAThousandTags(): void
    {
        $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ['a', 'b']);
        $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ['b', 'c']);
        $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, array_map(fn (int $n): string => "x$n", range(1, 999)));

        $this->newProcess()->drain(500);

        $this->assertSame(['a', 'b', 'c'], $this->http->purged()[0]);
        $this->assertCount(999, $this->http->purged()[1]);
        $this->assertSame([], $this->outbox->rows);
    }

    /**
     * A refused entry waits out its backoff for automatic retries — but the
     * operator's `trident:purge:drain`, run after fixing the token, delivers
     * it now (found on the live stack: the command delivered nothing).
     */
    public function testAForcedDrainIgnoresTheBackoffAnAutomaticOneKeeps(): void
    {
        $this->http->refuse('edge-1');
        $this->purge->purgeTags(['cat_p_1']);
        $this->assertCount(1, $this->outbox->rows, 'refused');

        $this->assertSame(0, $this->newProcess()->drain(500), 'backing off: an automatic drain waits');
        $this->assertSame(1, $this->newProcess()->drain(500, true), 'the operator drain delivers now');
        $this->assertSame([], $this->outbox->rows);
    }

    /** Before `setup:upgrade` creates the table, purges still go out. */
    public function testWithoutItsTableThePurgeStillGoesOutDirectly(): void
    {
        $this->outbox->broken = true;

        $this->purge->purgeTags(['cat_p_1']);
        $this->assertSame([['cat_p_1']], $this->http->purged());

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_2']);
        $this->purge->purgeAll();
        $this->commitTo(0);
        $this->assertSame([['cat_p_1']], $this->http->purged(), 'the clear supersedes the held tags');
        $this->assertSame(1, $this->http->clears());
    }

    // =========================================================================
    // X03 — several instances
    // =========================================================================

    private function twoInstances(): void
    {
        $this->instances = [
            new Instance('edge-1', 'http://edge-1:9301', 't1'),
            new Instance('edge-2', 'http://edge-2:9301', 't2'),
        ];
    }

    /**
     * @return list<?string>
     */
    private function owedTo(): array
    {
        return array_column($this->outbox->all(), 'instance');
    }

    public function testAPurgeIsRecordedAndDeliveredOncePerInstance(): void
    {
        $this->twoInstances();

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->assertSame([], $this->http->requests, 'held until the commit');
        $this->commitAndDie();
        $this->assertSame(['edge-1', 'edge-2'], $this->owedTo());
        $this->newProcess()->drain(50);

        $this->assertSame([['cat_p_1']], $this->http->purged('edge-1'));
        $this->assertSame([['cat_p_1']], $this->http->purged('edge-2'));
        $this->assertSame('Bearer t2', $this->http->requests[1]['headers']['Authorization'], 'each with its own token');
        $this->assertSame([], $this->outbox->rows);
    }

    /**
     * edge-2 is down: edge-1's purge is done and gone, edge-2's stays — and
     * the next drain sends it to edge-2 alone, not to edge-1 again.
     */
    public function testAnInstanceThatIsDownKeepsOnlyItsOwnPurgePending(): void
    {
        $this->twoInstances();
        $this->http->refuse('edge-2');

        $this->purge->purgeTags(['cat_p_1']);
        $this->assertSame(['edge-2'], $this->owedTo());
        $this->assertSame('HTTP 503 — unavailable', end($this->outbox->failures));

        $this->clock->now += 1;
        $this->purge->drain(50);

        $this->assertSame([['cat_p_1']], $this->http->purged('edge-1'), 'edge-1 was not sent it twice');
        $this->assertSame([['cat_p_1'], ['cat_p_1']], $this->http->purged('edge-2'));
        $this->assertSame([], $this->outbox->rows);
    }

    /** Three failures stop one instance's drain, never the other's. */
    public function testOneInstancesFailuresDoNotStopAnother(): void
    {
        $this->twoInstances();
        foreach (range(1, 5) as $i) {
            $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, array_map(fn (int $j): string => "t{$i}_$j", range(1, 1000)), 'edge-1');
            $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, array_map(fn (int $j): string => "t{$i}_$j", range(1, 1000)), 'edge-2');
        }
        $this->http->refuse('edge-1', 5);

        $this->purge->drain(50);

        $this->assertCount(3, $this->http->purged('edge-1'), 'edge-1 gave up after three');
        $this->assertCount(5, $this->http->purged('edge-2'), 'edge-2 delivered everything');
        $this->assertSame(['edge-1'], array_values(array_unique($this->owedTo())));
    }

    /** A row written before X03 has no instance: every instance is owed it. */
    public function testAPurgeRecordedBeforeInstancesExistedGoesToEveryInstance(): void
    {
        $this->twoInstances();
        $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ['cat_p_1'], null);

        $this->purge->drain(50);

        $this->assertSame([['cat_p_1']], $this->http->purged('edge-1'));
        $this->assertSame([['cat_p_1']], $this->http->purged('edge-2'));
        $this->assertSame([], $this->outbox->rows);
    }

    /**
     * X02 may leave thousands of rows behind; the first request after the
     * upgrade must split only as many as the drain reads, not all of them.
     */
    public function testSplittingOldRowsStaysWithinTheDrainLimit(): void
    {
        $this->twoInstances();
        $this->http->down('edge-1')->down('edge-2');
        foreach (range(1, 120) as $i) {
            $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ["t$i"], null);
        }

        $this->purge->drain(50);

        $legacy = array_filter($this->owedTo(), fn (?string $i): bool => $i === null);
        $this->assertCount(70, $legacy, 'only 50 were split');
        $this->assertCount(170, $this->outbox->rows, '50 split in two, 70 untouched');
    }

    /** A split row keeps its attempts and its backoff: it is not "new". */
    public function testASplitRowKeepsItsHistory(): void
    {
        $this->twoInstances();
        $id = $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ['cat_p_1'], null);
        $this->outbox->rows[$id]['attempts'] = 2;
        $this->outbox->rows[$id]['next_attempt_at'] = $this->clock->now + 100;

        $this->purge->drain(50);

        $this->assertSame([2, 2], array_column($this->outbox->all(), 'attempts'));
        $this->assertSame([], $this->http->requests, 'still backing off');
    }

    /**
     * The column compares names case-insensitively; PHP does not. A row for
     * "Edge-1" matched by "edge-1" must be skipped — not crash the save that
     * triggered the drain, and not go to the wrong instance.
     */
    public function testARowWhoseNameMatchesOnlyCaseInsensitivelyIsSkipped(): void
    {
        $this->twoInstances();
        $this->outbox->caseInsensitive = true;
        $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ['old'], 'Edge-1');
        $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ['cat_p_1'], 'edge-1');

        $this->assertSame(1, $this->purge->drain(50));

        $this->assertSame([['cat_p_1']], $this->http->purged('edge-1'));
        $this->assertSame([], $this->http->purged('edge-2'));
        $this->assertSame(['Edge-1'], $this->owedTo());
    }

    /**
     * An instance removed from env.php: its purges are neither sent anywhere
     * nor dropped behind the operator's back, and they do not take the
     * drain's slots from the instances that exist.
     */
    public function testPurgesForARemovedInstanceAreKeptButDoNotBlockTheOthers(): void
    {
        $this->twoInstances();
        foreach (range(1, 60) as $i) {
            $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ["old_$i"], 'edge-gone');
        }
        $this->outbox->seed(DbPurgeOutbox::KIND_TAGS, ['cat_p_1'], 'edge-1');

        $this->purge->drain(50);

        $this->assertSame([['cat_p_1']], $this->http->purged('edge-1'));
        $this->assertCount(60, $this->outbox->rows, 'kept, not sent');
        $this->assertSame(60, $this->outbox->forget('edge-gone'));
        $this->assertSame([], $this->outbox->rows);
    }

    public function testAFullClearIsOwedToEveryInstance(): void
    {
        $this->twoInstances();

        $this->purge->purgeAll();

        $this->assertSame(1, $this->http->clears('edge-1'));
        $this->assertSame(1, $this->http->clears('edge-2'));
    }

    /** A PurgeAfterCommit in a fresh PHP process: same database, no memory. */
    private function newProcess(): PurgeAfterCommit
    {
        return new PurgeAfterCommit(
            $this->resource,
            $this->outbox,
            $this->config,
            new TridentClient($this->http, new NullLogger(), $this->config),
            new NullLogger(),
            $this->clock
        );
    }

    private function commitTo(int $level): void
    {
        $this->level = $level;
        if ($level === 0) {
            $this->outbox->commit();
        }
        $this->commitCallbacks->afterCommit($this->connection, $this->connection);
    }

    /** The transaction commits, then the process dies before the callbacks. */
    private function commitAndDie(): void
    {
        $this->level = 0;
        $this->outbox->commit();
        CallbackPool::clear(spl_object_hash($this->connection));
    }

    private function rollBack(): void
    {
        $this->level = 0;
        $this->outbox->rollBack();
        $this->commitCallbacks->afterRollBack($this->connection, $this->connection);
    }
}
