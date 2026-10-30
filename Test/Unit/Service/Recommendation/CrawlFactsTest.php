<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Service\Recommendation\CrawlFacts;
use Panth\AiVisibility\Service\DataScope;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\StockStatusLookup;
use PHPUnit\Framework\TestCase;

class CrawlFactsTest extends TestCase
{
    /**
     * @var DataScope|null
     */
    private ?DataScope $scope = null;

    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $queries = [];

    /**
     * @var array<int, mixed>
     */
    private array $results = [];

    protected function setUp(): void
    {
        $this->queries = [];
        $this->results = [];
    }

    /**
     * @dataProvider storeCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storeCases')]
    public function testHitSummaryFiltersStoreSimulatedRowsAndBotCategories(int $storeId, bool $filtered): void
    {
        $this->results = [['hits' => '42', 'paths' => '7', 'bots' => '3']];
        $summary = $this->facts()->hitSummary($storeId, '2026-03-01 00:00:00', '2026-03-08 00:00:00', 404, 404);

        $this->assertSame(['hits' => 42, 'paths' => 7, 'bots' => 3], $summary);
        [$sql, $bind] = $this->queries[0];
        $this->assertStringContainsString('h.is_simulated = 0', $sql);
        $this->assertStringContainsString('h.created_at >= :from AND h.created_at < :to', $sql);
        $this->assertSame('2026-03-01 00:00:00', $bind['from']);
        $this->assertSame('2026-03-08 00:00:00', $bind['to']);
        $this->assertSame(404, $bind['status_min']);
        $this->assertSame(404, $bind['status_max']);
        $this->assertSame(['training', 'search', 'live', 'other'], [$bind['hc0'], $bind['hc1'], $bind['hc2'], $bind['hc3']]);
        $this->assertSame($filtered, str_contains($sql, 'h.store_id = :store'));
        $this->assertSame($filtered ? $storeId : null, $bind['store'] ?? null);
    }

    public static function storeCases(): array
    {
        return [
            'one store view' => [2, true],
            'all store views' => [0, false],
        ];
    }

    public function testAnyStatusAddsNoStatusFilterAndExclusionsAreBound(): void
    {
        $this->results = [false];
        $summary = $this->facts()->hitSummary(1, 'a', 'b', 0, 0, ['/robots.txt'], ['/.well_known/']);

        $this->assertSame(['hits' => 0, 'paths' => 0, 'bots' => 0], $summary);
        [$sql, $bind] = $this->queries[0];
        $this->assertStringNotContainsString('h.status', $sql);
        $this->assertStringContainsString('h.path NOT IN (:xp0)', $sql);
        $this->assertStringContainsString('h.path NOT LIKE :xl0', $sql);
        $this->assertSame('/robots.txt', $bind['xp0']);
        $this->assertSame('/.well\\_known/%', $bind['xl0']);
    }

    public function testTopPathsAreGroupedByHashTypedAndLimitIsClamped(): void
    {
        $this->results = [[['path' => '/a.html', 'hits' => '9', 'bots' => '2', 'avg_ms' => '120',
            'max_status' => '404', 'last_seen' => '2026-03-07 10:00:00']]];
        $rows = $this->facts()->topHitPaths(1, 'a', 'b', 404, 404, 100000);

        $this->assertSame([[
            'path' => '/a.html', 'hits' => 9, 'bots' => 2, 'avg_ms' => 120, 'max_status' => 404,
            'last_seen' => '2026-03-07 10:00:00',
        ]], $rows);
        $sql = $this->queries[0][0];
        // Per path and bot first: no index starts with (path_hash, bot_code), so the window is scanned once
        $this->assertStringContainsString('GROUP BY h.path_hash, h.bot_code) AS g', $sql);
        $this->assertStringContainsString('GROUP BY g.path_hash', $sql);
        $this->assertStringContainsString('COUNT(*) AS bots', $sql);
        $this->assertStringContainsString('ROUND(SUM(g.total_ms) / SUM(g.hits)) AS avg_ms', $sql);
        $this->assertStringNotContainsString('COUNT(DISTINCT', $sql);
        $this->assertStringContainsString('MAX(h.hit_id) AS hit_id', $sql);
        $this->assertStringNotContainsString('MAX(h.path)', $sql, 'the path text stays out of the temporary table');
        $this->assertStringContainsString('INNER JOIN panth_aiv_hit AS p ON p.hit_id = x.hit_id', $sql);
        $this->assertStringContainsString('ORDER BY x.hits DESC, CHAR_LENGTH(p.path) ASC, p.path ASC', $sql);
        $this->assertStringEndsWith('LIMIT ' . CrawlFacts::MAX_LIMIT, $sql);
    }

    public function testHitCountCountsTheWindowWithoutDistinctCounts(): void
    {
        $this->results = ['1234'];
        $this->assertSame(1234, $this->facts()->hitCount(2, '2026-03-01 00:00:00', '2026-03-08 00:00:00'));
        [$sql, $bind] = $this->queries[0];
        $this->assertStringStartsWith('SELECT COUNT(*) FROM panth_aiv_hit AS h WHERE ', $sql);
        $this->assertStringContainsString('h.created_at >= :from AND h.created_at < :to', $sql);
        $this->assertStringContainsString('h.is_simulated = 0', $sql);
        $this->assertStringNotContainsString('h.status', $sql);
        $this->assertSame(2, $bind['store']);
    }

    public function testTopBotsMapsCodeToHits(): void
    {
        $this->results = [[['bot_code' => 'gptbot', 'hits' => '5'], ['bot_code' => 'claudebot', 'hits' => '2']]];
        $this->assertSame(['gptbot' => 5, 'claudebot' => 2], $this->facts()->topHitBots(0, 'a', 'b', 500, 599, 5));
        $this->assertStringContainsString('LIMIT 5', $this->queries[0][0]);
        $this->assertStringContainsString('GROUP BY h.bot_code, h.path_hash) AS g', $this->queries[0][0]);
        $this->assertStringContainsString('GROUP BY g.bot_code ORDER BY hits DESC, bot_code ASC', $this->queries[0][0]);
    }

    public function testSlowPathsUsesTimedHitsThresholdAndMinimumHits(): void
    {
        $this->results = [[
            ['path' => '/slow', 'hits' => '20', 'bots' => '1', 'avg_ms' => '2500', 'max_ms' => '4000',
                'paths_total' => '2', 'hits_total' => '30'],
        ]];
        $slow = $this->facts()->slowPaths(3, 'a', 'b', 2000, 3, 50);

        $this->assertSame(2, $slow['paths']);
        $this->assertSame(30, $slow['hits']);
        $this->assertSame([['path' => '/slow', 'hits' => 20, 'bots' => 1, 'avg_ms' => 2500, 'max_ms' => 4000]], $slow['rows']);
        $this->assertCount(1, $this->queries, 'totals and rows come from one pass');
        [$sql, $bind] = $this->queries[0];
        $this->assertStringContainsString('h.duration_ms > 0', $sql);
        $this->assertStringContainsString(
            'HAVING SUM(g.hits) >= :min_hits AND SUM(g.total_ms) / SUM(g.hits) > :threshold',
            $sql
        );
        $this->assertStringContainsString('COUNT(*) OVER () AS paths_total, SUM(x.hits) OVER () AS hits_total', $sql);
        $this->assertStringContainsString('GROUP BY h.path_hash, h.bot_code) AS g', $sql);
        $this->assertStringContainsString('ORDER BY y.hits DESC, y.avg_ms DESC, CHAR_LENGTH(p.path) ASC, p.path ASC', $sql);
        $this->assertStringEndsWith('LIMIT 50', $sql);
        $this->assertStringContainsString('h.is_simulated = 0', $sql);
        $this->assertSame(3, $bind['min_hits']);
        $this->assertSame(2000, $bind['threshold']);
        $this->assertSame(3, $bind['store']);
    }

    public function testSlowPathsWithoutQualifyingPathsIsEmpty(): void
    {
        $this->results = [[]];
        $this->assertSame(['paths' => 0, 'hits' => 0, 'rows' => []], $this->facts()->slowPaths(0, 'a', 'b', 2000, 3, 50));
    }

    public function testCrawlDataStartIgnoresSimulatedRows(): void
    {
        $this->results = ['2026-01-05'];
        $this->assertSame('2026-01-05', $this->facts()->crawlDataStart(1));
        $this->assertStringContainsString('d.is_simulated = 0', $this->queries[0][0]);
        $this->assertSame(1, $this->queries[0][1]['store']);
        $this->assertStringContainsString('FROM panth_aiv_entity_daily', $this->queries[0][0]);
        $this->assertStringContainsString("d.entity_type = 'product'", $this->queries[0][0]);
        $this->assertStringNotContainsString('panth_aiv_crawl_daily', $this->queries[0][0]);

        $this->queries = [];
        $this->results = [null];
        $this->assertNull($this->facts()->crawlDataStart(0));
        $this->assertArrayNotHasKey('store', $this->queries[0][1]);
    }

    public function testBotHitsByCodeFillsMissingCodes(): void
    {
        $this->results = [[['bot_code' => 'oai_searchbot', 'hits' => '12']]];
        $hits = $this->facts()->botHitsByCode(2, ['oai_searchbot', 'perplexitybot'], '2026-02-01', '2026-03-02');

        $this->assertSame(['oai_searchbot' => 12, 'perplexitybot' => 0], $hits);
        [$sql, $bind] = $this->queries[0];
        $this->assertStringContainsString('d.is_simulated = 0', $sql);
        $this->assertSame('oai_searchbot', $bind['b0']);
        $this->assertSame(2, $bind['store']);
        $this->assertSame([], $this->facts()->botHitsByCode(2, [], 'a', 'b'));
    }

    public function testProductCoverageForAStoreViewJoinsWebsiteStockAndExcludesSimulatedCrawls(): void
    {
        $this->results = ['96', '97', '500', '40', [11, 12]];
        $coverage = $this->facts()->productCoverage(2, '2025-12-01', '2026-02-15 00:00:00', 2);

        $this->assertSame(['eligible' => 500, 'never' => 40, 'ids' => [11, 12]], $coverage);
        [$countSql, $countBind] = $this->queries[2];
        $this->assertStringContainsString('catalog_product_website', $countSql);
        $this->assertStringContainsString('AND <in stock for store 2>', $countSql);
        $this->assertStringNotContainsString('cataloginventory_stock_item', $countSql);
        $this->assertStringContainsString('e.created_at < :created_before', $countSql);
        $this->assertSame(1, $countBind['website']);
        [$neverSql, $neverBind] = $this->queries[3];
        $this->assertStringContainsString('NOT EXISTS (SELECT 1 FROM panth_aiv_entity_daily', $neverSql);
        $this->assertStringContainsString('p.is_simulated = 0', $neverSql);
        $this->assertSame(2, $neverBind['p_store']);
        $this->assertSame('2025-12-01', $neverBind['since']);
        $this->assertStringEndsWith('LIMIT 2', $this->queries[4][0]);
    }

    public function testProductCoverageForAllStoresSkipsWebsiteAndStopsWhenNothingIsEligible(): void
    {
        $this->results = ['96', '97', '0'];
        $coverage = $this->facts()->productCoverage(0, '2025-12-01', '2026-02-15 00:00:00', 10);

        $this->assertSame(['eligible' => 0, 'never' => 0, 'ids' => []], $coverage);
        $this->assertCount(3, $this->queries);
        $this->assertStringNotContainsString('catalog_product_website', $this->queries[2][0]);
    }

    public function testProductCoverageWithoutAttributesReturnsEmpty(): void
    {
        $this->results = ['0', '0'];
        $this->assertSame(['eligible' => 0, 'never' => 0, 'ids' => []], $this->facts()->productCoverage(0, 'a', 'b', 5));
    }

    /**
     * Facts with an adapter that records every query and answers from $this->results in order
     *
     * @return CrawlFacts
     */
    private function facts(): CrawlFacts
    {
        $next = function (string $sql, array $bind = []) {
            $this->queries[] = [$sql, $bind];
            return array_shift($this->results);
        };
        $adapter = $this->createStub(AdapterInterface::class);
        foreach (['fetchRow', 'fetchAll', 'fetchOne', 'fetchCol'] as $method) {
            $adapter->method($method)->willReturnCallback($next);
        }
        $adapter->method('tableColumnExists')->willReturn(false);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $stock = $this->createStub(StockStatusLookup::class);
        $stock->method('inStockCondition')->willReturnCallback(
            static fn (int $storeId, string $alias): string => '<in stock for store ' . $storeId . '>'
        );
        return new CrawlFacts($resource, $storeManager, $stock, $this->scope ?? new DataScope());
    }

    public function testSimulatedPreviewScopeIncludesSimulatedCrawls(): void
    {
        $this->scope = new DataScope();
        $this->scope->includeSimulated(true);
        $this->results = [null];
        $this->facts()->crawlDataStart(1);

        $this->assertStringContainsString('d.is_simulated IN (0, 1)', $this->queries[0][0]);
    }
}
