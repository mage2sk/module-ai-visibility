<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\Rule\LiveAnswersNoSales;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\RuleSupport;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use Panth\AiVisibility\Service\VisitWriter;
use PHPUnit\Framework\TestCase;

class LiveAnswersNoSalesTest extends TestCase
{
    use RuleDbTrait;

    private function rule(int $minVolume = 20): LiveAnswersNoSales
    {
        $catalog = $this->createStub(CatalogLookup::class);
        $catalog->method('entityNames')->willReturn(['product:7' => 'Trail Shoe']);
        return new LiveAnswersNoSales($this->support($minVolume), $this->logger(), $catalog);
    }

    /**
     * The database answers with the pages that already passed the visit and order filters
     */
    private function seed(): void
    {
        $this->route('fetchAll', 'FROM `panth_aiv_path_daily`', [
            ['path_hash' => sha1('/guide'), 'path' => '/guide', 'entity_type' => 'cms', 'entity_id' => 3,
                'fetches' => 6, 'visits' => 5],
            ['path_hash' => sha1('/shoe.html'), 'path' => '/shoe.html', 'entity_type' => 'product', 'entity_id' => 7,
                'fetches' => 40, 'visits' => 15],
        ]);
    }

    public function testFlagsPagesWithLiveFetchesAndVisitsButNoOrders(): void
    {
        $this->seed();
        $findings = $this->rule()->evaluate(1, self::today());

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame(LiveAnswersNoSales::CODE, $finding->ruleCode);
        $this->assertSame(1, $finding->storeId);
        $this->assertSame(Finding::SUBJECT_PATH, $finding->subjectType);
        $this->assertSame(MetricProviderInterface::METRIC_AI_CONVERSION, $finding->metric);
        $this->assertSame(['/shoe.html', '/guide'], $finding->subjects);
        $this->assertSame(20, $finding->affectedCount);
        $this->assertGreaterThan(0, $finding->impactScore);
        $this->assertStringContainsString('2 pages', $finding->title);
        $this->assertSame(46, $finding->evidence['live_fetches']);
        $this->assertSame(20, $finding->evidence['ai_visits']);
        $this->assertSame('Trail Shoe', $finding->evidence['examples'][0]['name']);
        $this->assertSame('2026-10-01', $finding->evidence['from']);
        $this->assertSame('2026-10-30', $finding->evidence['to']);
    }

    public function testBelowMinimumVolumeFindsNothing(): void
    {
        $this->seed();
        $this->assertSame([], $this->rule(21)->evaluate(1, self::today()));
    }

    public function testEmptyDataStopsAfterTheFirstQuery(): void
    {
        $this->assertSame([], $this->rule()->evaluate(1, self::today()));
        $this->assertCount(1, $this->queries);
    }

    /**
     * Live 200k run: pages with visitors were lost because only the 500 most fetched pages were kept before
     * visits and orders were looked at. Visits and orders are now joined in the same statement, before
     * the LIMIT, and the cut keeps the most visited pages.
     */
    public function testVisitsAndOrdersAreFilteredBeforeTheLimit(): void
    {
        $this->seed();
        $this->rule()->evaluate(2, self::today());

        $this->assertCount(1, $this->queries);
        $sql = $this->queries[0]['sql'];
        $limit = strpos($sql, 'LIMIT ' . RuleSupport::SCAN_LIMIT);
        $this->assertNotFalse($limit);
        $visits = strpos($sql, 'INNER JOIN (SELECT v.path_hash, COUNT(*) AS visits FROM `panth_aiv_visit`');
        $orders = strpos($sql, 'LEFT JOIN (SELECT SHA1(o.landing_path) AS path_hash FROM `panth_aiv_order`');
        $this->assertNotFalse($visits);
        $this->assertNotFalse($orders);
        $this->assertLessThan($limit, $visits);
        $this->assertLessThan($limit, $orders);
        $this->assertStringContainsString('HAVING COUNT(*) >= ?', $sql);
        $this->assertStringContainsString('os.path_hash IS NULL', $sql);
        $this->assertStringContainsString('ORDER BY u.visits DESC, u.fetches DESC', $sql);
    }

    /**
     * panth_aiv_path_daily keeps only the top 500 paths per day and category, so on a store with more than
     * 500 live-fetched pages a day the long tail had no live fetches at all. Product and category pages
     * now read their live fetches from panth_aiv_entity_daily (uncapped); path_daily is only used for the
     * other page types, and never counts a resolved product or category page twice.
     */
    public function testProductAndCategoryLiveFetchesComeFromTheUncappedEntityTable(): void
    {
        $this->rule()->evaluate(2, self::today());

        $sql = $this->queries[0]['sql'];
        [$entityPart, $otherPart] = explode(' UNION ALL ', $sql, 2);
        $this->assertStringContainsString('INNER JOIN `panth_aiv_entity_daily` AS e', $entityPart);
        $this->assertStringContainsString('ON e.entity_type = em.entity_type AND e.entity_id = em.entity_id', $entityPart);
        $this->assertStringContainsString('e.category = ?', $entityPart);
        $this->assertStringContainsString('FROM `panth_aiv_visit_path_daily` AS vp', $entityPart);
        $this->assertStringContainsString('vp.entity_type IN (?, ?)', $entityPart);
        $this->assertStringNotContainsString('panth_aiv_path_daily', $entityPart);
        $this->assertStringContainsString('FROM `panth_aiv_path_daily` AS p', $otherPart);
        $this->assertStringContainsString('ex.path_hash IS NULL', $otherPart);
        $this->assertSame(substr_count($sql, '?'), count($this->queries[0]['bind']));
    }

