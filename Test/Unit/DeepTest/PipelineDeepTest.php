<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Service\BotClassifier;
use Panth\AiVisibility\Service\PageTypeResolver;
use Panth\AiVisibility\Service\Relabeler;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;
use Panth\AiVisibility\Service\Retention;
use Panth\AiVisibility\Service\Rollup;
use Panth\AiVisibility\Service\Simulator\Scenario;
use Panth\AiVisibility\Service\Simulator\TrafficGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep test D4: rollup / relabel / retention / report pipeline.
 *
 * These tests prove the fix of a defect found in the deep test (they failed on the first build):
 *  - testRollupKeepsDailyRowsOfADayWithoutRawRowsInsideARaisedRetentionWindow
 *  - testRollupDoesNotWipeRealDailyRowsWhenOnlySimulatedRawRowsRemain
 *  - testRollupResolvesIndexPhpPrefixedPathsThroughUrlRewrite
 *  - testReportListsHaveADeterministicTieBreaker
 *  - testHasSimulatedDataOrderProbeRespectsTheStoreFilter
 */
class PipelineDeepTest extends TestCase
{
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $data = [];

    /**
     * @var array<int, array{0: string, 1: string, 2: array}>
     */
    private array $calls = [];

    /**
     * @var callable|null fetchOne answer for "raw rows exist" probes: fn(string $sql, array $bind): mixed
     */
    private $rawProbe = null;

    private string $defaultTimezone = 'UTC';

