<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Email;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Service\Email\WeeklySummaryBuilder;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;
use PHPUnit\Framework\TestCase;
use Panth\AiVisibility\Service\DataScope;
use Psr\Log\LoggerInterface;

/**
 * Shared mocks get expectations only in the tests that need them.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class WeeklySummaryBuilderTest extends TestCase
{
    /**
     * where() conditions of every select
     *
     * @var array<int, string>
     */
    private array $wheres = [];

    /**
     * @var DataScope
     */
    private DataScope $scope;

    /**
     * Results returned by fetchAll / fetchOne, in call order
     *
     * @var array<int, mixed>
     */
    private array $fetchAll = [];

    /**
     * @var array<int, mixed>
     */
    private array $fetchOne = [];

    protected function setUp(): void
    {
        $this->wheres = [];
        $this->scope = new DataScope();
        $this->fetchAll = [];
        $this->fetchOne = [];
    }

    /**
     * @dataProvider periodCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('periodCases')]
    public function testPeriodsAreCompleteUtcDays(string $now, array $expected): void
    {
        $ts = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->getTimestamp();

        $this->assertSame($expected, WeeklySummaryBuilder::periods($ts));
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function periodCases(): array
    {
        return [
            'monday morning' => ['2026-10-26 07:05:00', [
                'from' => '2026-10-19', 'to' => '2026-10-25', 'prev_from' => '2026-10-12', 'prev_to' => '2026-10-18',
            ]],
            'just before midnight' => ['2026-10-26 23:59:59', [
                'from' => '2026-10-19', 'to' => '2026-10-25', 'prev_from' => '2026-10-12', 'prev_to' => '2026-10-18',
            ]],
            'across the year end' => ['2027-01-04 07:00:00', [
                'from' => '2026-12-28', 'to' => '2027-01-03', 'prev_from' => '2026-12-21', 'prev_to' => '2026-12-27',
            ]],
            'across a leap day' => ['2028-03-02 00:00:00', [
                'from' => '2028-02-24', 'to' => '2028-03-01', 'prev_from' => '2028-02-17', 'prev_to' => '2028-02-23',
            ]],
        ];
    }

    public function testBuildsBothWeeksFromRealDataOnly(): void
    {
        $report = $this->createMock(ReportRepository::class);
        $simFlags = [];
        $report->method('crawlTotals')->willReturnCallback(
            static function (DateRange $range, int $store, bool $sim) use (&$simFlags): array {
                $simFlags[] = $sim;
                return $range->from() === '2026-10-19' ? ['hits' => 1200, 'live' => 300] : ['hits' => 1000, 'live' => 200];
            }
        );
        $report->method('visitTotal')->willReturnCallback(
            static function (DateRange $range, int $store, bool $sim) use (&$simFlags): int {
                $simFlags[] = $sim;
                return $range->from() === '2026-10-19' ? 45 : 30;
            }
        );
        $report->method('orderTotals')->willReturnCallback(
            static function (DateRange $range, int $store, bool $sim) use (&$simFlags): array {
                $simFlags[] = $sim;
                return $range->from() === '2026-10-19' ? ['orders' => 3, 'revenue' => 250.5] : ['orders' => 0, 'revenue' => 0.0];
            }
        );
        $report->expects($this->once())->method('topPaths')
            ->with($this->isInstanceOf(DateRange::class), 0, false, null, 5)
            ->willReturn([
                ['path' => '/blue-shirt.html', 'entity_type' => 'product', 'entity_id' => 7, 'hits' => 90],
                ['path' => '/about-us', 'entity_type' => null, 'entity_id' => null, 'hits' => 20],
            ]);
        $catalog = $this->createStub(CatalogLookup::class);
        $catalog->method('entityNames')->willReturn(['product:7' => 'Blue Shirt']);

        $recs = $this->createStub(RecommendationRepositoryInterface::class);
        $recs->method('listOpen')->willReturn([
            ['title' => 'Simulated one', 'rule_code' => 'bot_404', 'impact_score' => 99, 'is_simulated' => 1],
            ['title' => 'Fix 404s', 'rule_code' => 'bot_404', 'impact_score' => 80, 'affected_count' => 12, 'is_simulated' => 0],
            ['title' => 'Serve llms.txt', 'rule_code' => 'llms_not_served', 'impact_score' => 60, 'is_simulated' => 0],
            ['title' => 'Stock', 'rule_code' => 'ai_visitors_out_of_stock', 'impact_score' => 40, 'is_simulated' => 0],
            ['title' => 'Fourth real', 'rule_code' => 'bot_errors_or_slow', 'impact_score' => 10, 'is_simulated' => 0],
        ]);

        $sources = $this->createStub(SourceClassifierInterface::class);
        $sources->method('nameFor')->willReturnMap([['chatgpt', 'ChatGPT']]);

        $this->fetchAll = [
            [['source_code' => 'chatgpt', 'visits' => '40'], ['source_code' => 'cand:12', 'visits' => '5']],
            [[
                'verdict' => 'helped', 'effect' => '1.2300', 'ci_low' => '1.1000', 'ci_high' => '1.3800',
                'computed_at' => '2026-10-22 04:40:00', 'rule_code' => 'bot_404', 'metric' => 'bot_404',
                'note' => null, 'title' => null,
            ]],
            [['type' => 'bot', 'key_value' => 'NewBot/1.0', 'hits' => '33']],
        ];
        $this->fetchOne = ['4'];

        $summary = $this->builder($report, $recs, $sources, $catalog)->build(
            (new \DateTimeImmutable('2026-10-26 07:05:00', new \DateTimeZone('UTC')))->getTimestamp()
        );

        $this->assertSame(['current' => 1200, 'previous' => 1000], $summary['metrics']['crawler_hits']);
        $this->assertSame(['current' => 300, 'previous' => 200], $summary['metrics']['live_fetches']);
        $this->assertSame(['current' => 45, 'previous' => 30], $summary['metrics']['ai_visits']);
        $this->assertSame(['current' => 3, 'previous' => 0], $summary['metrics']['ai_orders']);
        $this->assertSame(['current' => 250.5, 'previous' => 0.0], $summary['metrics']['ai_revenue']);
        $this->assertSame(['current' => '250.50', 'previous' => '0.00'], $summary['money']);
        $this->assertSame([false, false, false, false, false, false], $simFlags);

        $this->assertSame('Blue Shirt', $summary['top_pages'][0]['label']);
        $this->assertSame('Product', $summary['top_pages'][0]['type']);
        $this->assertSame('/about-us', $summary['top_pages'][1]['label']);

        $this->assertSame(
            [['name' => 'ChatGPT', 'visits' => 40], ['name' => 'Unconfirmed source #12', 'visits' => 5]],
            $summary['top_sources']
        );
        $this->assertSame(['Fix 404s', 'Serve llms.txt', 'Stock'], array_column($summary['recommendations'], 'title'));
        $this->assertSame(12, $summary['recommendations'][0]['affected_count']);

        $this->assertSame('Fix for rule bot_404', $summary['verdicts'][0]['title']);
        $this->assertSame(1.23, $summary['verdicts'][0]['effect']);
        $this->assertSame(1.38, $summary['verdicts'][0]['ci_high']);
        $this->assertSame(['count' => 4, 'items' => [['type' => 'bot', 'key' => 'NewBot/1.0', 'hits' => 33]]], $summary['candidates']);

        // Every own query leaves simulated rows out.
        $this->assertContains('v.is_simulated = 0', $this->wheres);
        $this->assertContains('i.is_simulated = 0', $this->wheres);
        $this->assertContains('c.is_simulated = 0', $this->wheres);
        $this->assertContains('a.undone_at IS NULL', $this->wheres);
    }

    /**
     * Base currency codes of the store views the store manager returns
     *
     * @var string[]
     */
    private array $currencies = ['USD'];

    /**
     * Revenue is summed across store views; with different base currencies the email says so
     */
    public function testMixedBaseCurrenciesAreFlagged(): void
    {
        $report = $this->createStub(ReportRepository::class);
        $report->method('orderTotals')->willReturn(['orders' => 1, 'revenue' => 10.0]);
        $report->method('crawlTotals')->willReturn([]);
        $report->method('topPaths')->willReturn([]);
        $recs = $this->createStub(RecommendationRepositoryInterface::class);
        $args = [$report, $recs, $this->createStub(SourceClassifierInterface::class), $this->createStub(CatalogLookup::class)];

        $this->currencies = ['USD', 'USD'];
        $this->assertFalse($this->builder(...$args)->build(time())['mixed_currencies']);
        $this->currencies = ['USD', 'EUR'];
        $this->assertTrue($this->builder(...$args)->build(time())['mixed_currencies']);
    }

    /**
     * The real-only email never asks for simulated recommendations; the --simulated preview merges them by rank
     */
    public function testSimulatedPreviewReadsSimulatedRecommendationsToo(): void
    {
        $report = $this->createStub(ReportRepository::class);
        $report->method('orderTotals')->willReturn(['orders' => 0, 'revenue' => 0.0]);
        $report->method('crawlTotals')->willReturn([]);
        $report->method('topPaths')->willReturn([]);
        $calls = [];
        $recs = $this->createStub(RecommendationRepositoryInterface::class);
        $recs->method('listOpen')->willReturnCallback(
            static function (?int $store, int $limit, bool $simulated = false) use (&$calls): array {
                $calls[] = $simulated;
                return $simulated
                    ? [['title' => 'Sim top', 'rule_code' => 'bot_404', 'impact_score' => 90, 'rank_score' => 90, 'is_simulated' => 1]]
                    : [
                        ['title' => 'Real A', 'rule_code' => 'llms_not_served', 'impact_score' => 95, 'rank_score' => 95, 'is_simulated' => 0],
                        ['title' => 'Real B', 'rule_code' => 'bot_errors_or_slow', 'impact_score' => 50, 'rank_score' => 50, 'is_simulated' => 0],
                    ];
            }
        );
        $args = [$report, $recs, $this->createStub(SourceClassifierInterface::class), $this->createStub(CatalogLookup::class)];

        $summary = $this->builder(...$args)->build(time());
        $this->assertSame([false], $calls);
        $this->assertSame(['Real A', 'Real B'], array_column($summary['recommendations'], 'title'));

        $calls = [];
        $this->scope->includeSimulated(true);
        $summary = $this->builder(...$args)->build(time());
        $this->assertSame([false, true], $calls);
        $this->assertSame(['Real A', 'Sim top', 'Real B'], array_column($summary['recommendations'], 'title'));
    }

    public function testFailingSectionIsLoggedAndLeftEmpty(): void
    {
        $report = $this->createStub(ReportRepository::class);
        $report->method('crawlTotals')->willThrowException(new \RuntimeException('table missing'));
        $report->method('visitTotal')->willReturn(8);
        $report->method('orderTotals')->willReturn(['orders' => 0, 'revenue' => 0.0]);
        $report->method('topPaths')->willReturn([]);
        $recs = $this->createStub(RecommendationRepositoryInterface::class);
        $recs->method('listOpen')->willThrowException(new \RuntimeException('no table'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeast(2))->method('warning');
        $this->fetchAll = [[], [], []];
        $this->fetchOne = ['0'];

        $summary = $this->builder(
            $report,
            $recs,
            $this->createStub(SourceClassifierInterface::class),
            $this->createStub(CatalogLookup::class),
            $logger
        )->build(time());

        $this->assertSame(['current' => 0, 'previous' => 0], $summary['metrics']['crawler_hits']);
        $this->assertSame(['current' => 8, 'previous' => 8], $summary['metrics']['ai_visits']);
        $this->assertSame([], $summary['recommendations']);
        $this->assertSame(['count' => 0, 'items' => []], $summary['candidates']);
    }

    /**
     * Builder with a fake connection that answers from the fetch queues
     *
     * @param ReportRepository $report
     * @param RecommendationRepositoryInterface $recs
     * @param SourceClassifierInterface $sources
     * @param CatalogLookup $catalog
     * @param LoggerInterface|null $logger
     * @return WeeklySummaryBuilder
     */
    private function builder(
        ReportRepository $report,
        RecommendationRepositoryInterface $recs,
        SourceClassifierInterface $sources,
        CatalogLookup $catalog,
        ?LoggerInterface $logger = null
    ): WeeklySummaryBuilder {
        $select = $this->createStub(Select::class);
        foreach (['from', 'join', 'joinLeft', 'group', 'order', 'limit', 'columns'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('where')->willReturnCallback(function ($condition) use ($select) {
            $this->wheres[] = (string)$condition;
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturnCallback(function () {
            return array_shift($this->fetchAll) ?? [];
        });
        $connection->method('fetchOne')->willReturnCallback(function () {
            return array_shift($this->fetchOne) ?? false;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn(null);
        $storeManager->method('getStores')->willReturnCallback(fn (): array => array_map(
            static fn (string $code) => new \Magento\Framework\DataObject(['base_currency_code' => $code]),
            $this->currencies
        ));

        return new WeeklySummaryBuilder(
            $report,
            $recs,
            $sources,
            $catalog,
            $resource,
            $storeManager,
            $logger ?? $this->createStub(LoggerInterface::class),
            $this->scope
        );
    }
}