    /**
     * 600 product pages fetched live every day (more than the 500 path_daily keeps per day): every page
     * with enough visits counts, the most visited first, up to MAX_SUBJECTS subjects.
     */
    public function testMoreThan500LivePagesADayAreAllCounted(): void
    {
        $rows = [];
        for ($i = 1; $i <= 600; $i++) {
            $path = '/product-' . $i . '.html';
            // one live fetch a day for 30 days: never in the daily top 500 of path_daily when 600 pages tie
            $rows[] = ['path_hash' => sha1($path), 'path' => $path, 'entity_type' => 'product', 'entity_id' => $i,
                'fetches' => 30, 'visits' => 5 + ($i % 7)];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['visits'], $a['path_hash']] <=> [$a['visits'], $b['path_hash']]);
        $this->route('fetchAll', 'INNER JOIN `panth_aiv_entity_daily`', array_slice($rows, 0, RuleSupport::SCAN_LIMIT));

        $findings = $this->rule()->evaluate(2, self::today());

        $this->assertCount(1, $findings);
        $this->assertCount(RuleSupport::MAX_SUBJECTS, $findings[0]->subjects);
        $this->assertSame(RuleSupport::MAX_SUBJECTS, $findings[0]->evidence['pages']);
        $this->assertSame(RuleSupport::MAX_SUBJECTS * 30, $findings[0]->evidence['live_fetches']);
        $this->assertSame(11, $findings[0]->evidence['examples'][0]['ai_visits']);
    }

    public function testStoreFilterAndRealRowsOnly(): void
    {
        $this->seed();
        $this->rule()->evaluate(2, self::today());

        $this->assertRealRowsOnly(['panth_aiv_path_daily']);
        $sql = $this->queries[0]['sql'];
        foreach (['p.store_id = ?', 'v.store_id = ?', 'so.store_id = ?', 'so.state <> ?', 'p.is_simulated = 0',
            'v.is_simulated = 0', 'o.is_simulated = 0', 'e.store_id = ?', 'e.is_simulated = 0',
            'vp.store_id = ?', 'vp.is_simulated = 0'] as $fragment) {
            $this->assertStringContainsString($fragment, $sql);
        }
        $visits = ['2026-10-01 00:00:00', '2026-10-31 00:00:00', VisitWriter::CANDIDATE_PREFIX . '%', 2, 5];
        $map = ['2026-10-01', '2026-10-30', 'product', 'category', 2];
        $orders = ['2026-10-01 00:00:00', '2026-10-31 00:00:00', 'canceled', 2];
        $live = ['2026-10-01', '2026-10-30', 'live', 2, 5];
        $this->assertSame(
            array_merge($visits, $map, $orders, $live, $visits, $orders, $map, $live),
            $this->queries[0]['bind']
        );
    }

    public function testAllStoresHaveNoStoreFilter(): void
    {
        $this->seed();
        $this->assertCount(1, $this->rule()->evaluate(0, self::today()));
        foreach ($this->queries as $query) {
            $this->assertStringNotContainsString('store_id = ?', $query['sql']);
            $this->assertSame(substr_count($query['sql'], '?'), count($query['bind']));
        }
    }

    public function testSimulatedPreviewReadsSimulatedRowsEverywhere(): void
    {
        $this->scope = new \Panth\AiVisibility\Service\DataScope();
        $this->scope->includeSimulated(true);
        $this->seed();
        $this->rule()->evaluate(1, self::today());

        $this->assertSame(0, substr_count($this->queries[0]['sql'], 'is_simulated = 0'));
        $this->assertSame(8, substr_count($this->queries[0]['sql'], 'is_simulated IN (0, 1)'));
    }

    /**
     * A failure is not swallowed: the engine keeps the open recommendation and reports the error
     */
    public function testQueryFailureReachesTheEngine(): void
    {
        $catalog = $this->createStub(CatalogLookup::class);
        $catalog->method('entityNames')->willThrowException(new \RuntimeException('boom'));
        $this->route('fetchAll', 'FROM `panth_aiv_path_daily`', [
            ['path_hash' => 'x', 'path' => '/x', 'entity_type' => null, 'entity_id' => null, 'fetches' => 9,
                'visits' => 50],
        ]);
        $rule = new LiveAnswersNoSales($this->support(), $this->logger(), $catalog);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $rule->evaluate(1, self::today());
    }
}