    protected function setUp(): void
    {
        $this->data = ['paths' => [], 'crawl' => [], 'overflow' => [], 'path' => [], 'visit' => [], 'rewrite' => []];
        $this->calls = [];
        $this->rawProbe = null;
        $this->defaultTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);
    }

    /**
     * Fixed; on the first build: a day inside the (raised) hit retention window whose raw rows were already pruned under the
     * old, shorter window has no raw rows; rollupDay() still deletes its crawl_daily/path_daily/visit_daily
     * rows and rebuilds them from nothing (TC4.07 "daily rows of days without raw rows are never wiped").
     */
    public function testRollupKeepsDailyRowsOfADayWithoutRawRowsInsideARaisedRetentionWindow(): void
    {
        $day = gmdate('Y-m-d', time() - 20 * 86400);
        $this->rawProbe = static fn (string $sql, array $bind) => false;

        $this->rollup(40, 40)->rollupDay($day);

        $this->assertSame([], $this->deletes(), 'Daily rows of a day without any raw rows must not be deleted.');
    }

    /**
     * Fixed; on the first build: a day before the hit retention cutoff whose REAL raw rows were pruned but which got simulated raw
     * rows (simulator, or a simulated candidate re-rolled by Relabeler) is rebuilt as a whole: the delete
     * removes the real (is_simulated = 0) crawl_daily rows, which can never be rebuilt.
     */
    public function testRollupDoesNotWipeRealDailyRowsWhenOnlySimulatedRawRowsRemain(): void
    {
        $day = gmdate('Y-m-d', time() - 20 * 86400);
        $this->rawProbe = static function (string $sql, array $bind) {
            $realProbe = stripos($sql, 'is_simulated = 0') !== false
                || (stripos($sql, 'is_simulated') !== false && in_array(0, $bind, true));
            return $realProbe ? false : '1';
        };
        $this->data['paths'] = [['store_id' => '1', 'path_hash' => 'h1', 'path' => '/sim.html']];
        $this->data['crawl'] = [$this->crawlGroup('1', '1', 'gptbot', 'training', 'h1', 2)];

        $this->rollup(14, 30)->rollupDay($day);

        foreach ($this->deletes() as [$table, $where]) {
            if ($table !== Rollup::CRAWL_DAILY_TABLE) {
                continue;
            }
            $simOnly = false;
            foreach ($where as $condition => $value) {
                if (is_string($condition) && str_contains($condition, 'is_simulated') && (int)$value === 1) {
                    $simOnly = true;
                }
            }
            $this->assertTrue(
                $simOnly,
                'Rebuilding a day whose real raw rows are gone must not delete its real daily rows: ' . json_encode($where)
            );
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Fixed (minor); on the first build: "/index.php/<url-key>.html" is a page for PageTypeResolver, but the url_rewrite lookup uses
     * "index.php/<url-key>.html" and fails, so the hit is counted as "other" without its product.
     */
    public function testRollupResolvesIndexPhpPrefixedPathsThroughUrlRewrite(): void
    {
        $this->data['paths'] = [['store_id' => '1', 'path_hash' => 'h-idx', 'path' => '/index.php/bag.html']];
        $this->data['rewrite'] = [
            ['request_path' => 'bag.html', 'entity_type' => 'product', 'entity_id' => '14', 'redirect_type' => '0'],
        ];
        $this->data['crawl'] = [$this->crawlGroup('1', '0', 'gptbot', 'training', 'h-idx', 1)];
        $this->data['path'] = [[
            'store_id' => '1', 'is_simulated' => '0', 'category' => 'training', 'path_hash' => 'h-idx',
            'path' => '/index.php/bag.html', 'hits' => '1',
        ]];

        $this->rollup(14, 30, new PageTypeResolver())->rollupDay(gmdate('Y-m-d'));

        $crawl = $this->inserted(Rollup::CRAWL_DAILY_TABLE);
        $this->assertSame('product', $crawl[0]['page_type'] ?? null);
        $paths = $this->inserted(Rollup::PATH_DAILY_TABLE);
        $this->assertSame(14, $paths[0]['entity_id'] ?? null);
    }

    /**
     * TC4.04: path_daily keeps the first 500 rows of every (store, simulated, category) group in the SQL
     * order "hits DESC, path_hash" and every row of smaller groups.
     */
    public function testPathDailyKeepsTop500PerGroupInSqlOrder(): void
    {
        for ($i = 0; $i < 600; $i++) {
            $this->data['path'][] = [
                'store_id' => '1', 'is_simulated' => '1', 'category' => 'training',
                'path_hash' => sprintf('%040d', $i), 'path' => '/p' . $i, 'hits' => (string)(1000 - intdiv($i, 3)),
            ];
        }
        foreach ([['1', '1', 'search'], ['1', '0', 'training'], ['2', '1', 'training']] as [$store, $sim, $cat]) {
            for ($i = 0; $i < 3; $i++) {
                $this->data['path'][] = [
                    'store_id' => $store, 'is_simulated' => $sim, 'category' => $cat,
                    'path_hash' => sprintf('x%039d', $i), 'path' => '/x' . $i, 'hits' => '1',
                ];
            }
        }

        $this->rollup(14, 30)->rollupDay(gmdate('Y-m-d'));

        $rows = $this->inserted(Rollup::PATH_DAILY_TABLE);
        $this->assertCount(509, $rows);
        $big = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['store_id'] === 1 && $r['is_simulated'] === 1 && $r['category'] === 'training'
        ));
        $this->assertCount(500, $big);
        $this->assertSame('/p0', $big[0]['path']);
        $this->assertSame('/p499', $big[499]['path']);
        $sql = '';
        foreach ($this->calls as [$method, $query]) {
            if ($method === 'query' && str_contains($query, 'ORDER BY')) {
                $sql = $query;
            }
        }
        $this->assertStringContainsString('ORDER BY store_id, is_simulated, category, hits DESC, path_hash', $sql);
    }

    /**
     * TC4.05: a bot candidate re-rolls every day with raw hits or overflow counters, oldest first, once each.
     */
    public function testRelabelerRerollsEveryAffectedDayOnce(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($name): string => '`' . $name . '`');
        $connection->method('fetchCol')->willReturnCallback(static function (string $sql): array {
            return str_contains($sql, 'DATE(created_at)')
                ? ['2026-10-21', '2026-10-20 00:00:00', '2026-10-20']
                : ['2026-10-15', '2026-10-21'];
        });
        $connection->method('update')->willReturn(7);
        $connection->method('fetchAll')->willReturn([
            ['day' => '2026-10-15', 'store_id' => '1', 'status_class' => '2', 'is_simulated' => '1', 'hits' => '5'],
        ]);
        $merged = [];
        $connection->method('insertOnDuplicate')->willReturnCallback(function ($table, array $rows, array $fields) use (&$merged) {
            $merged = [$rows, $fields];
            return count($rows);
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $days = [];
        $rollup = $this->createStub(RollupInterface::class);
        $rollup->method('rollupDay')->willReturnCallback(function (string $day, ?int $store = null) use (&$days) {
            $days[] = [$day, $store];
        });

        $result = (new Relabeler($resource, $rollup, $this->createStub(LoggerInterface::class)))
            ->relabelCandidate(12, 'bot', 'disc_simagent', 'training');

        $this->assertSame(7, $result);
        $this->assertSame([['2026-10-15', null], ['2026-10-20', null], ['2026-10-21', null]], $days);
        $this->assertSame('disc_simagent', $merged[0][0]['bot_code']);
        $this->assertSame('training', $merged[0][0]['category']);
    }

    /**
     * Fixed (minor); on the first build: top pages, bots and revenue lists sort by one aggregate only, so rows tied at the LIMIT
     * boundary come back in an undefined order (MySQL and MariaDB may differ between runs).
     */
    public function testReportListsHaveADeterministicTieBreaker(): void
    {
        [$repository, $selects] = $this->reportRepository();
        $range = new DateRange('2026-10-16', '2026-10-29', DateRange::PERIOD_CUSTOM);
        $repository->topPaths($range, 1, true, null, 10);
        $repository->botStats($range, 1, true);
        $repository->revenueBySource($range, 1, true, 'first');

        foreach (['topPaths' => 0, 'botStats' => 1, 'revenueBySource' => 2] as $method => $index) {
            $keys = 0;
            foreach ($selects->calls[$index] ?? [] as [$name, $args]) {
                if ($name === 'order') {
                    $keys += is_array($args[0]) ? count($args[0]) : 1;
                }
            }
            $this->assertGreaterThanOrEqual(2, $keys, $method . ' needs a secondary ORDER BY key for ties.');
        }
    }

    /**
     * Fixed (minor); on the first build: hasSimulatedData() filters crawl/visit probes by store, but not the order probe, so a
     * store view without simulated rows shows the "simulated data" banner when another store has
     * simulated order attributions.
     */
    public function testHasSimulatedDataOrderProbeRespectsTheStoreFilter(): void
    {
        [$repository, $selects, $connection] = $this->reportRepository();
        $connection->method('fetchOne')->willReturn(false);
        $repository->hasSimulatedData(new DateRange('2026-10-16', '2026-10-29', DateRange::PERIOD_CUSTOM), 2);

        $orderProbe = end($selects->calls);
        $mentionsStore = false;
        foreach ($orderProbe as [$name, $args]) {
            if (in_array($name, ['where', 'join'], true) && str_contains(json_encode($args), 'store_id')) {
                $mentionsStore = true;
            }
        }
        $this->assertTrue($mentionsStore, 'The simulated-order probe must be limited to the selected store.');
    }

    /**
     * Day maths is UTC whatever the PHP default timezone (DST changes are irrelevant)
     *
     * @dataProvider timezones
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('timezones')]
    public function testDayBoundariesAreUtcWhateverTheDefaultTimezone(string $timezone): void
    {
        date_default_timezone_set($timezone);

        $range = DateRange::fromInput('custom', '2026-10-31', '2026-11-02', gmmktime(12, 0, 0, 11, 5, 2026));
        $this->assertSame(['2026-10-31', '2026-11-01', '2026-11-02'], $range->days());
        $this->assertSame('2026-10-31 00:00:00', $range->startDateTime());
        $this->assertSame('2026-11-03 00:00:00', $range->endDateTimeExclusive());
        $spring = DateRange::fromInput('custom', '2026-03-28', '2026-03-30', gmmktime(12, 0, 0, 4, 5, 2026));
        $this->assertSame(['2026-03-28', '2026-03-29', '2026-03-30'], $spring->days());
        $preset = DateRange::fromInput('7', null, null, gmmktime(0, 30, 0, 11, 1, 2026));
        $this->assertSame(['2026-10-26', '2026-11-01'], [$preset->from(), $preset->to()]);

        $this->assertSame('2026-10-18', Rollup::cutoffDay(14, gmmktime(1, 0, 0, 11, 1, 2026)));
        $this->assertSame('2026-03-15', Rollup::cutoffDay(14, gmmktime(23, 59, 59, 3, 29, 2026)));
        $this->assertSame('2025-02-28', Retention::monthsCutoffDay(13, gmmktime(0, 0, 0, 3, 31, 2026)));
        $this->assertSame(gmmktime(0, 0, 0, 11, 1, 2026), TrafficGenerator::dayStart('2026-11-01'));
        $this->assertSame(gmmktime(0, 0, 0, 3, 29, 2026), TrafficGenerator::dayStart('2026-03-29'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function timezones(): array
    {
        return [
            'New York (DST ends 2026-11-01)' => ['America/New_York'],
            'London (DST starts 2026-03-29)' => ['Europe/London'],
            'Lord Howe (30 min DST)' => ['Australia/Lord_Howe'],
            'Kolkata (+05:30)' => ['Asia/Kolkata'],
            'Chatham (+12:45/+13:45)' => ['Pacific/Chatham'],
        ];
    }

    /**
     * TC4.02: generated rows depend only on seed, scenario, paths and day - not on generation order - and
     * stay inside their UTC day.
     */
    public function testTrafficGeneratorIsDeterministicAndOrderIndependent(): void
    {
        $paths = ['product' => ['/a.html', '/b.html', '/c.html'], 'category' => ['/cat.html'], 'cms' => ['/about']];
        $end = gmmktime(23, 59, 59, 10, 29, 2026);
        $first = new TrafficGenerator(Scenario::standard(), 7, 1, $paths, $end);
        $lateA = $first->generateDay('2026-10-29', 13, 14, 82, 83);
        $earlyA = $first->generateDay('2026-10-16', 0, 14, 82, 83);
        $second = new TrafficGenerator(Scenario::standard(), 7, 1, $paths, $end);
        $earlyB = $second->generateDay('2026-10-16', 0, 14, 82, 83);
        $lateB = $second->generateDay('2026-10-29', 13, 14, 82, 83);

        $this->assertSame($earlyA, $earlyB);
        $this->assertSame($lateA, $lateB);
        foreach (['hits', 'visits', 'landings'] as $kind) {
            foreach ($lateA[$kind] as $row) {
                $this->assertSame('2026-10-29', substr($row['created_at'], 0, 10));
                $this->assertSame(1, $row['is_simulated']);
            }
        }
        $other = (new TrafficGenerator(Scenario::standard(), 8, 1, $paths, $end))->generateDay('2026-10-16', 0, 14, 82, 83);
        $this->assertNotSame($earlyA['hits'], $other['hits']);
    }

    /**
     * Rollup wired to the recording connection stub
     *
     * @param int $hitDays
     * @param int $visitDays
     * @param PageTypeResolverInterface|null $resolver
     * @return Rollup
     */
    private function rollup(int $hitDays, int $visitDays, ?PageTypeResolverInterface $resolver = null): Rollup
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($name): string => '`' . $name . '`');
        $connection->method('query')->willReturnCallback(function (string $sql, array $bind = []) {
            $this->calls[] = ['query', $sql, $bind];
            if (str_contains($sql, 'SUM(CASE')) {
                return $this->statement($this->data['crawl']);
            }
            if (str_contains($sql, 'AS visits')) {
                return $this->statement($this->data['visit']);
            }
            if (str_contains($sql, 'ORDER BY')) {
                return $this->statement($this->data['path']);
            }
            return $this->statement($this->data['paths']);
        });
        $connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind = []) {
            $this->calls[] = ['fetchAll', $sql, $bind];
            if (str_contains($sql, 'url_rewrite')) {
                $values = array_map('strtolower', array_slice($bind, 4));
                return array_values(array_filter(
                    $this->data['rewrite'],
                    static fn (array $row): bool => in_array(strtolower($row['request_path']), $values, true)
                ));
            }
            return $this->data['overflow'];
        });
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $bind = []) {
            $this->calls[] = ['fetchOne', $sql, $bind];
            return $this->rawProbe !== null ? ($this->rawProbe)($sql, $bind) : '1';
        });
        $connection->method('fetchRow')->willReturn(false);
        $connection->method('delete')->willReturnCallback(function (string $table, $where = '') {
            $this->calls[] = ['delete', $table, (array)$where];
            return 1;
        });
        $connection->method('insertMultiple')->willReturnCallback(function (string $table, array $rows) {
            $this->calls[] = ['insertMultiple', $table, $rows];
            return count($rows);
        });
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $connection->method($method)->willReturnCallback(function () use ($method, $connection) {
                $this->calls[] = [$method, '', []];
                return $connection;
            });
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        if ($resolver === null) {
            $resolver = $this->createStub(PageTypeResolverInterface::class);
            $resolver->method('resolve')->willReturn(PageTypeResolverInterface::TYPE_OTHER);
        }
        $config = $this->createStub(Config::class);
        $config->method('hitRetentionDays')->willReturn($hitDays);
        $config->method('rawVisitRetentionDays')->willReturn($visitDays);
        $config->method('urlStoreCode')->willReturn('');
        return new Rollup($resource, $resolver, $config);
    }

    /**
     * Delete calls as [table, where]
     *
     * @return array<int, array{0: string, 1: array}>
     */
    private function deletes(): array
    {
        $result = [];
        foreach ($this->calls as [$method, $table, $where]) {
            if ($method === 'delete') {
                $result[] = [$table, $where];
            }
        }
        return $result;
    }

    /**
     * Rows inserted into a table
     *
     * @param string $table
     * @return array<int, array<string, mixed>>
     */
    private function inserted(string $table): array
    {
        $rows = [];
        foreach ($this->calls as [$method, $name, $data]) {
            if ($method === 'insertMultiple' && $name === $table) {
                $rows = array_merge($rows, $data);
            }
        }
        return $rows;
    }

    /**
     * Statement stub returning the rows one by one
     *
     * @param array $rows
     * @return \Zend_Db_Statement_Interface
     */
    private function statement(array $rows): \Zend_Db_Statement_Interface
    {
        $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
        $statement->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
        return $statement;
    }

    /**
     * Grouped crawl row as MySQL returns it
     *
     * @param string $store
     * @param string $sim
     * @param string $bot
     * @param string $category
     * @param string $hash
     * @param int $hits
     * @return array<string, string>
     */
    private function crawlGroup(string $store, string $sim, string $bot, string $category, string $hash, int $hits): array
    {
        return [
            'store_id' => $store, 'is_simulated' => $sim, 'bot_code' => $bot, 'category' => $category,
            'path_hash' => $hash, 'hits' => (string)$hits, 's2xx' => (string)$hits, 's3xx' => '0', 's4xx' => '0',
            's5xx' => '0', 'total_ms' => '10', 'max_ms' => '10',
        ];
    }

    /**
     * ReportRepository on a connection whose selects record every builder call
     *
     * @return array{0: ReportRepository, 1: \stdClass, 2: AdapterInterface}
     */
    private function reportRepository(): array
    {
        $log = new \stdClass();
        $log->calls = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(function () use ($log) {
            $index = count($log->calls);
            $log->calls[$index] = [];
            $select = $this->createStub(Select::class);
            foreach (['from', 'where', 'group', 'order', 'limit', 'join', 'joinLeft', 'columns'] as $method) {
                $select->method($method)->willReturnCallback(function (...$args) use ($select, $index, $method, $log) {
                    $log->calls[$index][] = [$method, $args];
                    return $select;
                });
            }
            return $select;
        });
        $connection->method('fetchAll')->willReturn([]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $rules = $this->createStub(RuleProvider::class);
        $rules->method('botRules')->willReturn([]);
        $config = $this->createStub(Config::class);
        $config->method('customBotPatterns')->willReturn([]);
        $repository = new ReportRepository($resource, new BotDirectory(new BotClassifier($config, $rules)));
        return [$repository, $log, $connection];
    }
}
