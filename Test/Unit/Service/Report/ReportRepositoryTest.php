<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Report;

use Panth\AiVisibility\Service\BotClassifier;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReportRepositoryTest extends TestCase
{
    /**
     * Recorded calls per select: list of [method, args]
     *
     * @var array<int, array<int, array{0: string, 1: array}>>
     */
    private array $calls = [];

    /**
     * @var AdapterInterface&MockObject
     */
    private $connection;

    /**
     * @var ReportRepository
     */
    private ReportRepository $repository;

    /**
     * @var DateRange
     */
    private DateRange $range;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(AdapterInterface::class);
        $this->connection->method('select')->willReturnCallback(fn () => $this->recordingSelect());
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnCallback(static fn ($name) => 'pfx_' . $name);

        $rules = $this->createStub(RuleProvider::class);
        $rules->method('botRules')->willReturn([]);
        $config = $this->createStub(Config::class);
        $config->method('customBotPatterns')->willReturn([]);

        $this->repository = new ReportRepository($resource, new BotDirectory(new BotClassifier($config, $rules)));
        $this->range = new DateRange('2026-10-01', '2026-10-03', DateRange::PERIOD_CUSTOM);
    }

    public function testCrawlTotalsFilterDaysStoreSimulationAndCategories(): void
    {
        $this->connection->method('fetchRow')->willReturn(['hits' => '120', 'live' => '7']);
        $this->assertSame(['hits' => 120, 'live' => 7], $this->repository->crawlTotals($this->range, 2, false));

        $wheres = $this->wheres(0);
        $this->assertContains(['d.day >= ?', '2026-10-01'], $wheres);
        $this->assertContains(['d.day <= ?', '2026-10-03'], $wheres);
        $this->assertContains(['d.store_id = ?', 2], $wheres);
        $this->assertContains(['d.is_simulated = ?', 0], $wheres);
        $this->assertContains(['d.category IN (?)', Category::BOT_CATEGORIES], $wheres);
        $this->assertSame('pfx_panth_aiv_crawl_daily', array_values($this->call(0, 'from')[0])[0]);
    }

    public function testAllStoresAndSimulationIncludedDropThoseFilters(): void
    {
        $this->connection->method('fetchOne')->willReturn('5');
        $this->assertSame(5, $this->repository->visitTotal($this->range, 0, true));
        $conditions = array_column($this->wheres(0), 0);
        $this->assertNotContains('v.store_id = ?', $conditions);
        $this->assertNotContains('v.is_simulated = ?', $conditions);
        $this->assertContains('v.day >= ?', $conditions);
    }

    public function testOrderTotalsJoinSalesOrderByOrderDateAndSkipCanceled(): void
    {
        $this->connection->method('fetchRow')->willReturn(['orders' => '3', 'revenue' => '149.90']);
        $this->assertSame(['orders' => 3, 'revenue' => 149.9], $this->repository->orderTotals($this->range, 1, false));

        $join = $this->call(0, 'join');
        $this->assertSame(['so' => 'pfx_sales_order'], $join[0]);
        $this->assertSame('so.entity_id = o.order_id', $join[1]);
        $wheres = $this->wheres(0);
        $this->assertContains(['so.created_at >= ?', '2026-10-01 00:00:00'], $wheres);
        $this->assertContains(['so.created_at < ?', '2026-10-04 00:00:00'], $wheres);
        $this->assertContains(['(so.state IS NULL OR so.state <> ?)', 'canceled'], $wheres);
        $this->assertContains(['so.store_id = ?', 1], $wheres);
        $this->assertContains(['o.is_simulated = ?', 0], $wheres);
    }

    public function testRevenueBySourceGroupsByTheAttributionModelColumn(): void
    {
        $this->connection->method('fetchAll')->willReturn([['source' => 'chatgpt', 'orders' => '2', 'revenue' => '80']]);
        $rows = $this->repository->revenueBySource($this->range, 0, false, 'last');
        $this->assertSame([['source' => 'chatgpt', 'orders' => 2, 'revenue' => 80.0]], $rows);
        $this->assertSame('o.last_source', $this->call(0, 'group')[0]);

        $this->repository->revenueBySource($this->range, 0, false, 'first');
        $this->assertSame('o.first_source', $this->call(1, 'group')[0]);
    }

    public function testHitsByCompanyKeepsTopFiveAndFoldsTheRestIntoOther(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['day' => '2026-10-01', 'bot_code' => 'gptbot', 'hits' => '50'],
            ['day' => '2026-10-01', 'bot_code' => 'chatgpt_user', 'hits' => '10'],
            ['day' => '2026-10-02', 'bot_code' => 'claudebot', 'hits' => '40'],
            ['day' => '2026-10-02', 'bot_code' => 'perplexitybot', 'hits' => '30'],
            ['day' => '2026-10-03', 'bot_code' => 'amazonbot', 'hits' => '20'],
            ['day' => '2026-10-03', 'bot_code' => 'applebot', 'hits' => '15'],
            ['day' => '2026-10-03', 'bot_code' => 'ccbot', 'hits' => '5'],
            ['day' => '2026-10-01', 'bot_code' => 'bytespider', 'hits' => '4'],
            ['day' => '2026-09-01', 'bot_code' => 'gptbot', 'hits' => '999'],
        ]);
        $result = $this->repository->hitsByCompany($this->range, 0, false);

        $this->assertSame(['OpenAI', 'Anthropic', 'Perplexity', 'Amazon', 'Apple', 'Other'], array_keys($result));
        $this->assertSame(['2026-10-01' => 60, '2026-10-02' => 0, '2026-10-03' => 0], $result['OpenAI']);
        $this->assertSame(['2026-10-01' => 4, '2026-10-02' => 0, '2026-10-03' => 5], $result['Other']);
    }

    public function testHitsByCompanyShowsASingleRemainingCompanyByName(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['day' => '2026-10-01', 'bot_code' => 'gptbot', 'hits' => '1'],
            ['day' => '2026-10-01', 'bot_code' => 'claudebot', 'hits' => '1'],
        ]);
        $result = $this->repository->hitsByCompany($this->range, 0, false, 1);
        $this->assertSame(['Anthropic', 'OpenAI'], array_keys($result));
    }

    public function testHitsByCompanyEmpty(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);
        $this->assertSame([], $this->repository->hitsByCompany($this->range, 0, false));
    }

    public function testDailySeriesAreFilledForEveryDay(): void
    {
        $this->connection->method('fetchPairs')->willReturn(['2026-10-02' => '9', '2026-12-01' => '4']);
        $this->assertSame(
            ['2026-10-01' => 0, '2026-10-02' => 9, '2026-10-03' => 0],
            $this->repository->visitsByDay($this->range, 0, false)
        );
    }

    public function testTopPathsUsesCategoryFilterAndNormalisesRows(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['path' => '/shoe.html', 'entity_type' => 'product', 'entity_id' => '12', 'hits' => '30'],
            ['path' => '/llms.txt', 'entity_type' => null, 'entity_id' => null, 'hits' => '3'],
        ]);
        $rows = $this->repository->topPaths($this->range, 0, false, Category::LIVE, 5);
        $this->assertSame(
            [
                ['path' => '/shoe.html', 'entity_type' => 'product', 'entity_id' => 12, 'hits' => 30],
                ['path' => '/llms.txt', 'entity_type' => null, 'entity_id' => null, 'hits' => 3],
            ],
            $rows
        );
        $this->assertContains(['p.category = ?', 'live'], $this->wheres(0));
        $this->assertSame(5, $this->call(0, 'limit')[0]);
        $this->assertSame('p.path_hash', $this->call(0, 'group')[0]);
    }

    public function testLlmsFetchesFilterOnPageType(): void
    {
        $this->connection->method('fetchPairs')->willReturn(['gptbot' => '4']);
        $this->assertSame(['gptbot' => 4], $this->repository->llmsFetchesByBot($this->range, 0, false));
        $this->assertContains(['d.page_type = ?', 'llms'], $this->wheres(0));
    }

    public function testHasSimulatedDataStopsAtTheFirstHit(): void
    {
        $this->connection->method('fetchOne')->willReturn('1');
        $this->assertTrue($this->repository->hasSimulatedData($this->range, 3));
        $this->assertCount(1, $this->calls);
        $this->assertContains(['t.is_simulated = ?', 1], $this->wheres(0));
        $this->assertContains(['t.store_id = ?', 3], $this->wheres(0));
    }

    public function testHasAnyDataChecksEveryTable(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);
        $this->assertFalse($this->repository->hasAnyData(false));
        $this->assertCount(3, $this->calls);
    }

    public function testNewCandidateCount(): void
    {
        $this->connection->method('fetchOne')->willReturn('4');
        $this->assertSame(4, $this->repository->newCandidateCount(false));
        $this->assertContains(['c.status = ?', 'new'], $this->wheres(0));
        $this->assertContains(['c.is_simulated = ?', 0], $this->wheres(0));
    }

    /**
     * Select mock that records every builder call
     *
     * @return Select
     */
    private function recordingSelect(): Select
    {
        $index = count($this->calls);
        $this->calls[$index] = [];
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'group', 'order', 'limit', 'join', 'joinLeft', 'columns'] as $method) {
            $select->method($method)->willReturnCallback(
                function (...$args) use ($select, $index, $method) {
                    $this->calls[$index][] = [$method, $args];
                    return $select;
                }
            );
        }
        return $select;
    }

    /**
     * Where conditions of a select as [condition, value]
     *
     * @param int $index
     * @return array
     */
    private function wheres(int $index): array
    {
        $result = [];
        foreach ($this->calls[$index] ?? [] as [$method, $args]) {
            if ($method === 'where') {
                $result[] = [$args[0], $args[1] ?? null];
            }
        }
        return $result;
    }

    /**
     * Arguments of the first call of a builder method on a select
     *
     * @param int $index
     * @param string $method
     * @return array
     */
    private function call(int $index, string $method): array
    {
        foreach ($this->calls[$index] ?? [] as [$name, $args]) {
            if ($name === $method) {
                return $args;
            }
        }
        $this->fail('No ' . $method . ' call on select ' . $index);
    }
}
