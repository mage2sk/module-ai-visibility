<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Simulator;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleWriter;
use Panth\AiVisibility\Service\Simulator\Simulator;
use Panth\AiVisibility\Service\VisitWriter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SimulatorTest extends TestCase
{
    /**
     * @var AdapterInterface&Stub
     */
    private $connection;

    /**
     * @var RollupInterface&Stub
     */
    private $rollup;

    /**
     * @var RuleWriter&Stub
     */
    private $ruleWriter;

    /**
     * @var array<int, array{0: string, 1: mixed}>
     */
    private array $deletes = [];

    /**
     * @var array<string, array<int, array>>
     */
    private array $inserted = [];

    /**
     * @var string[]
     */
    private array $missing = [];

    /**
     * fetch results per table: method => table => value
     *
     * @var array<string, array<string, mixed>>
     */
    private array $results = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $candidates = [];

    private int $inserts = 0;

    private int $windowDays = 3;

    /**
     * @var array<string, array<int, array>> single-row inserts into other tables, by table
     */
    private array $rowInserts = [];

    /**
     * @var array<string, array<string, mixed>> candidate rows inserted, by type
     */
    private array $candidateRows = [];

    /**
     * @var int[]
     */
    private array $deletedRules = [];

    protected function setUp(): void
    {
        $this->connection = $this->createStub(AdapterInterface::class);
        $this->rollup = $this->createStub(RollupInterface::class);
        $this->ruleWriter = $this->createStub(RuleWriter::class);
        $this->deletes = [];
        $this->inserted = [];
        $this->missing = [];
        $this->candidates = [];
        $this->inserts = 0;
        $this->deletedRules = [];
        $this->results = [
            'fetchOne' => ['store' => '1', 'panth_aiv_recommendation' => '100', 'panth_aiv_action' => '200'],
            'fetchAll' => [
                'url_rewrite' => [
                    ['request_path' => 'shirt.html', 'entity_type' => 'product'],
                    ['request_path' => 'trousers.html', 'entity_type' => 'product'],
                    ['request_path' => 'men.html', 'entity_type' => 'category'],
                    ['request_path' => 'about-us', 'entity_type' => 'cms-page'],
                ],
            ],
            'fetchCol' => [],
        ];

        $this->connection->method('isTableExists')->willReturnCallback(
            fn($table) => !in_array($table, $this->missing, true)
        );
        $this->connection->method('select')->willReturnCallback(static fn() => new FakeSelect());
        $this->connection->method('delete')->willReturnCallback(function ($table, $where) {
            $this->deletes[] = [$table, $where];
            return 2;
        });
        $this->connection->method('insertMultiple')->willReturnCallback(function ($table, $rows) {
            $this->inserted[$table][] = $rows;
            return count($rows);
        });
        $this->connection->method('insertOnDuplicate')->willReturnCallback(function ($table, $rows, $fields) {
            $this->inserted[$table . '#dup:' . implode(',', $fields)][] = $rows;
            return count($rows);
        });
        $this->connection->method('insert')->willReturnCallback(function ($table, $row) {
            if ($table !== 'panth_aiv_candidate') {
                $this->rowInserts[$table][] = $row;
                return 1;
            }
            $this->inserts++;
            $this->candidateRows[$row['type']] = $row;
            $this->candidates[$row['type']] = [
                'candidate_id' => $row['type'] === 'bot' ? 21 : 22,
                'is_simulated' => 1,
            ];
            return 1;
        });
        $this->connection->method('fetchOne')->willReturnCallback(
            fn($select) => $this->results['fetchOne'][$select->table()] ?? '0'
        );
        $this->connection->method('fetchAll')->willReturnCallback(
            fn($select) => $this->results['fetchAll'][$select->table()] ?? []
        );
        $this->connection->method('fetchCol')->willReturnCallback(
            fn($select) => $this->results['fetchCol'][$select->table()] ?? []
        );
        $this->connection->method('fetchRow')->willReturnCallback(function ($select) {
            if ($select->table() === 'panth_aiv_candidate') {
                return $this->candidates[$select->whereValue('type = ?')] ?? false;
            }
            return ['hits' => 27, 'llms' => 2, 'robots' => 1, 'first_seen' => null, 'last_seen' => null];
        });
    }

    private function simulator(): Simulator
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $config = $this->createStub(Config::class);
        $config->method('verifyWindowDays')->willReturnCallback(fn () => $this->windowDays);
        return new Simulator($resource, $this->rollup, $this->ruleWriter, $config);
    }

    /**
     * @return array<string, mixed>
     */
    private static function options(array $overrides = []): array
    {
        return $overrides + [
            'days' => 3,
            'seed' => 7,
            'store' => 1,
            'scale_permille' => 1000,
            'with_orders' => 0,
            'force' => false,
            'until' => '2026-03-03',
        ];
    }

    public function testPurgeDeletesOnlySimulatedRowsInEveryTable(): void
    {
        $this->results['fetchAll']['panth_aiv_hit'] = [['store_id' => '1', 'day' => '2026-03-01']];
        $this->results['fetchAll']['panth_aiv_visit_daily'] = [['store_id' => '2', 'day' => '2026-02-27']];
        $this->results['fetchCol']['panth_aiv_candidate'] = ['5'];
        $this->results['fetchCol']['panth_aiv_rule'] = ['9'];
        $this->ruleWriter->method('delete')->willReturnCallback(function ($ruleId) {
            $this->deletedRules[] = $ruleId;
        });
        $rolled = [];
        $this->rollup->method('rollupDay')->willReturnCallback(function ($day, $store) use (&$rolled) {
            $rolled[] = [$day, $store];
        });

        $summary = $this->simulator()->purge();

        $tables = array_column($this->deletes, 0);
        sort($tables);
        $expected = Simulator::SIMULATED_TABLES;
        sort($expected);
        $this->assertSame($expected, $tables);
        foreach ($this->deletes as [$table, $where]) {
            $this->assertSame(['is_simulated = ?' => 1], $where, $table);
        }
        $this->assertSame([['2026-03-01', 1], ['2026-02-27', 2]], $rolled);
        $this->assertSame(1, $summary['rules']);
        $this->assertSame([9], $this->deletedRules);
        $this->assertSame(2 * count(Simulator::SIMULATED_TABLES), array_sum($summary['deleted']));
        // Planted impacts, actions and recommendations go too, children first.
        $this->assertSame(
            ['panth_aiv_impact', 'panth_aiv_action', 'panth_aiv_recommendation'],
            array_slice(array_column($this->deletes, 0), 0, 3)
        );
        $this->assertSame(2, $summary['rolled_up']);
    }

    public function testPurgeRefusesWithoutTables(): void
    {
        $this->missing = ['panth_aiv_visit'];
        try {
            $this->simulator()->purge();
            $this->fail('Expected an exception.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('panth_aiv_visit', $e->getMessage());
        }
        $this->assertSame([], $this->deletes);
    }

    public function testSimulateRefusesWithoutTables(): void
    {
        $this->missing = ['panth_aiv_hit'];
        try {
            $this->simulator()->simulate(self::options());
            $this->fail('Expected an exception.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('panth_aiv_hit', $e->getMessage());
        }
        $this->assertSame([], $this->inserted);
    }

    public function testSimulateRequiresForceWhenSimulatedDataExists(): void
    {
        $this->results['fetchOne']['panth_aiv_hit'] = '12';
        try {
            $this->simulator()->simulate(self::options());
            $this->fail('Expected an exception.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('--force', $e->getMessage());
        }
        $this->assertSame([], $this->inserted);
        $this->assertSame([], $this->deletes);
    }

    public function testForceReplacesOnlySimulatedRowsOfThePeriod(): void
    {
        $this->results['fetchOne']['panth_aiv_hit'] = '12';
        $this->results['fetchCol']['panth_aiv_order'] = ['100', '101'];
        $summary = $this->simulator()->simulate(self::options(['force' => true]));

        $this->assertNotEmpty($this->deletes);
        foreach ($this->deletes as [$table, $where]) {
            $this->assertSame(1, $where['is_simulated = ?'] ?? null, $table);
            if ($table !== 'panth_aiv_order') {
                $this->assertSame(1, $where['store_id = ?'], $table);
            }
        }
        $this->assertSame(
            ['is_simulated = ?' => 1, 'order_id IN (?)' => [100, 101]],
            $this->deletes[count($this->deletes) - 1][1]
        );
        $this->assertNotEmpty($summary['replaced']);
    }

    public function testSimulateWritesFlaggedRowsInChunksAndRollsEveryDay(): void
    {
        $rolled = [];
        $this->rollup->method('rollupDay')->willReturnCallback(function ($day, $store) use (&$rolled) {
            $rolled[] = [$day, $store];
        });

        $summary = $this->simulator()->simulate(self::options());

        $this->assertArrayHasKey('panth_aiv_hit', $this->inserted);
        $hitRows = 0;
        foreach ($this->inserted as $table => $chunks) {
            foreach ($chunks as $chunk) {
                $this->assertLessThanOrEqual(Simulator::CHUNK_SIZE, count($chunk));
                foreach ($chunk as $row) {
                    $this->assertSame(1, $row['is_simulated'], $table);
                    if ($table === 'panth_aiv_hit') {
                        $this->assertSame('sim', $row['origin']);
                        $hitRows++;
                    }
                }
            }
        }
        $this->assertSame($summary['hits'], $hitRows);
        $this->assertGreaterThan(Simulator::CHUNK_SIZE, $hitRows);
        $this->assertSame([['2026-03-01', 1], ['2026-03-02', 1], ['2026-03-03', 1]], $rolled);
        $this->assertSame(21, $summary['bot_candidate']);
        $this->assertSame(22, $summary['source_candidate']);
        $this->assertSame(81, $summary['candidate_hits']);
        $this->assertSame(9, $summary['candidate_visits']);
        $this->assertSame([], $this->deletes);
    }

    public function testPlantedSourceCandidateUsesTheCollectorKey(): void
    {
        $this->simulator()->simulate(self::options());
        $row = $this->candidateRows['source'];
        $collectorKey = VisitWriter::hostCandidateKey('www.Assistant.Example.Invalid');
        $this->assertSame($collectorKey, $row['key_value']);
        $this->assertSame(VisitWriter::candidateKeyHash($collectorKey), $row['key_hash']);
        $this->assertSame(sha1('host:assistant.example.invalid'), $row['key_hash']);
    }

    public function testRealCandidateWithSameKeyIsNeverTouched(): void
    {
        $this->candidates['bot'] = ['candidate_id' => 3, 'is_simulated' => 0];
        $summary = $this->simulator()->simulate(self::options());
        $this->assertSame(1, $this->inserts);
        $this->assertNull($summary['bot_candidate']);
        $this->assertSame(0, $summary['candidate_hits']);
        foreach ($this->inserted['panth_aiv_hit'] as $chunk) {
            foreach ($chunk as $row) {
                $this->assertStringStartsNotWith('cand:3', $row['bot_code']);
            }
        }
    }

    public function testWithOrdersOnlyInsertsWithoutOverwriting(): void
    {
        $this->results['fetchAll']['sales_order'] = [
            ['entity_id' => '40', 'created_at' => '2026-03-02 10:00:00'],
            ['entity_id' => '39', 'created_at' => '2026-03-01 09:00:00'],
        ];
        $summary = $this->simulator()->simulate(self::options(['with_orders' => 5]));
        $this->assertSame(2, $summary['orders']);
        $rows = $this->inserted['panth_aiv_order#dup:order_id'][0];
        $this->assertSame([40, 39], array_column($rows, 'order_id'));
        foreach ($rows as $row) {
            $this->assertSame(1, $row['is_simulated']);
            $this->assertSame('referral', $row['channel']);
        }
    }

    public function testDaysWithPrunedRealRawRowsAreRolledUpWithoutTouchingRealRows(): void
    {
        // 2026-03-01 has real daily rows but its real raw rows were pruned. The per-slice rollup keeps
        // those real rows, so the simulated slice of that day is rolled up as well.
        $this->results['fetchCol']['panth_aiv_crawl_daily'] = ['2026-03-01', '2026-03-02'];
        $this->results['fetchCol']['panth_aiv_hit'] = ['2026-03-02'];
        // Earlier simulated rows exist, so --force replaces them (exercises the simulator's own deletes).
        $this->results['fetchOne']['panth_aiv_hit'] = '5';
        $rolled = [];
        $this->rollup->method('rollupDay')->willReturnCallback(function ($day) use (&$rolled) {
            $rolled[] = $day;
        });
        $summary = $this->simulator()->simulate(self::options(['force' => true]));
        $this->assertSame([], $summary['rollup_skipped']);
        $this->assertSame(['2026-03-01', '2026-03-02', '2026-03-03'], $rolled);
        // The simulator itself only ever deletes simulated rows.
        $this->assertNotEmpty($this->deletes);
        foreach ($this->deletes as [$table, $where]) {
            $this->assertSame(1, $where['is_simulated = ?'] ?? null, $table . ' delete must be limited to simulated rows');
        }
    }

    public function testRollupFailureIsReportedNotThrown(): void
    {
        $this->rollup->method('rollupDay')->willThrowException(new \RuntimeException('boom'));
        $summary = $this->simulator()->simulate(self::options(['days' => 1]));
        $this->assertSame(['2026-03-03: boom'], $summary['rollup_failed']);
        $this->assertSame(0, $summary['rolled_up']);
    }

    public function testRollupDeadlockIsRetriedThenSucceeds(): void
    {
        $attempts = 0;
        $this->rollup->method('rollupDay')->willReturnCallback(function () use (&$attempts): void {
            if (++$attempts < 3) {
                throw new \RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
            }
        });
        $summary = $this->simulator()->simulate(self::options(['days' => 1]));
        $this->assertSame(3, $attempts);
        $this->assertSame([], $summary['rollup_failed']);
        $this->assertSame(1, $summary['rolled_up']);
    }

    public function testRollupDeadlockIsReportedAfterTheLastAttempt(): void
    {
        $attempts = 0;
        $this->rollup->method('rollupDay')->willReturnCallback(function () use (&$attempts): void {
            $attempts++;
            throw new \RuntimeException('Lock wait timeout exceeded');
        });
        $summary = $this->simulator()->simulate(self::options(['days' => 1]));
        $this->assertSame(Simulator::ROLLUP_ATTEMPTS, $attempts);
        $this->assertSame(['2026-03-03: Lock wait timeout exceeded'], $summary['rollup_failed']);
    }

    public function testOnlyDeadlocksAndLockTimeoutsAreRetryable(): void
    {
        $this->assertTrue(Simulator::isRetryable(new \RuntimeException('Deadlock found when trying to get lock')));
        $this->assertTrue(Simulator::isRetryable(
            new \RuntimeException('wrapped', 0, new \RuntimeException('SQLSTATE[HY000]: General error: 1205'))
        ));
        $this->assertFalse(Simulator::isRetryable(new \RuntimeException('boom')));
    }

    public function testUnknownStoreIsRejected(): void
    {
        $this->results['fetchOne']['store'] = false;
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Store view 9');
        $this->simulator()->simulate(self::options(['store' => 9]));
    }

    public function testStoreWithoutPathsIsRejected(): void
    {
        $this->results['fetchAll']['url_rewrite'] = [];
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('URL rewrites');
        $this->simulator()->simulate(self::options());
    }

    /**
     * url_rewrite rows: products with IDs 101.. (in path order) and one category
     *
     * @param int $products
     * @return array<int, array<string, mixed>>
     */
    private static function productRewrites(int $products = 20): array
    {
        $rows = [['request_path' => 'men.html', 'entity_type' => 'category', 'entity_id' => '5']];
        for ($i = 1; $i <= $products; $i++) {
            $rows[] = [
                'request_path' => sprintf('product-%02d.html', $i),
                'entity_type' => 'product',
                'entity_id' => (string) (100 + $i),
            ];
        }
        return $rows;
    }

    /**
     * Options of a 7-day scenario run ending 2026-03-07 (window 3: applied on the 4th)
     *
     * @param string $scenario
     * @param array $overrides
     * @return array<string, mixed>
     */
    private static function fixOptions(string $scenario, array $overrides = []): array
    {
        return self::options($overrides + ['days' => 7, 'until' => '2026-03-07', 'scenario' => $scenario]);
    }

    public function testFixScenarioPlantsAnAppliedSimulatedActionOnTreatedProducts(): void
    {
        $this->results['fetchAll']['url_rewrite'] = self::productRewrites();
        $summary = $this->simulator()->simulate(self::fixOptions('fix-helped'));

        $treated = ['101', '103', '105', '107', '109', '111', '113', '115', '117', '119'];
        $this->assertCount(1, $this->rowInserts['panth_aiv_recommendation']);
        $rec = $this->rowInserts['panth_aiv_recommendation'][0];
        $this->assertSame(Simulator::plantedFingerprint('fix-helped', 1), $rec['fingerprint']);
        $this->assertSame(sha1('never_crawled_products|1|sim:fix-helped'), $rec['fingerprint']);
        $this->assertSame('applied', $rec['status']);
        $this->assertSame('product', $rec['subject_type']);
        $this->assertSame('bot_hits', $rec['metric']);
        $this->assertSame(1, $rec['is_simulated']);
        $evidence = json_decode($rec['evidence'], true);
        $this->assertSame($treated, $evidence['subjects']);
        $this->assertSame(
            ['102', '104', '106', '108', '110', '112', '114', '116', '118', '120'],
            $evidence['controls']
        );

        $this->assertCount(1, $this->rowInserts['panth_aiv_action']);
        $action = $this->rowInserts['panth_aiv_action'][0];
        $this->assertSame(100, $action['rec_id']);
        $this->assertSame('never_crawled_products', $action['rule_code']);
        $this->assertSame(1, $action['store_id']);
        $this->assertSame($treated, json_decode($action['subjects'], true));
        // Window 3: applied on the 4th, before = 1st-3rd, after = 5th-7th, verifiable from the 8th.
        $this->assertSame('2026-03-04 12:00:00', $action['applied_at']);
        $this->assertSame('2026-03-08', $action['verify_after']);
        $this->assertSame('pending', $action['status']);
        $this->assertNull($action['applied_by']);
        $this->assertNull($action['undone_at']);
        $this->assertSame(1, $action['is_simulated']);

        $fix = $summary['fix'];
        $this->assertSame(200, $fix['action_id']);
        $this->assertSame(100, $fix['rec_id']);
        $this->assertSame([101, 103, 105, 107, 109, 111, 113, 115, 117, 119], $fix['treated']);
        $this->assertSame(500, $fix['effect_permille']);
        $ratio = $fix['treated_after'] / $fix['treated_before'];
        $controlRatio = $fix['untreated_after'] / $fix['untreated_before'];
        $this->assertGreaterThan(1.3, $ratio / $controlRatio);
        $this->assertLessThan(1.7, $ratio / $controlRatio);
        $this->assertGreaterThan(0, $fix['extra_hits']);
    }

    public function testFixNoEffectScenarioPlantsTheSameActionWithoutExtraHits(): void
    {
        $this->results['fetchAll']['url_rewrite'] = self::productRewrites();
        $summary = $this->simulator()->simulate(self::fixOptions('fix-no-effect'));
        $this->assertSame(0, $summary['fix']['extra_hits']);
        $this->assertSame(
            Simulator::plantedFingerprint('fix-no-effect', 1),
            $this->rowInserts['panth_aiv_recommendation'][0]['fingerprint']
        );
        $ratio = ($summary['fix']['treated_after'] / $summary['fix']['treated_before'])
            / ($summary['fix']['untreated_after'] / $summary['fix']['untreated_before']);
        $this->assertGreaterThan(0.85, $ratio);
        $this->assertLessThan(1.15, $ratio);
    }

    public function testPlainRunPlantsNothing(): void
    {
        $summary = $this->simulator()->simulate(self::options());
        $this->assertNull($summary['fix']);
        $this->assertSame([], $this->rowInserts);
    }

    public function testFixScenarioNeedsTwoWindowsOfDays(): void
    {
        $this->results['fetchAll']['url_rewrite'] = self::productRewrites();
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('at least 7 days');
        $this->simulator()->simulate(self::fixOptions('fix-helped', ['days' => 6]));
    }

    public function testFixScenarioNeedsSixProducts(): void
    {
        $this->results['fetchAll']['url_rewrite'] = self::productRewrites(5);
        try {
            $this->simulator()->simulate(self::fixOptions('fix-helped'));
            $this->fail('Expected an exception.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('at least 6 products', $e->getMessage());
        }
        $this->assertArrayNotHasKey('panth_aiv_action', $this->rowInserts);
        $this->assertSame([], $this->inserted);
    }

    public function testUnknownScenarioIsRejectedBeforeWriting(): void
    {
        try {
            $this->simulator()->simulate(self::options(['scenario' => 'fix-maybe']));
            $this->fail('Expected an exception.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('fix-maybe', $e->getMessage());
        }
        $this->assertSame([], $this->inserted);
    }

    public function testRerunReplacesOnlyEarlierPlantedSimulatedRows(): void
    {
        $this->results['fetchAll']['url_rewrite'] = self::productRewrites();
        $this->results['fetchCol']['panth_aiv_recommendation'] = ['7'];
        $this->simulator()->simulate(self::fixOptions('fix-helped'));
        $this->assertContains(
            ['panth_aiv_action', ['is_simulated = ?' => 1, 'rec_id IN (?)' => [7]]],
            $this->deletes
        );
        $this->assertContains(
            ['panth_aiv_recommendation', ['is_simulated = ?' => 1, 'rec_id IN (?)' => [7]]],
            $this->deletes
        );
        foreach ($this->deletes as [$table, $where]) {
            $this->assertSame(1, $where['is_simulated = ?'] ?? null, $table);
        }
    }

    public function testForceAlsoRemovesPlantedRowsOfTheStore(): void
    {
        $this->results['fetchOne']['panth_aiv_hit'] = '12';
        $this->results['fetchCol']['panth_aiv_recommendation'] = ['8'];
        $summary = $this->simulator()->simulate(self::options(['force' => true]));
        $this->assertContains(
            ['panth_aiv_action', ['is_simulated = ?' => 1, 'rec_id IN (?)' => [8]]],
            $this->deletes
        );
        $this->assertSame(2, $summary['replaced']['panth_aiv_action']);
    }

    public function testWindowComesFromTheVerificationConfig(): void
    {
        $this->windowDays = 5;
        $this->results['fetchAll']['url_rewrite'] = self::productRewrites();
        $summary = $this->simulator()->simulate(
            self::fixOptions('fix-helped', ['days' => 11, 'until' => '2026-03-11'])
        );
        $this->assertSame(5, $summary['fix']['window_days']);
        $this->assertSame('2026-03-06', $summary['fix']['applied_day']);
        $this->assertSame('2026-03-12', $summary['fix']['verify_after']);
    }
}
