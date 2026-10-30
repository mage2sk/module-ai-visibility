<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Retention;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetentionTest extends TestCase
{
    /**
     * @var array<int, array{0: string, 1: string, 2: mixed}>
     */
    private array $calls = [];

    /**
     * @var string[]
     */
    private array $rolled = [];

    private int $now;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->rolled = [];
        $this->now = gmmktime(3, 10, 0, 10, 30, 2026);
    }

    public function testUnsummarisedDaysAreRolledUpThenWholeDaysArePruned(): void
    {
        $result = $this->retention(
            ['panth_aiv_hit' => ['2026-10-10', '2026-10-12'], 'panth_aiv_visit' => ['2026-09-01']],
            ['panth_aiv_crawl_daily' => ['2026-10-12'], 'panth_aiv_visit_daily' => []]
        )->run($this->now);

        $this->assertSame(['2026-09-01', '2026-10-10'], $this->rolled);
        $this->assertSame(2, $result['rolled_up']);

        $deletes = $this->rawDeletes();
        $this->assertSame('2026-10-16 00:00:00', $deletes['panth_aiv_hit']);
        $this->assertSame('2026-09-30 00:00:00', $deletes['panth_aiv_visit']);
        $this->assertSame('2026-10-20 00:00:00', $deletes['panth_aiv_landing']);

        $daily = [];
        foreach ($this->calls as [$method, $table, $where]) {
            if ($method === 'delete') {
                $daily[$table] = $where;
            }
        }
        foreach (['panth_aiv_crawl_daily', 'panth_aiv_path_daily', 'panth_aiv_entity_daily', 'panth_aiv_visit_daily',
                     'panth_aiv_overflow_daily'] as $table) {
            $this->assertSame(['day < ?' => '2025-09-30'], $daily[$table]);
        }
        $this->assertSame(['expires_at < ?' => $this->now], $daily['panth_aiv_rate_limit']);
        $this->assertSame(
            ['day < ?' => '2026-10-27', 'counter_key NOT LIKE ?' => 'raw_floor_%',
                'counter_key NOT IN (?)' => ['email_weekly_sent']],
            $daily['panth_aiv_counter'],
            'old counters are pruned, the raw floor rows and the weekly email marker never'
        );
        $this->assertSame(3, $result['panth_aiv_hit']);
    }

    public function testRawCutoffsAreQueriedBeforeTheWindow(): void
    {
        $this->retention([], [])->run($this->now);

        $binds = [];
        foreach ($this->calls as [$method, $sql, $bind]) {
            if ($method === 'fetchAll' && str_contains($sql, 'DATE(created_at)')) {
                $binds[] = $bind[0];
            }
        }
        $this->assertSame(['2026-10-16 00:00:00', '2026-09-30 00:00:00'], $binds);
    }

    public function testFailedRollupKeepsRawRowsOfThatDayAndLater(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('2026-10-10'));

        $this->retention(
            ['panth_aiv_hit' => ['2026-10-10', '2026-10-12'], 'panth_aiv_visit' => []],
            ['panth_aiv_crawl_daily' => []],
            '2026-10-10',
            $logger
        )->run($this->now);

        $this->assertSame(['2026-10-12'], $this->rolled);
        $deletes = $this->rawDeletes();
        $this->assertSame('2026-10-10 00:00:00', $deletes['panth_aiv_hit']);
        $this->assertSame('2026-09-30 00:00:00', $deletes['panth_aiv_visit']);
    }

    public function testRawRowsAreDeletedInBatches(): void
    {
        $retention = $this->retention([], [], null, null, [Retention::DELETE_BATCH, Retention::DELETE_BATCH, 5]);
        $result = $retention->run($this->now);

        $hitDeletes = array_filter(
            $this->calls,
            static fn (array $call): bool => $call[0] === 'query' && str_contains($call[1], '`panth_aiv_hit`')
        );
        $this->assertCount(3, $hitDeletes);
        $this->assertSame(2 * Retention::DELETE_BATCH + 5, $result['panth_aiv_hit']);
        foreach ($hitDeletes as [, $sql]) {
            $this->assertStringContainsString('ORDER BY created_at, `hit_id` LIMIT ' . Retention::DELETE_BATCH, $sql);
        }
    }

    public function testRawStepIsSkippedWhenDayDetectionFails(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('gone away'));
        $retention = $this->retention([], [], null, $logger, [], new \RuntimeException('gone away'));

        $result = $retention->run($this->now);

        $deletes = $this->rawDeletes();
        $this->assertArrayNotHasKey('panth_aiv_hit', $deletes);
        $this->assertArrayNotHasKey('panth_aiv_visit', $deletes);
        $this->assertArrayHasKey('panth_aiv_landing', $deletes);
        $this->assertSame(0, $result['panth_aiv_hit']);
    }

    /**
     * @dataProvider monthCutoffs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('monthCutoffs')]
    public function testMonthsCutoffDayClampsTheDayOfMonth(int $months, string $now, string $expected): void
    {
        $this->assertSame($expected, Retention::monthsCutoffDay($months, (int)strtotime($now . ' 12:00:00 UTC')));
    }

    public static function monthCutoffs(): array
    {
        return [
            'default' => [13, '2026-10-30', '2025-09-30'],
            'end of month' => [1, '2026-03-31', '2026-02-28'],
            'leap year' => [1, '2024-03-31', '2024-02-29'],
            'one year' => [12, '2026-01-15', '2025-01-15'],
            'across two years' => [14, '2026-01-31', '2024-11-30'],
        ];
    }

    /**
     * @param array $rawDays raw table => DATE(created_at) values before the cutoff
     * @param array $summarised daily table => days that have rows
     * @param string|null $failingDay
     * @param LoggerInterface|null $logger
     * @param int[] $rowCounts rowCount() sequence of the raw hit deletes
     * @param \Throwable|null $fetchFailure
     * @return Retention
     */
    private function retention(
        array $rawDays,
        array $summarised,
        ?string $failingDay = null,
        ?LoggerInterface $logger = null,
        array $rowCounts = [3],
        ?\Throwable $fetchFailure = null
    ): Retention {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($name): string => '`' . $name . '`');
        // Grouped (day, store_id, is_simulated) slices; every listed day is one slice of store 1, real rows.
        $connection->method('fetchAll')->willReturnCallback(
            function (string $sql, array $bind) use ($rawDays, $summarised, $fetchFailure): array {
                if ($fetchFailure !== null) {
                    throw $fetchFailure;
                }
                $this->calls[] = ['fetchAll', $sql, $bind];
                $source = str_contains($sql, 'DATE(created_at)') ? $rawDays : $summarised;
                foreach ($source as $table => $days) {
                    if (str_contains($sql, '`' . $table . '`')) {
                        $days = str_contains($sql, 'IN (') ? array_values(array_intersect($days, $bind)) : $days;
                        return array_map(
                            static fn (string $day): array => ['day' => $day, 'store_id' => '1', 'is_simulated' => '0'],
                            $days
                        );
                    }
                }
                return [];
            }
        );
        $connection->method('query')->willReturnCallback(function (string $sql, array $bind) use (&$rowCounts) {
            $this->calls[] = ['query', $sql, $bind];
            $count = 0;
            if (str_contains($sql, '`panth_aiv_hit`')) {
                $count = (int)(array_shift($rowCounts) ?? 0);
            }
            $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
            $statement->method('rowCount')->willReturn($count);
            return $statement;
        });
        $connection->method('delete')->willReturnCallback(function (string $table, array $where) {
            $this->calls[] = ['delete', $table, $where];
            return 2;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $config = $this->createStub(Config::class);
        $config->method('hitRetentionDays')->willReturn(14);
        $config->method('rawVisitRetentionDays')->willReturn(30);
        $config->method('landingRetentionDays')->willReturn(10);
        $config->method('dailyRetentionMonths')->willReturn(13);

        $rollup = $this->createStub(RollupInterface::class);
        $rollup->method('rollupDay')->willReturnCallback(function (string $day) use ($failingDay): void {
            if ($day === $failingDay) {
                throw new \RuntimeException('rollup failed');
            }
            $this->rolled[] = $day;
        });

        return new Retention($resource, $config, $rollup, $logger ?? $this->createStub(LoggerInterface::class));
    }

    /**
     * Cutoff bound to the first batch delete of each raw table: table => cutoff
     *
     * @return array<string, string>
     */
    private function rawDeletes(): array
    {
        $result = [];
        foreach ($this->calls as [$method, $sql, $bind]) {
            if ($method === 'query' && preg_match('/^DELETE FROM `([a-z_]+)`/', $sql, $m) && !isset($result[$m[1]])) {
                $result[$m[1]] = $bind[0];
            }
        }
        return $result;
    }
}
