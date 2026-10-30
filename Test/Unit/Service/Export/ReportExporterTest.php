<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Export;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Export\ReportExporter;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ReportExporterTest extends TestCase
{
    /**
     * Fluent calls per select: spl_object_id => [[method, args], ...]
     *
     * @var array<int, array<int, array{0: string, 1: array}>>
     */
    private array $calls = [];

    /**
     * Result rows per table (first from())
     *
     * @var array<string, array>
     */
    private array $rows = [];

    /**
     * Selects in creation order
     *
     * @var Select[]
     */
    private array $selects = [];

    /**
     * @var ReportRepository&Stub
     */
    private $reportRepository;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->rows = [];
        $this->selects = [];
        $this->reportRepository = $this->createStub(ReportRepository::class);
    }

    private function exporter(): ReportExporter
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->newSelect());
        $connection->method('fetchAll')->willReturnCallback(fn ($select) => $this->rows[$this->tableOf($select)] ?? []);
        $connection->method('fetchPairs')->willReturnCallback(fn ($select) => $this->rows[$this->tableOf($select)] ?? []);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $catalog = $this->createStub(CatalogLookup::class);
        $catalog->method('entityNames')->willReturn(['product:7' => 'Blue Shirt']);
        $bots = $this->createStub(BotDirectory::class);
        $bots->method('describe')->willReturnCallback(
            static fn ($code, $category) => ['name' => 'Name of ' . $code, 'company' => 'Co', 'category' => $category]
        );
        $sources = $this->createStub(SourceClassifierInterface::class);
        $sources->method('nameFor')->willReturnCallback(static fn ($code) => ucfirst((string)$code));
        $config = $this->createStub(Config::class);
        $config->method('attributionModel')->willReturn('first');

        return new ReportExporter($resource, $this->reportRepository, $catalog, $bots, $sources, $config);
    }

    private function newSelect(): Select
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'group', 'order', 'limit', 'join', 'joinLeft'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($select, $method) {
                $this->calls[spl_object_id($select)][] = [$method, $args];
                return $select;
            });
        }
        $this->selects[] = $select;
        return $select;
    }

    private function tableOf(Select $select): string
    {
        foreach ($this->calls[spl_object_id($select)] ?? [] as [$method, $args]) {
            if ($method === 'from') {
                return is_array($args[0]) ? (string)current($args[0]) : (string)$args[0];
            }
        }
        return '';
    }

    /**
     * where() conditions of the select on a table: condition => bound value
     *
     * @param string $table
     * @return array<string, mixed>
     */
    private function wheres(string $table): array
    {
        foreach ($this->selects as $select) {
            if ($this->tableOf($select) === $table) {
                $wheres = [];
                foreach ($this->calls[spl_object_id($select)] as [$method, $args]) {
                    if ($method === 'where') {
                        $wheres[$args[0]] = $args[1] ?? null;
                    }
                }
                return $wheres;
            }
        }
        $this->fail('No select on ' . $table);
    }

    private static function range(): DateRange
    {
        // "Today" = 2026-10-30
        return DateRange::fromInput('custom', '2026-10-01', '2026-10-03', 1793361600);
    }

    public function testUnknownReportThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->exporter()->export('orders', self::range(), 0, false);
    }

    public function testDailyHasEveryDayAndTheDashboardFilters(): void
    {
        $this->rows['panth_aiv_crawl_daily'] = [['day' => '2026-10-02', 'hits' => '120', 'live' => '30']];
        $this->rows['panth_aiv_order'] = [['day' => '2026-10-03', 'orders' => '2', 'revenue' => '99.5']];
        $this->reportRepository->method('visitsByDay')
            ->willReturn(['2026-10-01' => 0, '2026-10-02' => 9, '2026-10-03' => 4]);

        $data = $this->exporter()->export('daily', self::range(), 2, false);

        $this->assertSame(
            ['day', 'ai_crawler_hits', 'live_answer_fetches', 'ai_visits', 'ai_orders', 'ai_revenue'],
            $data['headers']
        );
        $this->assertSame([
            ['2026-10-01', 0, 0, 0, 0, 0.0],
            ['2026-10-02', 120, 30, 9, 0, 0.0],
            ['2026-10-03', 0, 0, 4, 2, 99.5],
        ], $data['rows']);
        $crawl = $this->wheres('panth_aiv_crawl_daily');
        $this->assertSame('2026-10-01', $crawl['d.day >= ?']);
        $this->assertSame('2026-10-03', $crawl['d.day <= ?']);
        $this->assertSame(2, $crawl['d.store_id = ?']);
        $this->assertSame(0, $crawl['d.is_simulated = ?']);
        $orders = $this->wheres('panth_aiv_order');
        $this->assertSame('2026-10-01 00:00:00', $orders['so.created_at >= ?']);
        $this->assertSame('2026-10-04 00:00:00', $orders['so.created_at < ?']);
        $this->assertSame(2, $orders['so.store_id = ?']);
        $this->assertSame(0, $orders['o.is_simulated = ?']);
    }

    public function testAllStoresWithSimulatedRowsAddsNoStoreOrSimulationFilter(): void
    {
        $this->reportRepository->method('visitsByDay')->willReturn([]);
        $this->exporter()->export('daily', self::range(), 0, true);
        $crawl = $this->wheres('panth_aiv_crawl_daily');
        $this->assertArrayNotHasKey('d.store_id = ?', $crawl);
        $this->assertArrayNotHasKey('d.is_simulated = ?', $crawl);
    }

    public function testPagesCarryEntityNames(): void
    {
        $this->reportRepository->method('topPaths')->willReturnCallback(
            function ($range, $store, $sim, $category, $limit) {
                $this->assertSame([1, true, null, ReportExporter::PAGE_LIMIT], [$store, $sim, $category, $limit]);
                return [
                    ['path' => '/blue-shirt.html', 'entity_type' => 'product', 'entity_id' => 7, 'hits' => 40],
                    ['path' => '/llms.txt', 'entity_type' => null, 'entity_id' => null, 'hits' => 5],
                ];
            }
        );
        $data = $this->exporter()->export('pages', self::range(), 1, true);
        $this->assertSame(['rank', 'name', 'type', 'entity_id', 'path', 'ai_crawler_hits'], $data['headers']);
        $this->assertSame([
            [1, 'Blue Shirt', 'product', 7, '/blue-shirt.html', 40],
            [2, '', 'page', null, '/llms.txt', 5],
        ], $data['rows']);
    }

    public function testBotsHaveSharesAndAverageResponseTime(): void
    {
        $this->reportRepository->method('botStats')->willReturn([
            ['bot_code' => 'gptbot', 'category' => 'training', 'hits' => 200, 's4xx' => 10, 's5xx' => 1, 'total_ms' => 50000],
        ]);
        $data = $this->exporter()->export('bots', self::range(), 0, false);
        $this->assertCount(10, $data['headers']);
        $this->assertSame([['gptbot', 'Name of gptbot', 'Co', 'training', 200, 10, 1, 5.0, 0.5, 250]], $data['rows']);
    }

    public function testSourcesMergeVisitsAndRevenue(): void
    {
        $this->rows['panth_aiv_visit_daily'] = ['chatgpt' => '30', 'cand:5' => '4', 'perplexity' => '30'];
        $this->reportRepository->method('revenueBySource')->willReturn([
            ['source' => 'perplexity', 'orders' => 2, 'revenue' => 120.0],
            ['source' => 'gemini', 'orders' => 1, 'revenue' => 10.0],
        ]);
        $data = $this->exporter()->export('sources', self::range(), 0, false);
        $this->assertSame(['source_code', 'source', 'ai_visits', 'ai_orders', 'ai_revenue'], $data['headers']);
        $this->assertSame([
            ['perplexity', 'Perplexity', 30, 2, 120.0],
            ['chatgpt', 'Chatgpt', 30, 0, 0.0],
            ['cand:5', 'Unconfirmed source #5', 4, 0, 0.0],
            ['gemini', 'Gemini', 0, 1, 10.0],
        ], $data['rows']);
        $this->assertSame(0, $this->wheres('panth_aiv_visit_daily')['v.is_simulated = ?']);
    }

    public function testRecommendationsAreFilteredAndTyped(): void
    {
        $this->rows['panth_aiv_recommendation'] = [[
            'rec_id' => '4', 'status' => 'open', 'rule_code' => 'bot_404', 'store_id' => '1',
            'title' => '=cmd|calc', 'subject_type' => 'path', 'subject_ref' => '/old.html', 'affected_count' => '30',
            'impact_score' => '80', 'metric' => 'bot_404', 'feedback' => '-1', 'first_seen_at' => '2026-09-01 04:20:00',
            'last_seen_at' => '2026-10-02 04:20:00', 'status_changed_at' => null,
        ]];
        $data = $this->exporter()->export('recommendations', self::range(), 1, false);
        $this->assertCount(14, $data['headers']);
        $row = $data['rows'][0];
        $this->assertSame(4, $row[0]);
        $this->assertSame('=cmd|calc', $row[4]);
        $this->assertSame(-1, $row[10]);
        $this->assertNull($row[13]);
        $wheres = $this->wheres('panth_aiv_recommendation');
        $this->assertSame('2026-10-01 00:00:00', $wheres['r.last_seen_at >= ?']);
        $this->assertSame('2026-10-04 00:00:00', $wheres['r.first_seen_at < ?']);
        $this->assertSame(1, $wheres['r.store_id = ?']);
        $this->assertSame(0, $wheres['r.is_simulated = ?']);
    }

    public function testVerdictsJoinImpactsAndKeepUnverifiedActions(): void
    {
        $base = [
            'rec_id' => '3', 'rule_code' => 'never_crawled_products', 'store_id' => '1', 'metric' => 'bot_hits',
            'subjects' => '["101","103","105"]', 'applied_at' => '2026-10-02 12:00:00', 'applied_by' => 'admin',
            'undone_at' => null, 'verify_after' => '2026-10-17', 'note' => null,
        ];
        $this->rows['panth_aiv_action'] = [
            ['action_id' => '9', 'status' => 'verified', 'verdict' => 'helped', 'method' => 'did', 'window_days' => '14',
                'effect' => '1.4800', 'ci_low' => '1.2100', 'ci_high' => '1.7700', 'events' => '900',
                'subjects_treated' => '3', 'subjects_control' => '12', 'computed_at' => '2026-10-17 04:40:00'] + $base,
            ['action_id' => '10', 'status' => 'pending', 'verdict' => null, 'method' => null, 'window_days' => null,
                'effect' => null, 'ci_low' => null, 'ci_high' => null, 'events' => null, 'subjects_treated' => null,
                'subjects_control' => null, 'computed_at' => null] + $base,
        ];
        $data = $this->exporter()->export('verdicts', self::range(), 0, true);
        $this->assertCount(22, $data['headers']);
        $this->assertSame(array_fill(0, 2, 22), array_map('count', $data['rows']));
        [$verified, $pending] = $data['rows'];
        $this->assertSame(9, $verified[0]);
        $this->assertSame(3, $verified[5]);
        $this->assertSame('helped', $verified[11]);
        $this->assertSame([1.48, 1.21, 1.77], [$verified[14], $verified[15], $verified[16]]);
        $this->assertNull($pending[11]);
        $this->assertNull($pending[14]);
        $wheres = $this->wheres('panth_aiv_action');
        $this->assertSame('2026-10-01 00:00:00', $wheres['a.applied_at >= ?']);
        $this->assertSame('2026-10-04 00:00:00', $wheres['a.applied_at < ?']);
        $this->assertArrayNotHasKey('a.is_simulated = ?', $wheres);
    }

    public function testEveryReportHasHeaders(): void
    {
        $exporter = $this->exporter();
        foreach (ReportExporter::REPORTS as $report) {
            $data = $exporter->export($report, self::range(), 0, false);
            $this->assertNotEmpty($data['headers'], $report);
            foreach ($data['headers'] as $header) {
                $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $header, $report);
            }
        }
    }
}
