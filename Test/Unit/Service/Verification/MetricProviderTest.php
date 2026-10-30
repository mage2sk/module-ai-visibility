<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Verification;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Verification\MetricProvider;
use PHPUnit\Framework\TestCase;

class MetricProviderTest extends TestCase
{
    /**
     * Recorded calls per select: object id => [[method, args], ...]
     *
     * @var array<int, array>
     */
    private array $calls = [];

    /**
     * Provider whose adapter answers fetchAll / fetchOne through callbacks that get the call record
     *
     * @param callable $fetchAll fn (array $calls): array
     * @param mixed $fetchOne
     * @param string $model Attribution model
     * @return MetricProvider
     */
    private function provider(callable $fetchAll, $fetchOne = 0, string $model = 'first'): MetricProvider
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(function () {
            $select = $this->createStub(Select::class);
            $id = spl_object_id($select);
            $this->calls[$id] = [];
            foreach (['from', 'where', 'group', 'join', 'joinLeft', 'order', 'limit', 'distinct', 'columns'] as $m) {
                $select->method($m)->willReturnCallback(function (...$args) use ($select, $id, $m) {
                    $this->calls[$id][] = [$m, $args];
                    return $select;
                });
            }
            $select->method('assemble')->willReturn('SELECT 1');
            return $select;
        });
        $connection->method('fetchAll')->willReturnCallback(
            fn ($select) => $fetchAll($this->calls[spl_object_id($select)])
        );
        $connection->method('fetchOne')->willReturn($fetchOne);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name): string => $name);
        $config = $this->createStub(Config::class);
        $config->method('attributionModel')->willReturn($model);
        return new MetricProvider($resource, $config);
    }

    /**
     * First table of a call record
     *
     * @param array $calls
     * @return string
     */
    private static function table(array $calls): string
    {
        foreach ($calls as [$method, $args]) {
            if ($method === 'from') {
                return is_array($args[0]) ? (string)current($args[0]) : (string)$args[0];
            }
        }
        return '';
    }

    /**
     * Column expressions of the from() call, as strings
     *
     * @param array $calls
     * @return array<string, string>
     */
    private static function columns(array $calls): array
    {
        foreach ($calls as [$method, $args]) {
            if ($method === 'from') {
                return array_map('strval', (array)($args[1] ?? []));
            }
        }
        return [];
    }

    /**
     * Bound value of a where() condition, or the string "<absent>"
     *
     * @param array $calls
     * @param string $condition
     * @return mixed
     */
    private static function where(array $calls, string $condition)
    {
        foreach ($calls as [$method, $args]) {
            if ($method === 'where' && $args[0] === $condition) {
                return $args[1] ?? null;
            }
        }
        return '<absent>';
    }

    public function testSupportsEveryContractMetricAndNothingElse(): void
    {
        $provider = $this->provider(static fn () => []);
        foreach (MetricProviderInterface::METRICS as $metric) {
            $this->assertTrue($provider->supports($metric), $metric);
            $this->assertNotSame([], MetricProvider::sources($metric, Finding::SUBJECT_SITE), $metric);
        }
        $this->assertFalse($provider->supports('page_views'));
    }

    /**
     * @dataProvider badArgumentsProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badArgumentsProvider')]
    public function testRejectsBadArguments(string $metric, string $from, string $to): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->provider(static fn () => [])->value($metric, 0, ['site'], $from, $to);
    }

    /**
     * @return array
     */
    public static function badArgumentsProvider(): array
    {
        return [
            'unknown metric' => ['page_views', '2026-03-01', '2026-03-14'],
            'bad day' => ['bot_hits', '2026-02-30', '2026-03-14'],
            'not a day' => ['bot_hits', 'yesterday', '2026-03-14'],
            'reversed range' => ['bot_hits', '2026-03-14', '2026-03-01'],
        ];
    }

    public function testSiteCrawlCountFromTheDailyTable(): void
    {
        $seen = null;
        $result = $this->provider(function (array $calls) use (&$seen) {
            $seen = $calls;
            return [['k' => '', 'n' => '120', 'h' => '120']];
        })->value('bot_hits', 2, ['site'], '2026-03-01', '2026-03-14');

        $this->assertSame(['site' => ['num' => 120.0, 'den' => 1.0]], $result);
        $this->assertSame('panth_aiv_crawl_daily', self::table($seen));
        $this->assertSame(Category::BOT_CATEGORIES, self::where($seen, 'd.category IN (?)'));
        $this->assertSame('2026-03-01', self::where($seen, 'd.day >= ?'));
        $this->assertSame('2026-03-14', self::where($seen, 'd.day <= ?'));
        $this->assertSame(0, self::where($seen, 'd.is_simulated = ?'));
        $this->assertSame(2, self::where($seen, 'd.store_id = ?'));
    }

    public function testSimulatedRowsAndAllStores(): void
    {
        $seen = null;
        $this->provider(function (array $calls) use (&$seen) {
            $seen = $calls;
            return [];
        })->value('live_fetches', 0, ['site'], '2026-03-01', '2026-03-14', true);

        $this->assertSame(1, self::where($seen, 'd.is_simulated = ?'));
        $this->assertSame('<absent>', self::where($seen, 'd.store_id = ?'));
        $this->assertSame([Category::LIVE], self::where($seen, 'd.category IN (?)'));
    }

    public function testBotSubjectsAreGroupedAndMissingOnesAreZero(): void
    {
        $seen = null;
        $result = $this->provider(function (array $calls) use (&$seen) {
            $seen = $calls;
            return [['k' => 'gptbot', 'n' => '30', 'h' => '30']];
        })->value('search_bot_hits', 1, ['gptbot', 'bot:claudebot'], '2026-03-01', '2026-03-14');

        $this->assertSame([
            'gptbot' => ['num' => 30.0, 'den' => 1.0],
            'bot:claudebot' => ['num' => 0.0, 'den' => 1.0],
        ], $result);
        $this->assertSame([Category::SEARCH, Category::LIVE], self::where($seen, 'd.category IN (?)'));
        $this->assertSame(['gptbot', 'claudebot'], self::where($seen, 'd.bot_code IN (?)'));
    }

    public function testAverageResponseTimeIsARatioOfTotalMsOverTimedHits(): void
    {
        $result = $this->provider(static fn () => [['k' => '', 'n' => '250000', 'h' => '100']])
            ->value('bot_avg_ms', 0, ['site'], '2026-03-01', '2026-03-14');

        $this->assertSame(['site' => ['num' => 250000.0, 'den' => 100.0]], $result);
    }

    public function testPathCountsComeFromPathDailyByHash(): void
    {
        $seen = null;
        $result = $this->provider(function (array $calls) use (&$seen) {
            $seen = $calls;
            return [['k' => sha1('/b'), 'n' => '7']];
        })->value('bot_hits', 1, ['/a', '/b'], '2026-03-01', '2026-03-14');

        $this->assertSame('panth_aiv_path_daily', self::table($seen));
        $this->assertSame([sha1('/a'), sha1('/b')], self::where($seen, 'p.path_hash IN (?)'));
        $this->assertSame(['/a' => ['num' => 0.0, 'den' => 1.0], '/b' => ['num' => 7.0, 'den' => 1.0]], $result);
    }

    public function testCategoryCountsUseTheEntityColumns(): void
    {
        $seen = null;
        $result = $this->provider(function (array $calls) use (&$seen) {
            $seen = $calls;
            return [['k' => '5', 'n' => '11']];
        })->value('bot_hits', 1, ['category:5'], '2026-03-01', '2026-03-14');

        $this->assertSame('panth_aiv_entity_daily', self::table($seen), 'uncapped per-entity counts');
        $this->assertSame('category', self::where($seen, 'p.entity_type = ?'));
        $this->assertSame([5], self::where($seen, 'p.entity_id IN (?)'));
        $this->assertSame(['category:5' => ['num' => 11.0, 'den' => 1.0]], $result);
    }

    public function testPathErrorsComeFromThePathDailyStatusColumns(): void
    {
        $seen = null;
        $provider = $this->provider(function (array $calls) use (&$seen) {
            $seen = $calls;
            return [['k' => sha1('/gone'), 'c4' => '18', 'c5' => '1', 'ms' => '4000', 'timed' => '16']];
        });

        $this->assertSame(
            ['/gone' => ['num' => 18.0, 'den' => 1.0], '/fine' => ['num' => 0.0, 'den' => 1.0]],
            $provider->value('bot_404', 1, ['/gone', '/fine'], '2026-03-01', '2026-03-14')
        );
        $this->assertSame('panth_aiv_path_daily', self::table($seen));
        $this->assertSame('2026-03-01', self::where($seen, 'p.day >= ?'));
        $this->assertSame('2026-03-14', self::where($seen, 'p.day <= ?'));
        $this->assertSame([sha1('/gone'), sha1('/fine')], self::where($seen, 'p.path_hash IN (?)'));
        $this->assertSame(
            ['/gone' => ['num' => 1.0, 'den' => 1.0]],
            $provider->value('bot_5xx', 1, ['/gone'], '2026-03-01', '2026-03-14')
        );
        $this->assertSame(
            ['/gone' => ['num' => 4000.0, 'den' => 16.0]],
            $provider->value('bot_avg_ms', 1, ['/gone'], '2026-03-01', '2026-03-14'),
            'average response time is total ms over timed hits only'
        );
    }

    public function testProductErrorsAreGroupedByEntity(): void
    {
        $seen = null;
        $result = $this->provider(function (array $calls) use (&$seen) {
            $seen = $calls;
            return [['k' => '42', 'c4' => '5', 'c5' => '2', 'ms' => '9000', 'timed' => '3']];
        })->value('bot_5xx', 1, ['product:42'], '2026-03-01', '2026-03-14');

        $this->assertSame('panth_aiv_path_daily', self::table($seen));
        $this->assertSame('product', self::where($seen, 'p.entity_type = ?'));
        $this->assertSame([42], self::where($seen, 'p.entity_id IN (?)'));
        $this->assertSame(['product:42' => ['num' => 2.0, 'den' => 1.0]], $result);
    }

    public function testErrorMetricsNoLongerDependOnRawHitRetention(): void
    {
        foreach (['bot_404', 'bot_5xx', 'bot_avg_ms'] as $metric) {
            foreach (['path', 'product', 'category'] as $type) {
                $this->assertSame(['path_daily'], MetricProvider::sources($metric, $type), $metric . '/' . $type);
            }
            $this->assertSame(['crawl_daily'], MetricProvider::sources($metric, 'site'));
        }
    }

    public function testCanonicalShareForTheSiteComesFromTheDailyQueryCounts(): void
    {
        $seen = null;
        $result = $this->provider(function (array $calls) use (&$seen) {
            $seen = $calls;
            return [['k' => '', 'n' => '35', 'h' => '50']];
        })->value('canonical_crawl_share', 0, ['site'], '2026-03-01', '2026-03-14');

        $this->assertSame(['site' => ['num' => 35.0, 'den' => 50.0]], $result);
        $this->assertSame('panth_aiv_crawl_daily', self::table($seen));
        $columns = self::columns($seen);
        $this->assertStringContainsString('d.canonical_hits', $columns['n']);
        $this->assertStringContainsString('d.canonical_hits + d.query_hits', $columns['h']);
        $this->assertStringContainsString('IS NULL THEN 0', $columns['h'], 'days rolled up before 1.1 are left out');
        $this->assertSame(['crawl_daily'], MetricProvider::sources('canonical_crawl_share', 'site'));
        $this->assertSame(['crawl_daily'], MetricProvider::sources('canonical_crawl_share', 'bot'));
        $this->assertSame(['raw_hit'], MetricProvider::sources('canonical_crawl_share', 'path'));
    }

    public function testSiteAverageResponseTimeDividesByTimedHitsOnly(): void
    {
        $seen = null;
        $this->provider(function (array $calls) use (&$seen) {
            $seen = $calls;
            return [['k' => '', 'n' => '250000', 'h' => '100']];
        })->value('bot_avg_ms', 0, ['site'], '2026-03-01', '2026-03-14');

        $columns = self::columns($seen);
        $this->assertSame('COALESCE(SUM(d.timed_hits), 0)', $columns['h']);
        $this->assertStringContainsString('WHEN d.timed_hits IS NULL THEN 0 ELSE d.total_ms', $columns['n']);
    }

    public function testProductVisitsComeFromTheUncappedVisitPathDailyByEntity(): void
    {
        $seen = null;
        $result = $this->provider(function (array $calls) use (&$seen) {
            $seen = $calls;
            return [['k' => '42', 'n' => '8']];
        })->value('ai_visits', 1, ['product:42', 'product:43'], '2026-03-01', '2026-03-14');

        $this->assertSame('panth_aiv_visit_path_daily', self::table($seen));
        $this->assertSame('product', self::where($seen, 'v.entity_type = ?'));
        $this->assertSame([42, 43], self::where($seen, 'v.entity_id IN (?)'));
        $this->assertSame('2026-03-01', self::where($seen, 'v.day >= ?'));
        $this->assertSame(1, self::where($seen, 'v.store_id = ?'));
        $this->assertSame([
            'product:42' => ['num' => 8.0, 'den' => 1.0],
            'product:43' => ['num' => 0.0, 'den' => 1.0],
        ], $result);
        foreach (['path', 'product', 'category'] as $type) {
            $this->assertSame(['visit_path_daily'], MetricProvider::sources('ai_visits', $type));
            $this->assertSame(['order', 'visit_path_daily'], MetricProvider::sources('ai_conversion', $type));
        }
    }

    public function testPathVisitsAreReadByPathHash(): void
    {
        $seen = null;
        $result = $this->provider(function (array $calls) use (&$seen) {
            $seen = $calls;
            return [['k' => sha1('/landing.html'), 'n' => '3']];
        })->value('ai_visits', 1, ['/landing.html'], '2026-03-01', '2026-03-14');

        $this->assertSame([sha1('/landing.html')], self::where($seen, 'v.path_hash IN (?)'));
        $this->assertSame(['/landing.html' => ['num' => 3.0, 'den' => 1.0]], $result);
    }

    public function testSourceConversionIsOrdersOverVisitsWithTheAttributionModel(): void
    {
        $orderQuery = null;
        $result = $this->provider(function (array $calls) use (&$orderQuery) {
            if (self::table($calls) === 'panth_aiv_order') {
                $orderQuery = $calls;
                return [['k' => 'chatgpt', 'n' => '3']];
            }
            return [['k' => 'chatgpt', 'n' => '120']];
        }, 0, 'last')->value('ai_conversion', 1, ['chatgpt'], '2026-03-01', '2026-03-14');

        $this->assertSame(['chatgpt' => ['num' => 3.0, 'den' => 120.0]], $result);
        $this->assertSame(['chatgpt'], self::where($orderQuery, 'o.last_source IN (?)'));
        $this->assertSame('canceled', self::where($orderQuery, '(so.state IS NULL OR so.state <> ?)'));
    }

    public function testRatioSubjectWithoutDataHasZeroDenominator(): void
    {
        $result = $this->provider(static fn () => [])->value('ai_conversion', 1, ['/p'], '2026-03-01', '2026-03-14');

        $this->assertSame(['/p' => ['num' => 0.0, 'den' => 0.0]], $result);
    }

    public function testBacklogIsAnEndOfWindowCount(): void
    {
        $result = $this->provider(static fn () => [], '12')
            ->value('candidate_backlog', 0, ['site'], '2026-03-01', '2026-03-14');

        $this->assertSame(['site' => ['num' => 12.0, 'den' => 1.0]], $result);
    }

    public function testUnsupportedSubjectTypeGivesZeros(): void
    {
        $result = $this->provider(static fn () => [['k' => '', 'n' => '99']])
            ->value('candidate_backlog', 0, ['/a'], '2026-03-01', '2026-03-14');

        $this->assertSame(['/a' => ['num' => 0.0, 'den' => 1.0]], $result);
        $this->assertSame([], MetricProvider::sources('candidate_backlog', Finding::SUBJECT_PATH));
        $this->assertSame([], MetricProvider::sources('ai_visits', Finding::SUBJECT_BOT));
    }

    /**
     * @dataProvider refProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refProvider')]
    public function testParseRef(string $ref, string $metric, array $expected): void
    {
        $this->assertSame($expected, MetricProvider::parseRef($ref, $metric));
    }

    /**
     * @return array
     */
    public static function refProvider(): array
    {
        return [
            'site' => ['site', 'bot_hits', ['site', '']],
            'path' => ['/a/b.html', 'bot_hits', ['path', '/a/b.html']],
            'plain id' => ['42', 'bot_hits', ['product', '42']],
            'product prefix' => ['product:42', 'bot_hits', ['product', '42']],
            'category prefix' => ['category:7', 'ai_visits', ['category', '7']],
            'plain bot' => ['gptbot', 'bot_hits', ['bot', 'gptbot']],
            'plain source' => ['chatgpt', 'ai_orders', ['source', 'chatgpt']],
            'source prefix' => ['source:chatgpt', 'bot_hits', ['source', 'chatgpt']],
            'unknown prefix stays a code' => ['cand:5', 'bot_hits', ['bot', 'cand:5']],
        ];
    }

    public function testCrawlCategories(): void
    {
        $this->assertSame(['search', 'live'], MetricProvider::crawlCategories('search_bot_hits'));
        $this->assertSame(['live'], MetricProvider::crawlCategories('live_fetches'));
        $this->assertSame(Category::BOT_CATEGORIES, MetricProvider::crawlCategories('bot_hits'));
    }

    public function testTypedRef(): void
    {
        $this->assertSame('/a', MetricProvider::typedRef('path', '/a'));
        $this->assertSame('site', MetricProvider::typedRef('site', 'site'));
        $this->assertSame('category:7', MetricProvider::typedRef('category', '7'));
        $this->assertSame('category:7', MetricProvider::typedRef('category', 'category:7'));
        $this->assertSame('bot:gptbot', MetricProvider::typedRef('bot', 'gptbot'));
    }
}
