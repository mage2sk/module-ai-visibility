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
            'fetchOne' => ['store' => '1'],
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
        return new Simulator($resource, $this->rollup, $this->ruleWriter);
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
        $this->assertSame(18, array_sum($summary['deleted']));
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
}
