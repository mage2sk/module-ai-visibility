<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\DataScope;
use Panth\AiVisibility\Service\Email\WeeklySendMarker;
use Panth\AiVisibility\Service\Recommendation\CrawlFacts;
use Panth\AiVisibility\Service\Retention;
use Panth\AiVisibility\Service\Rollup;
use Panth\AiVisibility\Service\Verification\ImpactCalculator;
use Panth\AiVisibility\Service\Verification\MetricProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deep test E5 (1.1): data migration 1.0 -> 1.1 and the daily pipeline with the new tables.
 *
 * Context (verified on a throwaway MySQL 8.0 copy of the 1.0 backup with the 1.1 DDL applied):
 * - setup:upgrade only adds columns / tables: panth_aiv_path_daily s4xx / s5xx / total_ms / timed_hits are 0
 *   on every 1.0 row and panth_aiv_entity_daily starts empty. No data patch re-rolls history.
 * - The rollup cron rebuilds today and yesterday only; days still inside the raw window are filled only
 *   when the merchant runs panth:aivisibility:rollup --days=N; days before the raw floor keep their
 *   1.0 rows and never get entity_daily rows (by the per-slice safety rule).
 */
class V11PipelineTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../..';

    /**
     * DEFECT (major): R4 never_crawled_products checks "crawler data goes back MIN_DATA_DAYS days" with
     * crawlDataStart(), which reads panth_aiv_crawl_daily (full 1.0 history), but counts products as
     * fetched from panth_aiv_entity_daily (new in 1.1, empty for every day before the upgrade). Right after
     * an upgrade from 1.0 every product not fetched in the last one or two days is reported as "never
     * fetched by an AI bot in 90 days". The guard must use the first day of the table the coverage reads.
     */
    public function testR4HistoryGuardMustNotPredateTheEntityDailyItCounts(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql): string {
            if (str_contains($sql, 'panth_aiv_entity_daily')) {
                return '2026-10-29'; // first 1.1 rollup (cron: yesterday + today)
            }
            if (str_contains($sql, 'panth_aiv_crawl_daily')) {
                return '2025-10-01'; // 13 months of 1.0 history
            }
            return '';
        });
        $facts = $this->crawlFacts($connection);

        $start = $facts->crawlDataStart(1);

        $this->assertNotNull($start);
        $this->assertGreaterThanOrEqual(
            '2026-10-29',
            $start,
            'R4 would run on a store upgraded from 1.0 although per-product history only starts on 2026-10-29'
        );
    }

    /**
     * DEFECT (minor): verification checks that the raw tables still cover the before window, but not the
     * daily summary retention. Daily retention may be 1 month while the window may be 56 days, so the
     * before window of a product / path / site crawl metric can be pruned (read as 0) and the verdict is
     * computed on missing data instead of "insufficient (data expired)".
     */
    public function testVerificationDetectsBeforeWindowPrunedByDailyRetention(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('hitRetentionDays')->willReturn(14);
        $config->method('rawVisitRetentionDays')->willReturn(30);
        $config->method('dailyRetentionMonths')->willReturn(1);
        $calculator = (new \ReflectionClass(ImpactCalculator::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($config): void {
            $this->config = $config;
        }, $calculator, ImpactCalculator::class)();
        $method = new \ReflectionMethod(ImpactCalculator::class, 'expiredSource');
        $method->setAccessible(true);

        $today = gmmktime(0, 0, 0, 10, 30, 2026);
        // window 56: applied 2026-09-03, before window starts 2026-07-09 (113 days ago)
        $expired = $method->invoke($calculator, MetricProvider::METRIC_BOT_HITS, 'product', '2026-07-09', $today);

        $this->assertNotNull($expired, 'before window older than the daily retention is not reported as expired');
    }

    /**
     * Upgrade behaviour (documents the design): a 1.0 day before the raw floor that already has daily rows is
     * never rebuilt or refilled, even when raw rows of that day remain, so its panth_aiv_entity_daily rows
     * stay empty and its path_daily status columns stay 0. Nothing is deleted or inserted.
     */
    public function testUpgradedDayBeforeTheRawFloorIsLeftAsIs(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $bind = []) {
            if (str_contains($sql, 'MAX(value)')) {
                return '20261020';
            }
            if (str_contains($sql, Rollup::HIT_TABLE)) {
                return $bind[2] === 0 ? '1' : false; // real raw rows left on 2026-10-18, no simulated ones
            }
            if (str_contains($sql, Rollup::CRAWL_DAILY_TABLE)) {
                return '1'; // 1.0 daily rows exist
            }
            return false;
        });
        $connection->expects($this->never())->method('delete');
        $connection->expects($this->never())->method('insertMultiple');
        $connection->expects($this->never())->method('beginTransaction');

        $this->rollup($connection)->rollupDay('2026-10-18');
    }

    /**
     * After the upgrade a slice before the raw floor that never had daily rows is filled, entity rows
     * included; a filled slice is written without deleting other slices of the day.
     */
    public function testFillSliceBeforeTheFloorAlsoWritesEntityRowsWithoutDeletes(): void
    {
        $inserted = [];
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $bind = []) {
            if (str_contains($sql, 'MAX(value)')) {
                return '20261020';
            }
            if (str_contains($sql, Rollup::HIT_TABLE)) {
                return '1'; // raw rows in both slices
            }
            if (str_contains($sql, Rollup::CRAWL_DAILY_TABLE)) {
                return $bind[1] === 0 ? '1' : false; // real slice has 1.0 rows, simulated slice none
            }
            return false;
        });
        $rows = [
            'types' => [['store_id' => 1, 'path_hash' => sha1('/p.html'), 'path' => '/p.html']],
            'crawl' => [
                ['store_id' => 1, 'is_simulated' => 0, 'bot_code' => 'gptbot', 'category' => 'training',
                    'path_hash' => sha1('/p.html'), 'hits' => 5, 's2xx' => 5, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0,
                    'total_ms' => 50, 'max_ms' => 10],
                ['store_id' => 1, 'is_simulated' => 1, 'bot_code' => 'gptbot', 'category' => 'training',
                    'path_hash' => sha1('/p.html'), 'hits' => 2, 's2xx' => 2, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0,
                    'total_ms' => 20, 'max_ms' => 10],
            ],
            'path' => [
                ['store_id' => 1, 'is_simulated' => 0, 'category' => 'training', 'path_hash' => sha1('/p.html'),
                    'path' => '/p.html', 'hits' => 5, 's4xx' => 0, 's5xx' => 0, 'total_ms' => 50, 'timed_hits' => 5],
                ['store_id' => 1, 'is_simulated' => 1, 'category' => 'training', 'path_hash' => sha1('/p.html'),
                    'path' => '/p.html', 'hits' => 2, 's4xx' => 0, 's5xx' => 0, 'total_ms' => 20, 'timed_hits' => 2],
            ],
        ];
        $connection->method('query')->willReturnCallback(function (string $sql) use ($rows) {
            if (str_contains($sql, 'UNION') || str_contains($sql, 'MIN(path) AS path FROM')
                && !str_contains($sql, 'COUNT(*)')
            ) {
                return $this->statement($rows['types']);
            }
            if (str_contains($sql, 'bot_code')) {
                return $this->statement($rows['crawl']);
            }
            return $this->statement($rows['path']);
        });
        $connection->method('fetchAll')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, Rollup::URL_REWRITE_TABLE)) {
                return [['request_path' => 'p.html', 'entity_type' => 'product', 'entity_id' => 42,
                    'redirect_type' => 0]];
            }
            return [];
        });
        $connection->expects($this->never())->method('delete');
        $connection->method('insertMultiple')->willReturnCallback(
            static function (string $table, array $data) use (&$inserted): int {
                $inserted[$table] = array_merge($inserted[$table] ?? [], $data);
                return count($data);
            }
        );

        $this->rollup($connection)->rollupDay('2026-10-18');

        $this->assertArrayHasKey(Rollup::ENTITY_DAILY_TABLE, $inserted);
        foreach ([Rollup::CRAWL_DAILY_TABLE, Rollup::PATH_DAILY_TABLE, Rollup::ENTITY_DAILY_TABLE] as $table) {
            foreach ($inserted[$table] as $row) {
                $this->assertSame(1, (int)$row['is_simulated'], $table . ': only the empty slice is filled');
            }
        }
        $this->assertSame(
            [['day' => '2026-10-18', 'store_id' => 1, 'category' => 'training', 'entity_type' => 'product',
                'entity_id' => 42, 'hits' => 2, 'is_simulated' => 1]],
            $inserted[Rollup::ENTITY_DAILY_TABLE]
        );
    }

    /**
     * Upgrade compatibility: the new path_daily columns are NOT NULL DEFAULT 0 (1.0 rows read as 0, never
     * NULL), entity_daily has the PK the rollup deletes / inserts by, and the whitelist knows all of it.
     */
    public function testSchemaAdditionsAreAdditiveAndWhitelisted(): void
    {
        $xml = simplexml_load_file(self::MODULE_DIR . '/etc/db_schema.xml');
        $this->assertNotFalse($xml);
        $path = $xml->xpath('//table[@name="panth_aiv_path_daily"]')[0];
        foreach (['s4xx', 's5xx', 'total_ms', 'timed_hits'] as $column) {
            $node = $path->xpath('column[@name="' . $column . '"]')[0] ?? null;
            $this->assertNotNull($node, $column);
            $this->assertSame('false', (string)$node['nullable'], $column);
            $this->assertSame('0', (string)$node['default'], $column);
        }
        $entity = $xml->xpath('//table[@name="panth_aiv_entity_daily"]')[0];
        $pk = [];
        foreach ($entity->xpath('constraint[@*[local-name()="type"]="primary"]/column') as $c) {
            $pk[] = (string)$c['name'];
        }
        $this->assertSame(['day', 'store_id', 'category', 'entity_type', 'entity_id', 'is_simulated'], $pk);

        $whitelist = json_decode((string)file_get_contents(self::MODULE_DIR . '/etc/db_schema_whitelist.json'), true);
        foreach (['s4xx', 's5xx', 'total_ms', 'timed_hits'] as $column) {
            $this->assertTrue($whitelist['panth_aiv_path_daily']['column'][$column] ?? false, $column);
        }
        foreach (['panth_aiv_entity_daily', 'panth_aiv_recommendation', 'panth_aiv_action', 'panth_aiv_impact'] as $t) {
            $this->assertArrayHasKey($t, $whitelist);
        }
    }

    /**
     * Retention covers entity_daily with the daily months cutoff, and keeps the state counters.
     */
    public function testRetentionCoversEntityDailyAndKeepsStateCounters(): void
    {
        $this->assertContains(WeeklySendMarker::KEY, Retention::KEEP_COUNTERS);
        $deletes = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->method('fetchAll')->willReturn([]);
        $connection->method('query')->willReturn($this->statement([]));
        $connection->method('delete')->willReturnCallback(
            static function (string $table, array $where) use (&$deletes): int {
                $deletes[$table] = $where;
                return 0;
            }
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $config = $this->createStub(Config::class);
        $config->method('hitRetentionDays')->willReturn(14);
        $config->method('rawVisitRetentionDays')->willReturn(30);
        $config->method('landingRetentionDays')->willReturn(30);
        $config->method('dailyRetentionMonths')->willReturn(13);
        $rollup = $this->createStub(\Panth\AiVisibility\Api\RollupInterface::class);
        $logger = $this->createStub(\Psr\Log\LoggerInterface::class);

        $result = (new Retention($resource, $config, $rollup, $logger))->run(gmmktime(3, 10, 0, 10, 30, 2026));

        $this->assertSame(['day < ?' => '2025-09-30'], $deletes[Rollup::ENTITY_DAILY_TABLE]);
        $this->assertSame(['day < ?' => '2025-09-30'], $deletes[Rollup::PATH_DAILY_TABLE]);
        $this->assertSame('raw_floor_%', $deletes[Retention::COUNTER_TABLE]['counter_key NOT LIKE ?']);
        // 1.1 history tables (recommendations, applied fixes, impacts) are kept on purpose: no retention step.
        foreach (['panth_aiv_recommendation', 'panth_aiv_action', 'panth_aiv_impact'] as $table) {
            $this->assertArrayNotHasKey($table, $deletes);
            $this->assertArrayNotHasKey($table, $result);
        }
    }

    /**
     * Beyond-cap boundaries of the 1.1 path rows (strictly slower than 2000 ms average, any error).
     * Updated for the E5-4 bound: beyond the cap a path needs at least 3 error answers or 3 slow timed
     * hits (and at most Rollup::MAX_EXTRA_PATHS such paths are kept per store and day).
     *
     * @dataProvider beyondCap
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('beyondCap')]
    public function testKeepBeyondCapBoundaries(int $s4, int $s5, int $ms, int $timed, bool $keep): void
    {
        $this->assertSame(
            $keep,
            Rollup::keepBeyondCap(['s4xx' => $s4, 's5xx' => $s5, 'total_ms' => $ms, 'timed_hits' => $timed])
        );
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: bool}>
     */
    public static function beyondCap(): array
    {
        return [
            'exactly 2000 avg' => [0, 0, 6000, 3, false],
            'just above' => [0, 0, 6001, 3, true],
            'just above, only 2 timed hits' => [0, 0, 4001, 2, false],
            'untimed (1.0 rows)' => [0, 0, 0, 0, false],
            'one 404' => [1, 0, 10, 1, false],
            'one 5xx' => [0, 1, 0, 0, false],
            'three errors' => [2, 1, 10, 1, true],
        ];
    }

    /**
     * DOC GAP: nothing tells a 1.0 merchant that per-product counts and path error / speed columns start at
     * the upgrade, and that panth:aivisibility:rollup --days=<hit retention> fills the days still in the raw
     * window (older days stay without them).
     */
    public function testReadmeDocumentsTheUpgradeFrom10(): void
    {
        $readme = (string)file_get_contents(self::MODULE_DIR . '/README.md');
        $this->assertSame(1, preg_match('/upgrad\w* from 1\.0/i', $readme), 'README has no 1.0 -> 1.1 upgrade notes');
        $this->assertSame(1, preg_match('/rollup[^\n]*--days/i', $readme), 'README does not name rollup --days');
    }

    private function crawlFacts(AdapterInterface $connection): CrawlFacts
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $facts = (new \ReflectionClass(CrawlFacts::class))->newInstanceWithoutConstructor();
        $scope = new DataScope();
        \Closure::bind(function () use ($resource, $scope): void {
            $this->resource = $resource;
            $this->scope = $scope;
        }, $facts, CrawlFacts::class)();
        return $facts;
    }

    private function rollup(AdapterInterface $connection): Rollup
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $resolver = $this->createStub(PageTypeResolverInterface::class);
        $resolver->method('resolve')->willReturn(PageTypeResolverInterface::TYPE_PAGE);
        $config = $this->createStub(Config::class);
        $config->method('urlStoreCode')->willReturn('');
        return new Rollup($resource, $resolver, $config);
    }

    private function statement(array $rows): \Zend_Db_Statement_Interface
    {
        $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
        $statement->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
        return $statement;
    }
}
