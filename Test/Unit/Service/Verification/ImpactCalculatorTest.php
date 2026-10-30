<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Verification;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Verification\Bootstrap;
use Panth\AiVisibility\Service\Verification\ControlMatcher;
use Panth\AiVisibility\Service\Verification\ImpactCalculator;
use Panth\AiVisibility\Service\Verification\ImpactStore;
use Panth\AiVisibility\Service\Verification\PoissonRateTest;
use Panth\AiVisibility\Service\Verification\ProportionDifference;
use Panth\AiVisibility\Service\Verification\SubjectPool;
use Panth\AiVisibility\Service\Verification\WelchTTest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImpactCalculatorTest extends TestCase
{
    private const BEFORE_FROM = '2026-02-15';
    private const AFTER_FROM = '2026-03-02';

    /**
     * Rows passed to ImpactStore::saveImpact()
     *
     * @var array
     */
    private array $saved = [];

    /**
     * [actionId, status] passed to ImpactStore::setActionStatus()
     *
     * @var array
     */
    private array $statuses = [];

    /**
     * Calls to MetricProviderInterface::value(): [metric, store, subjects, from, to, simulated]
     *
     * @var array
     */
    private array $valueCalls = [];

    /**
     * Result of SubjectPool::coverageBaseline()
     *
     * @var array{n: int, x: int}
     */
    private array $baseline = ['n' => 0, 'x' => 0];

    /**
     * Arguments of SubjectPool::coverageBaseline() calls
     *
     * @var array
     */
    private array $baselineCalls = [];

    /**
     * Row returned by ImpactStore::getImpact()
     *
     * @var array|null
     */
    private ?array $storedImpact = null;

    /**
     * First day with any data in the daily summaries (null = always had data)
     *
     * @var string|null
     */
    private ?string $historyStarts = null;

    /**
     * Arguments of SubjectPool::candidates() calls
     *
     * @var array
     */
    private array $candidateCalls = [];

    /**
     * Action row applied on 2026-03-01 with a 14-day window (verify_after 2026-03-16)
     *
     * @param array $overrides
     * @return array
     */
    private static function action(array $overrides = []): array
    {
        return $overrides + [
            'action_id' => 42,
            'rec_id' => 7,
            'rule_code' => 'never_crawled_products',
            'store_id' => 1,
            'metric' => MetricProviderInterface::METRIC_BOT_HITS,
            'subjects' => '["1","2","3","4"]',
            'applied_at' => '2026-03-01 10:00:00',
            'undone_at' => null,
            'verify_after' => '2026-03-16',
            'status' => ActionLogInterface::STATUS_PENDING,
            'is_simulated' => 0,
            'rec_subject_type' => 'product',
        ];
    }

    /**
     * Calculator over in-memory data: $data[ref] = ['b' => [num, den], 'a' => [num, den]]
     *
     * @param array|null $action
     * @param array $data
     * @param string[] $pool Candidate control refs (plain)
     * @param string $now UTC time
     * @param array $config Config overrides
     * @param LoggerInterface|null $logger
     * @return ImpactCalculator
     */
    private function calculator(
        ?array $action,
        array $data,
        array $pool = [],
        string $now = '2026-03-16 04:40:00',
        array $config = [],
        ?LoggerInterface $logger = null
    ): ImpactCalculator {
        $store = $this->createStub(ImpactStore::class);
        $store->method('getAction')->willReturn($action);
        $store->method('dueActionIds')->willReturn([]);
        $store->method('saveImpact')->willReturnCallback(function (array $row): void {
            $this->saved[] = $row;
        });
        $store->method('setActionStatus')->willReturnCallback(function (int $id, string $status): void {
            $this->statuses[] = [$id, $status];
        });
        $store->method('getImpact')->willReturnCallback(fn (): ?array => $this->storedImpact);

        $metrics = $this->createStub(MetricProviderInterface::class);
        $metrics->method('supports')->willReturnCallback(
            static fn (string $m): bool => in_array($m, MetricProviderInterface::METRICS, true)
        );
        $metrics->method('value')->willReturnCallback(
            function (string $metric, int $storeId, array $subjects, string $from, string $to, bool $sim) use ($data) {
                $this->valueCalls[] = [$metric, $storeId, $subjects, $from, $to, $sim];
                $ratio = in_array($metric, MetricProviderInterface::RATIO_METRICS, true);
                $out = [];
                foreach ($subjects as $ref) {
                    if ($from === $to && isset($data[$ref]['days'])) {
                        $pair = $data[$ref]['days'][$from] ?? [0.0, 0.0];
                        $out[$ref] = ['num' => (float)$pair[0], 'den' => (float)$pair[1]];
                        continue;
                    }
                    // Ranges before the before window (control matching) read 'p', else the before values.
                    $key = $from === self::BEFORE_FROM ? 'b' : ($from < self::BEFORE_FROM ? 'p' : 'a');
                    $pair = $data[$ref][$key] ?? ($key === 'p' ? ($data[$ref]['b'] ?? null) : null)
                        ?? [0.0, $ratio ? 0.0 : 1.0];
                    $out[$ref] = ['num' => (float)$pair[0], 'den' => (float)($pair[1] ?? 1.0)];
                }
                return $out;
            }
        );

        $subjectPool = $this->createStub(SubjectPool::class);
        $subjectPool->method('candidates')->willReturnCallback(
            function (string $metric, string $type, int $store, string $from, string $to) use ($pool): array {
                $this->candidateCalls[] = func_get_args();
                return $this->historyStarts !== null && $to < $this->historyStarts ? [] : $pool;
            }
        );
        $subjectPool->method('coverageBaseline')->willReturnCallback(function (...$args): array {
            $this->baselineCalls[] = $args;
            return $this->baseline;
        });

        $cfg = $this->createStub(Config::class);
        $values = $config + [
            'verifyMinEvents' => 30,
            'verifyMaxControls' => 50,
            'verifyWindowDays' => 14,
            'hitRetentionDays' => 14,
            'rawVisitRetentionDays' => 30,
        ];
        foreach ($values as $method => $value) {
            $cfg->method($method)->willReturn($value);
        }
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(
            (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->getTimestamp()
        );

        return new ImpactCalculator(
            $store,
            $metrics,
            $subjectPool,
            new ControlMatcher(),
            new Bootstrap(),
            new PoissonRateTest(),
            new ProportionDifference(),
            new WelchTTest(),
            $cfg,
            $clock,
            new Json(),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * Product data: treated 1-4, controls 10-15 (typed refs)
     *
     * @param float $treatedLift After / before factor of treated subjects
     * @param float $controlLift After / before factor of controls
     * @return array
     */
    private static function productData(float $treatedLift, float $controlLift = 1.0): array
    {
        $data = [];
        foreach ([1 => 40, 2 => 55, 3 => 60, 4 => 45] as $id => $before) {
            $data['product:' . $id] = ['b' => [$before], 'a' => [round($before * $treatedLift)]];
        }
        foreach ([10 => 42, 11 => 50, 12 => 58, 13 => 47, 14 => 61, 15 => 39] as $id => $before) {
            $data['product:' . $id] = ['b' => [$before], 'a' => [round($before * $controlLift)]];
        }
        return $data;
    }

    public function testMultiSubjectActionThatHelped(): void
    {
        $pool = ['10', '11', '12', '13', '14', '15', '1'];
        $calculator = $this->calculator(self::action(), self::productData(1.8, 1.1), $pool);

        $row = $calculator->verify(42);

        $this->assertSame(ImpactCalculatorInterface::METHOD_DID, $row['method']);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $row['verdict']);
        $this->assertSame(4, $row['subjects_treated']);
        $this->assertSame(6, $row['subjects_control']);
        $this->assertSame(200.0, $row['treat_before']);
        $this->assertSame(360.0, $row['treat_after']);
        $this->assertSame(297.0, $row['control_before']);
        // Controls 42,50,58,47,61,39 x 1.1 rounded: 46+55+64+52+67+43.
        $this->assertSame(327.0, $row['control_after']);
        $this->assertSame(round((360 / 200) / (327 / 297), 4), $row['effect']);
        $this->assertGreaterThan(1.05, $row['ci_low']);
        $this->assertGreaterThan($row['effect'], $row['ci_high']);
        $this->assertSame(560, $row['events']);
        $this->assertSame(['2026-02-15', '2026-02-28'], $row['details']['before']);
        $this->assertSame(['2026-03-02', '2026-03-15'], $row['details']['after']);
        $this->assertTrue($row['details']['final']);
        $this->assertSame(14, $row['window_days']);
        $this->assertNotContains('product:1', $row['details']['controls']);
        // Product refs reach the provider typed, with the action's store and simulation flag.
        $this->assertSame(['product:1', 'product:2', 'product:3', 'product:4'], $this->valueCalls[0][2]);
        $this->assertSame(1, $this->valueCalls[0][1]);
        $this->assertFalse($this->valueCalls[0][5]);

        $this->assertCount(1, $this->saved);
        $this->assertIsString($this->saved[0]['details']);
        $this->assertSame([[42, ActionLogInterface::STATUS_VERIFIED]], $this->statuses);
    }

    public function testSiteWideTrendIsNotMistakenForAnEffect(): void
    {
        // Everything rose by 50%: treated and controls alike.
        $row = $this->calculator(self::action(), self::productData(1.5, 1.5), ['10', '11', '12', '13', '14', '15'])
            ->verify(42);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_NO_EFFECT, $row['verdict']);
        $this->assertEqualsWithDelta(1.0, $row['effect'], 0.02);
        $this->assertLessThan(1.05, $row['ci_low']);
        $this->assertGreaterThan(0.95, $row['ci_high']);
    }

    public function testDropOnAMoreIsBetterMetricIsWorse(): void
    {
        $row = $this->calculator(self::action(), self::productData(0.4), ['10', '11', '12', '13', '14', '15'])
            ->verify(42);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_WORSE, $row['verdict']);
        $this->assertLessThan(0.95, $row['ci_high']);
    }

    /**
     * @return array
     */
    private static function pathAction(string $metric): array
    {
        return self::action([
            'rule_code' => 'bot_404',
            'metric' => $metric,
            'subjects' => '["/a","/b","/c"]',
            'rec_subject_type' => 'path',
        ]);
    }

    /**
     * @return array
     */
    private static function pathData(float $treatedLift): array
    {
        $data = [];
        foreach (['/a' => 30, '/b' => 25, '/c' => 40] as $path => $before) {
            $data[$path] = ['b' => [$before], 'a' => [round($before * $treatedLift)]];
        }
        foreach (['/x' => 28, '/y' => 33, '/z' => 41, '/w' => 22] as $path => $before) {
            $data[$path] = ['b' => [$before], 'a' => [$before]];
        }
        return $data;
    }

    public function testLessIsBetterMetricThatDroppedHelped(): void
    {
        $row = $this->calculator(
            self::pathAction(MetricProviderInterface::METRIC_BOT_404),
            self::pathData(0.1),
            ['/x', '/y', '/z', '/w'],
            '2026-03-16 04:40:00',
            ['hitRetentionDays' => 60]
        )->verify(42);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $row['verdict']);
        $this->assertSame('less_is_better', $row['details']['direction']);
        $this->assertLessThan(0.95, $row['ci_high']);
        $this->assertEqualsWithDelta((10 / 95) / 1.0, $row['effect'], 0.0001);
    }

    public function testLessIsBetterMetricThatRoseIsWorse(): void
    {
        $row = $this->calculator(
            self::pathAction(MetricProviderInterface::METRIC_BOT_404),
            self::pathData(2.0),
            ['/x', '/y', '/z', '/w'],
            '2026-03-16 04:40:00',
            ['hitRetentionDays' => 60]
        )->verify(42);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_WORSE, $row['verdict']);
        $this->assertGreaterThan(1.05, $row['ci_low']);
    }

    public function testPathErrorsVerifyFromDailyDataBeyondRawHitRetention(): void
    {
        $row = $this->calculator(
            self::pathAction(MetricProviderInterface::METRIC_BOT_404),
            self::pathData(0.1),
            ['/x', '/y', '/z', '/w']
        )->verify(42);

        $this->assertNull($row['details']['reason']);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $row['verdict'], 'fewer client errors');
        $this->assertLessThan(0.95, $row['ci_high']);
    }

    public function testRawDataOlderThanRetentionIsInsufficient(): void
    {
        $row = $this->calculator(
            self::pathAction(MetricProviderInterface::METRIC_CANONICAL_CRAWL_SHARE),
            self::pathData(0.1),
            ['/x', '/y', '/z', '/w']
        )->verify(42);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_INSUFFICIENT, $row['verdict']);
        $this->assertSame(ImpactCalculator::REASON_DATA_EXPIRED, $row['details']['reason']);
        $this->assertSame(14, $row['details']['retention_days']);
        $this->assertSame([[42, ActionLogInterface::STATUS_INSUFFICIENT]], $this->statuses);
    }

    /**
     * 40 never-fetched treated products, the first $fetched of them fetched after the change
     *
     * @param int $fetched
     * @return array
     */
    private static function coverageData(int $fetched): array
    {
        $data = [];
        for ($id = 1; $id <= 40; $id++) {
            $data['product:' . $id] = ['b' => [0], 'a' => [$id <= $fetched ? 2 : 0]];
        }
        return $data;
    }

    /**
     * Action on the 40 products of coverageData()
     *
     * @return array
     */
    private static function coverageAction(): array
    {
        return self::action(['subjects' => json_encode(array_map('strval', range(1, 40)))]);
    }

    public function testNeverFetchedProductsUseTheCoverageMethodAndCanHelp(): void
    {
        $this->baseline = ['n' => 600, 'x' => 30];
        $row = $this->calculator(self::coverageAction(), self::coverageData(12))->verify(42);

        $this->assertSame(ImpactCalculatorInterface::METHOD_COVERAGE, $row['method']);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $row['verdict']);
        $this->assertSame('newcombe_wilson', $row['details']['ci_method']);
        // 12/40 = 0.30 vs 30/600 = 0.05: difference 0.25, Newcombe 0.1290025917 to 0.4050040328 (SciPy)
        $this->assertSame(0.3, $row['treat_after']);
        $this->assertSame(0.05, $row['control_after']);
        $this->assertSame(0.25, $row['details']['coverage']['diff']);
        $this->assertSame(0.129003, $row['details']['coverage']['diff_low']);
        $this->assertSame(0.405004, $row['details']['coverage']['diff_high']);
        $this->assertSame(6.0, $row['effect']);
        $this->assertSame(3.5801, $row['ci_low']);
        $this->assertSame(9.1001, $row['ci_high']);
        $this->assertSame(600, $row['subjects_control']);
        $this->assertSame(640, $row['events']);
        $this->assertSame([[42, ActionLogInterface::STATUS_VERIFIED]], $this->statuses);

        $this->assertCount(1, $this->baselineCalls);
        [$metric, $type, $store, $ids, $lookback, $window, $sim] = $this->baselineCalls[0];
        $this->assertSame(['bot_hits', 'product', 1, false], [$metric, $type, $store, $sim]);
        $this->assertSame(range(1, 40), $ids, 'treated products are left out of the baseline');
        // Never crawled over the never-crawled rule's 90-day horizon before the before window.
        $this->assertSame(['2025-11-17', '2026-02-14'], $lookback);
        $this->assertSame(['2026-02-15', '2026-02-28'], $window);
    }

    public function testCoverageWithAnIntervalAcrossZeroIsNoEffect(): void
    {
        $this->baseline = ['n' => 600, 'x' => 30];
        $row = $this->calculator(self::coverageAction(), self::coverageData(3))->verify(42);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_NO_EFFECT, $row['verdict']);
        $this->assertSame(-0.028259, $row['details']['coverage']['diff_low']);
        $this->assertSame(0.14952, $row['details']['coverage']['diff_high']);
        $this->assertSame(1.5, $row['effect']);
        $this->assertSame(0.4348, $row['ci_low']);
        $this->assertSame(3.9904, $row['ci_high']);
    }

    public function testCoverageAgainstAZeroBaselineHasNoRatio(): void
    {
        $this->baseline = ['n' => 29, 'x' => 0];
        $data = [];
        for ($id = 1; $id <= 56; $id++) {
            $data['product:' . $id] = ['b' => [0], 'a' => [$id <= 5 ? 1 : 0]];
        }
        $action = self::action(['subjects' => json_encode(array_map('strval', range(1, 56)))]);
        $row = $this->calculator($action, $data)->verify(42);

        // 5/56 vs 0/29: Newcombe -0.0381371479 to 0.1925600139
        $this->assertSame(ImpactCalculatorInterface::VERDICT_NO_EFFECT, $row['verdict']);
        $this->assertNull($row['effect']);
        $this->assertNull($row['ci_low']);
        $this->assertSame(-0.038137, $row['details']['coverage']['diff_low']);
    }

    public function testCoverageNeedsABaseline(): void
    {
        $this->baseline = ['n' => 2, 'x' => 1];
        $row = $this->calculator(self::coverageAction(), self::coverageData(12))->verify(42);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_INSUFFICIENT, $row['verdict']);
        $this->assertSame(ImpactCalculator::REASON_TOO_FEW_CONTROLS, $row['details']['reason']);
        $this->assertSame(ImpactCalculatorInterface::METHOD_COVERAGE, $row['method']);
    }

    public function testProductsWithEarlierFetchesKeepTheControlComparison(): void
    {
        $this->baseline = ['n' => 600, 'x' => 30];
        $data = self::coverageData(12);
        $data['product:1']['b'] = [1];
        $row = $this->calculator(self::coverageAction(), $data)->verify(42);

        $this->assertSame(ImpactCalculatorInterface::METHOD_DID, $row['method']);
        $this->assertSame([], $this->baselineCalls);
    }

    /**
     * @dataProvider coverageEligibility
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('coverageEligibility')]
    public function testUsesCoverage(string $metric, string $type, array $before, bool $expected): void
    {
        $this->assertSame($expected, ImpactCalculator::usesCoverage($metric, $type, $before));
    }

    public static function coverageEligibility(): array
    {
        $zero = ['product:1' => ['num' => 0.0, 'den' => 1.0]];
        return [
            'products, nothing before' => ['bot_hits', 'product', $zero, true],
            'categories, live fetches' => ['live_fetches', 'category', $zero, true],
            'one product fetched before' => ['bot_hits', 'product', ['product:1' => ['num' => 1.0]], false],
            'paths' => ['bot_hits', 'path', $zero, false],
            'visits' => ['ai_visits', 'product', $zero, false],
        ];
    }

    /**
     * Site response-time data: daily means (100 timed hits a day) on some days of each window
     *
     * @param float[] $before day => mean ms
     * @param float[] $after day => mean ms
     * @return array
     */
    private static function dailyMeanData(array $before, array $after): array
    {
        $days = [];
        foreach ($before + $after as $day => $mean) {
            $days[$day] = [$mean * 100, 100];
        }
        $sum = static fn (array $means): array => [array_sum($means) * 100, count($means) * 100];
        return ['site' => ['b' => $sum($before), 'a' => $sum($after), 'days' => $days]];
    }

    /**
     * Site action on the average response time
     *
     * @return array
     */
    private static function speedAction(): array
    {
        return self::action([
            'rule_code' => 'custom_speed',
            'metric' => MetricProviderInterface::METRIC_BOT_AVG_MS,
            'subjects' => '["site"]',
            'rec_subject_type' => 'site',
        ]);
    }

    public function testSiteResponseTimeUsesWelchOnDailyMeans(): void
    {
        $before = ['2026-02-15' => 1500.0, '2026-02-17' => 1610.0, '2026-02-19' => 1475.0, '2026-02-21' => 1580.0,
            '2026-02-24' => 1702.0, '2026-02-26' => 1555.0, '2026-02-28' => 1530.0];
        $after = ['2026-03-02' => 1210.0, '2026-03-04' => 1350.0, '2026-03-07' => 1190.0, '2026-03-09' => 1420.0,
            '2026-03-12' => 1305.0, '2026-03-15' => 1288.0];
        $row = $this->calculator(self::speedAction(), self::dailyMeanData($before, $after))->verify(42);

        // SciPy: Welch on ln(daily means), df 8.9754339667, ratio 0.8262589761 (0.7675052764 to 0.8895103612)
        $this->assertSame(ImpactCalculatorInterface::METHOD_DAILY_MEANS, $row['method']);
        $this->assertSame('welch_t_log_daily_means', $row['details']['ci_method']);
        $this->assertSame(8.98, $row['details']['df']);
        $this->assertSame(0.8263, $row['effect']);
        $this->assertSame(0.7675, $row['ci_low']);
        $this->assertSame(0.8895, $row['ci_high']);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $row['verdict'], 'faster is better');
        $this->assertSame([7, 6], [$row['details']['days_before'], $row['details']['days_after']]);
        $this->assertSame(1300, $row['events']);
        $dayCalls = array_filter($this->valueCalls, static fn (array $call): bool => $call[3] === $call[4]);
        $this->assertCount(28, $dayCalls, 'one query per day of both 14-day windows');
    }

    public function testSiteResponseTimeNeedsDaysWithData(): void
    {
        $before = ['2026-02-15' => 1500.0, '2026-02-17' => 1610.0];
        $after = ['2026-03-02' => 1210.0, '2026-03-04' => 1350.0, '2026-03-07' => 1190.0];
        $row = $this->calculator(self::speedAction(), self::dailyMeanData($before, $after))->verify(42);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_INSUFFICIENT, $row['verdict']);
        $this->assertSame(ImpactCalculator::REASON_TOO_FEW_DAYS, $row['details']['reason']);
    }

    public function testTooFewEventsIsInsufficient(): void
    {
        $data = [];
        foreach ([1, 2, 3, 4] as $id) {
            $data['product:' . $id] = ['b' => [3], 'a' => [4]];
        }
        foreach ([10, 11, 12] as $id) {
            $data['product:' . $id] = ['b' => [3], 'a' => [3]];
        }
        $row = $this->calculator(self::action(), $data, ['10', '11', '12'])->verify(42);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_INSUFFICIENT, $row['verdict']);
        $this->assertSame(ImpactCalculator::REASON_TOO_FEW_EVENTS, $row['details']['reason']);
        $this->assertSame(28, $row['events']);
        $this->assertNull($row['ci_low']);
    }

    public function testTooFewTreatedOrControlSubjectsIsInsufficient(): void
    {
        $row = $this->calculator(
            self::action(['subjects' => '["1","2"]']),
            self::productData(1.8),
            ['10', '11', '12', '13']
        )->verify(42);
        $this->assertSame(ImpactCalculator::REASON_TOO_FEW_TREATED, $row['details']['reason']);
        $this->assertSame(2, $row['subjects_treated']);

        $row = $this->calculator(self::action(), self::productData(1.8), ['10', '11'])->verify(42);
        $this->assertSame(ImpactCalculator::REASON_TOO_FEW_CONTROLS, $row['details']['reason']);
        $this->assertSame(2, $row['subjects_control']);
    }

    public function testAllZeroControlsAreNotUsed(): void
    {
        $data = self::productData(1.8);
        foreach ([10, 11, 12, 13, 14, 15] as $id) {
            $data['product:' . $id] = ['b' => [0], 'a' => [5]];
        }
        $row = $this->calculator(self::action(), $data, ['10', '11', '12', '13', '14', '15'])->verify(42);

        $this->assertSame(0, $row['subjects_control']);
        $this->assertSame(ImpactCalculator::REASON_TOO_FEW_CONTROLS, $row['details']['reason']);
    }

    public function testZeroBeforeForTreatedIsInsufficient(): void
    {
        $data = self::pathData(1.0);
        foreach (['/a', '/b', '/c'] as $path) {
            $data[$path] = ['b' => [0], 'a' => [20]];
        }
        $row = $this->calculator(
            self::pathAction(MetricProviderInterface::METRIC_BOT_5XX),
            $data,
            ['/x', '/y', '/z', '/w']
        )->verify(42);

        $this->assertSame(ImpactCalculator::REASON_NOTHING_BEFORE, $row['details']['reason']);
    }

    public function testZeroBeforeForTreatedProductsSwitchesToCoverage(): void
    {
        $data = self::productData(1.0);
        foreach ([1, 2, 3, 4] as $id) {
            $data['product:' . $id] = ['b' => [0], 'a' => [20]];
        }
        $row = $this->calculator(self::action(), $data, ['10', '11', '12', '13', '14', '15'])->verify(42);

        $this->assertSame(ImpactCalculatorInterface::METHOD_COVERAGE, $row['method']);
        $this->assertSame(ImpactCalculator::REASON_TOO_FEW_CONTROLS, $row['details']['reason'], 'no baseline');
    }

    public function testUndoneAndUnknownActionsAreNotVerified(): void
    {
        try {
            $this->calculator(self::action(['status' => 'undone', 'undone_at' => '2026-03-03 00:00:00']), [])->verify(42);
            $this->fail('An undone action must not be verified.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('undone', $e->getMessage());
        }
        $this->expectException(LocalizedException::class);
        $this->calculator(null, [])->verify(9);
    }

    public function testDryRunWritesNothing(): void
    {
        $row = $this->calculator(self::action(), self::productData(1.8), ['10', '11', '12', '13', '14', '15'])
            ->verify(42, true);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $row['verdict']);
        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->statuses);
    }

    public function testReVerifyingGivesTheSameRow(): void
    {
        $calculator = $this->calculator(self::action(), self::productData(1.3), ['10', '11', '12', '13', '14', '15']);

        $first = $calculator->verify(42);
        $second = $calculator->verify(42);

        $this->assertSame($first, $second);
        $this->assertCount(2, $this->saved);
        $this->assertSame($this->saved[0], $this->saved[1]);
    }

    public function testEarlyVerificationUsesDaysSoFarAndKeepsTheActionPending(): void
    {
        $row = $this->calculator(
            self::action(),
            self::productData(1.8),
            ['10', '11', '12', '13', '14', '15'],
            '2026-03-08 12:00:00'
        )->verify(42);

        $this->assertFalse($row['details']['final']);
        $this->assertSame(['2026-03-02', '2026-03-07'], $row['details']['after']);
        $this->assertSame(6, $row['details']['after_days']);
        $this->assertCount(1, $this->saved);
        $this->assertSame([], $this->statuses);
    }

    public function testAfterWindowNotStarted(): void
    {
        $row = $this->calculator(self::action(), self::productData(1.8), [], '2026-03-01 18:00:00')->verify(42);

        $this->assertSame(ImpactCalculator::REASON_NOT_STARTED, $row['details']['reason']);
        $this->assertNull($row['details']['after']);
        $this->assertSame([], $this->statuses);
    }

    /**
     * @return array
     */
    private static function siteAction(string $metric): array
    {
        return self::action([
            'rule_code' => 'robots_blocks_ai_search',
            'metric' => $metric,
            'subjects' => '["site"]',
            'rec_subject_type' => 'site',
        ]);
    }

    public function testSiteActionUsesThePoissonRateRatio(): void
    {
        $row = $this->calculator(
            self::siteAction(MetricProviderInterface::METRIC_SEARCH_BOT_HITS),
            ['site' => ['b' => [200], 'a' => [300]]]
        )->verify(42);

        $this->assertSame(ImpactCalculatorInterface::METHOD_BEFORE_AFTER, $row['method']);
        $this->assertSame(1.5, $row['effect']);
        $this->assertSame('poisson_exact', $row['details']['ci_method']);
        $reference = (new PoissonRateTest())->rateRatio(300, 14, 200, 14);
        $this->assertSame(round($reference['low'], 4), $row['ci_low']);
        $this->assertSame(round($reference['high'], 4), $row['ci_high']);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $row['verdict']);
        $this->assertNull($row['control_before']);
        $this->assertSame(1, $row['subjects_treated']);
        $this->assertSame(0, $row['subjects_control']);
        $this->assertSame(500, $row['events']);
    }

    public function testSiteBacklogSnapshotWhereLessIsBetter(): void
    {
        $row = $this->calculator(
            self::siteAction(MetricProviderInterface::METRIC_CANDIDATE_BACKLOG),
            ['site' => ['b' => [40], 'a' => [5]]]
        )->verify(42);

        $this->assertSame(0.125, $row['effect']);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $row['verdict']);
    }

    public function testSiteRatioMetricUsesTheDenominatorAsExposure(): void
    {
        $row = $this->calculator(
            self::siteAction(MetricProviderInterface::METRIC_CANONICAL_CRAWL_SHARE),
            ['site' => ['b' => [600, 1000], 'a' => [900, 1000]]],
            [],
            '2026-03-16 04:40:00',
            ['hitRetentionDays' => 60]
        )->verify(42);

        $this->assertSame(0.6, $row['treat_before']);
        $this->assertSame(0.9, $row['treat_after']);
        $this->assertSame(1.5, $row['effect']);
        $this->assertSame(2000, $row['events']);
    }

    public function testSiteAverageResponseTimeWithoutDailyDataIsInsufficient(): void
    {
        $row = $this->calculator(
            self::siteAction(MetricProviderInterface::METRIC_BOT_AVG_MS),
            ['site' => ['b' => [90000, 100], 'a' => [60000, 100], 'days' => []]]
        )->verify(42);

        $this->assertSame(ImpactCalculator::REASON_TOO_FEW_DAYS, $row['details']['reason']);
        $this->assertSame(ImpactCalculatorInterface::METHOD_DAILY_MEANS, $row['method']);
        $this->assertSame(900.0, $row['treat_before']);
        $this->assertSame(600.0, $row['treat_after']);
    }

    public function testSiteTooFewEventsAndNothingBefore(): void
    {
        $row = $this->calculator(
            self::siteAction(MetricProviderInterface::METRIC_LLMS_FETCHES),
            ['site' => ['b' => [5], 'a' => [9]]]
        )->verify(42);
        $this->assertSame(ImpactCalculator::REASON_TOO_FEW_EVENTS, $row['details']['reason']);

        $row = $this->calculator(
            self::siteAction(MetricProviderInterface::METRIC_LLMS_FETCHES),
            ['site' => ['b' => [0], 'a' => [80]]]
        )->verify(42);
        $this->assertSame(ImpactCalculator::REASON_NOTHING_BEFORE, $row['details']['reason']);
    }

    public function testVerifyDueContinuesAfterAFailure(): void
    {
        $store = $this->createStub(ImpactStore::class);
        $store->method('dueActionIds')->willReturn([5, 6]);
        $store->method('getAction')->willReturnCallback(static function (int $id): array {
            if ($id === 5) {
                throw new \RuntimeException('db down');
            }
            return self::siteAction(MetricProviderInterface::METRIC_SEARCH_BOT_HITS) + ['action_id' => 6];
        });
        $metrics = $this->createStub(MetricProviderInterface::class);
        $metrics->method('supports')->willReturn(true);
        $metrics->method('value')->willReturn(['site' => ['num' => 100.0, 'den' => 1.0]]);
        $cfg = $this->createStub(Config::class);
        $cfg->method('verifyMinEvents')->willReturn(30);
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(gmmktime(4, 40, 0, 3, 16, 2026));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('action 5'));

        $calculator = new ImpactCalculator(
            $store,
            $metrics,
            $this->createStub(SubjectPool::class),
            new ControlMatcher(),
            new Bootstrap(),
            new PoissonRateTest(),
            new ProportionDifference(),
            new WelchTTest(),
            $cfg,
            $clock,
            new Json(),
            $logger
        );

        $this->assertSame(1, $calculator->verifyDue(true));
    }

    public function testWindowComesFromTheActionNotTheCurrentSetting(): void
    {
        $row = $this->calculator(
            self::action(['verify_after' => '2026-03-09']),
            self::productData(1.8),
            ['10', '11', '12', '13', '14', '15'],
            '2026-03-16 04:40:00',
            ['verifyWindowDays' => 28]
        )->verify(42);

        $this->assertSame(7, $row['window_days']);
        $this->assertSame(['2026-02-22', '2026-02-28'], $row['details']['before']);
        $this->assertSame(['2026-03-02', '2026-03-08'], $row['details']['after']);
    }

    /**
     * @dataProvider subjectTypeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('subjectTypeProvider')]
    public function testSubjectTypeFallbacks(array $action, array $subjects, string $expected): void
    {
        $this->assertSame($expected, ImpactCalculator::subjectType($action, $subjects));
    }

    /**
     * @return array
     */
    public static function subjectTypeProvider(): array
    {
        return [
            'recommendation type wins' => [['rec_subject_type' => 'category', 'rule_code' => 'bot_404'], ['5'], 'category'],
            'rule when recommendation is gone' => [['rec_subject_type' => null, 'rule_code' => 'bot_404'], ['/a'], 'path'],
            'site list' => [['rule_code' => 'custom'], ['site'], 'site'],
            'guess path' => [['rule_code' => 'custom'], ['/a', '/b'], 'path'],
            'guess product' => [['rule_code' => 'custom'], ['12'], 'product'],
            'guess bot for crawler metric' => [['rule_code' => 'custom', 'metric' => 'bot_hits'], ['gptbot'], 'bot'],
            'guess source for visit metric' => [['rule_code' => 'custom', 'metric' => 'ai_visits'], ['chatgpt'], 'source'],
        ];
    }

    /**
     * @dataProvider verdictProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('verdictProvider')]
    public function testVerdictThresholds(bool $lessIsBetter, float $low, float $high, string $expected): void
    {
        $this->assertSame($expected, ImpactCalculator::verdict($lessIsBetter, $low, $high));
    }

    /**
     * @return array
     */
    public static function verdictProvider(): array
    {
        return [
            'more: clearly up' => [false, 1.06, 1.5, 'helped'],
            'more: low exactly at 1.05' => [false, 1.05, 1.5, 'no_effect'],
            'more: straddles' => [false, 0.9, 1.2, 'no_effect'],
            'more: clearly down' => [false, 0.5, 0.94, 'worse'],
            'more: high exactly at 0.95' => [false, 0.5, 0.95, 'no_effect'],
            'less: clearly down' => [true, 0.2, 0.94, 'helped'],
            'less: straddles' => [true, 0.8, 1.1, 'no_effect'],
            'less: clearly up' => [true, 1.06, 2.0, 'worse'],
        ];
    }

    public function testControlsAreMatchedOnThePreAndTheBeforeWindow(): void
    {
        $data = self::productData(1.0);
        // Before-window values alone would pick 14 / 12 first; only 10 and 15 are alike in both windows.
        foreach ([10 => 59, 11 => 5, 12 => 5, 13 => 5, 14 => 5, 15 => 61] as $id => $pre) {
            $data['product:' . $id]['p'] = [$pre];
        }
        foreach ([1 => 61, 2 => 60, 3 => 59, 4 => 58] as $id => $pre) {
            $data['product:' . $id]['p'] = [$pre];
        }
        $row = $this->calculator(
            self::action(),
            $data,
            ['10', '11', '12', '13', '14', '15'],
            '2026-03-16 04:40:00',
            ['verifyMaxControls' => 3]
        )->verify(42);

        $this->assertSame(ImpactCalculator::MATCH_WINDOWS, $row['details']['match_window']);
        $this->assertSame('pre+before', $row['details']['match_window']);
        $this->assertSame(['2026-02-01', '2026-02-14'], $row['details']['pre_window']);
        // Treated 3 (pre 59, before 60) is busiest and takes 10 (59, 42); treated 2 (60, 55) takes 15 (61, 39).
        $this->assertSame('product:10', $row['details']['controls'][0]);
        $this->assertSame('product:15', $row['details']['controls'][1]);
        $calls = array_values(array_filter(
            $this->valueCalls,
            static fn (array $call): bool => $call[3] === '2026-02-01'
        ));
        $this->assertCount(2, $calls, 'treated and candidates are read for the earlier window');
        // Candidates come from both windows; the history check looks back from the first pre day.
        $ranges = array_map(static fn (array $call): string => $call[3] . '/' . $call[4], $this->candidateCalls);
        $this->assertContains('2026-02-01/2026-02-14', $ranges);
        $this->assertContains('2026-02-15/2026-02-28', $ranges);
        $this->assertContains('2025-11-03/2026-02-01', $ranges);
    }

    public function testWithoutComparisonHistoryTheVerdictIsInsufficient(): void
    {
        // Daily summaries start on 2026-02-02 (e.g. the 1.0 to 1.1 upgrade rebuilt only that far back): the
        // pre window starts 2026-02-01, so there is no comparison history and no before-window-only match.
        $this->historyStarts = '2026-02-02';
        $data = self::productData(1.8);
        $row = $this->calculator(self::action(), $data, ['10', '11', '12', '13', '14', '15'])->verify(42);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_INSUFFICIENT, $row['verdict']);
        $this->assertSame(ImpactCalculator::REASON_NO_HISTORY, $row['details']['reason']);
        $this->assertSame('comparison history not available yet', str_replace('_', ' ', $row['details']['reason']));
        $this->assertSame(0, $row['subjects_control']);
        $this->assertSame([], $row['details']['controls']);
        $this->assertNull($row['effect']);
        $this->assertNull($row['ci_low']);
        $this->assertSame(200.0, $row['treat_before'], 'treated values are still shown');
        $this->assertSame([[42, ActionLogInterface::STATUS_INSUFFICIENT]], $this->statuses);

        // Data from the first pre day on is enough.
        $this->historyStarts = '2026-02-01';
        $this->statuses = [];
        $row = $this->calculator(self::action(), $data, ['10', '11', '12', '13', '14', '15'])->verify(42);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $row['verdict']);
        $this->assertSame(6, $row['subjects_control']);
    }

    public function testEarlierWindowOlderThanTheDailyRetentionMeansNoComparisonHistory(): void
    {
        // Window 1 day, verified 2026-03-31 with 1 month of daily rows (kept from 2026-02-28): the before
        // day 2026-02-28 is kept, the earlier day 2026-02-27 is gone.
        $row = $this->calculator(
            self::action(['verify_after' => '2026-03-03']),
            self::productData(1.8),
            ['10', '11', '12', '13', '14', '15'],
            '2026-03-31 04:40:00',
            ['dailyRetentionMonths' => 1]
        )->verify(42);

        $this->assertSame(1, $row['window_days']);
        $this->assertNotSame(ImpactCalculator::REASON_DATA_EXPIRED, $row['details']['reason']);
        $this->assertSame(ImpactCalculator::REASON_NO_HISTORY, $row['details']['reason']);
        $this->assertSame(['2026-02-27', '2026-02-27'], $row['details']['pre_window']);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_INSUFFICIENT, $row['verdict']);
    }

    public function testCrossCheckKeepsAFallBackFromBecomingAVerdict(): void
    {
        // Treated 1-4 were picked as the busiest of the before window (20 hits, usually 10, more than any
        // candidate); candidates had 8-17 and then their usual 10 again: nothing changed, so no effect.
        $data = [];
        $pool = [];
        foreach ([1, 2, 3, 4] as $id) {
            $data['product:' . $id] = ['p' => [10], 'b' => [20], 'a' => [10]];
        }
        for ($id = 100; $id < 140; $id++) {
            $before = 8 + ($id % 10);
            $data['product:' . $id] = ['p' => [10], 'b' => [$before], 'a' => [10 + ($id % 3) - 1]];
            $pool[] = (string)$id;
        }
        $row = $this->calculator(self::action(), $data, $pool, '2026-03-16 04:40:00', ['verifyMaxControls' => 8])
            ->verify(42);

        $this->assertArrayNotHasKey('bias_correction', $row['details'], 'no outcome model since the caliper');
        $this->assertSame(ImpactCalculatorInterface::VERDICT_NO_EFFECT, $row['verdict']);
        $this->assertSame('both', $row['details']['decided_by']);
        // Main estimate alone: treated 20 -> 10 against controls 16-17 -> 10 leans "worse" (~0.85); the
        // pre-baseline check (10 -> 10 for both) says 1.0, and the union keeps it from a verdict.
        $this->assertLessThan(0.95, $row['effect']);
        $this->assertSame('bootstrap_union', $row['details']['ci_method']);
        $check = $row['details']['cross_check'];
        $this->assertSame('pre_baseline_before_matched', $check['method']);
        $this->assertEqualsWithDelta(1.0, $check['effect'], 0.1);
        $this->assertLessThanOrEqual(min($check['ci_low'], 1.0), $row['ci_low']);
        $this->assertGreaterThanOrEqual(max($check['ci_high'], 1.0), $row['ci_high']);
        // The matched controls fell back less (16-17 -> 10) than the treated (20 -> 10): raw DiD says worse.
        $this->assertGreaterThan((float)$row['control_after'] * 1.5, (float)$row['control_before']);
        $this->assertSame(80.0, $row['treat_before']);
        $this->assertSame(40.0, $row['treat_after']);
    }

    public function testBeforeWindowOlderThanTheDailyRetentionIsInsufficient(): void
    {
        $row = $this->calculator(
            self::action(),
            self::productData(1.8),
            ['10', '11', '12', '13', '14', '15'],
            '2026-04-20 04:40:00',
            ['dailyRetentionMonths' => 3]
        )->verify(42);
        $this->assertNull($row['details']['reason'], 'inside 3 months');

        $row = $this->calculator(
            self::action(),
            self::productData(1.8),
            ['10', '11', '12', '13', '14', '15'],
            '2026-04-20 04:40:00',
            ['dailyRetentionMonths' => 2]
        )->verify(42);
        // Daily rows from 2026-02-20 on are kept; the before window starts 2026-02-15.
        $this->assertSame(ImpactCalculator::REASON_DATA_EXPIRED, $row['details']['reason']);
        $this->assertSame(59, $row['details']['retention_days']);
    }

    public function testTreatedSubjectsWithNothingAfterAreFlagged(): void
    {
        $row = $this->calculator(self::action(), self::productData(0.0), ['10', '11', '12', '13', '14', '15'])
            ->verify(42);
        $this->assertTrue($row['details']['treated_after_zero']);
        $this->assertSame(0.0, $row['effect']);

        $row = $this->calculator(self::action(), self::productData(1.0), ['10', '11', '12', '13', '14', '15'])
            ->verify(42);
        $this->assertFalse($row['details']['treated_after_zero']);
    }

    public function testCoverageBelowTheBaselineIsNoEffectNotWorse(): void
    {
        $data = [];
        foreach (range(1, 10) as $id) {
            $data['product:' . $id] = ['b' => [0], 'a' => [0]];
        }
        $this->baseline = ['n' => 48, 'x' => 30];
        $row = $this->calculator(
            self::action(['subjects' => json_encode(array_map('strval', range(1, 10)))]),
            $data
        )->verify(42);

        $this->assertSame(ImpactCalculatorInterface::METHOD_COVERAGE, $row['method']);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_NO_EFFECT, $row['verdict']);
        $this->assertTrue($row['details']['coverage']['below_baseline']);
    }

    public function testBacklogNeedsOnlyAFewItems(): void
    {
        $row = $this->calculator(
            self::action([
                'rule_code' => 'discovery_backlog',
                'metric' => MetricProviderInterface::METRIC_CANDIDATE_BACKLOG,
                'subjects' => '["site"]',
                'rec_subject_type' => 'site',
            ]),
            ['site' => ['b' => [12], 'a' => [0]]]
        )->verify(42);

        $this->assertSame(ImpactCalculator::MIN_ITEM_EVENTS, $row['details']['min_events']);
        $this->assertNotSame(ImpactCalculator::REASON_TOO_FEW_EVENTS, $row['details']['reason']);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $row['verdict']);
        $this->assertSame(30, ImpactCalculator::minEvents(MetricProviderInterface::METRIC_BOT_HITS, 30));
        $this->assertSame(3, ImpactCalculator::minEvents(MetricProviderInterface::METRIC_CANDIDATE_BACKLOG, 3));
    }

    public function testAFinalVerdictIsNotReplacedByInsufficient(): void
    {
        $this->storedImpact = ['action_id' => 42, 'verdict' => 'helped', 'effect' => '1.6000',
            'details' => '{"reason":null,"final":true}'];
        // Re-verified when the paths have no data any more: computed "insufficient".
        $action = self::pathAction(MetricProviderInterface::METRIC_BOT_404);
        $action['status'] = ActionLogInterface::STATUS_VERIFIED;
        $row = $this->calculator($action, [], ['/x', '/y', '/z'])->verify(42);

        $this->assertSame('helped', $row['verdict']);
        $this->assertTrue($row['details']['kept_previous_verdict']);
        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->statuses);
    }

    public function testInsufficientIsStillStoredForAPendingAction(): void
    {
        $this->storedImpact = ['action_id' => 42, 'verdict' => 'helped', 'details' => '{}'];
        $row = $this->calculator(self::pathAction(MetricProviderInterface::METRIC_BOT_404), [], ['/x'])->verify(42);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_INSUFFICIENT, $row['verdict']);
        $this->assertCount(1, $this->saved);
    }

    public function testPagesWithoutComparableControlsAreInsufficient(): void
    {
        // The treated pages started erroring with the before window (nothing in the pre window); every
        // candidate is an old page, alike in neither window: no pair without extrapolation.
        $data = [];
        foreach (['/a' => 400, '/b' => 420, '/c' => 380] as $path => $before) {
            $data[$path] = ['p' => [0], 'b' => [$before], 'a' => [$before]];
        }
        $pool = [];
        for ($i = 0; $i < 30; $i++) {
            $data['/old-' . $i] = ['p' => [20 + $i], 'b' => [20 + $i], 'a' => [20 + $i]];
            $pool[] = '/old-' . $i;
        }
        $row = $this->calculator(self::pathAction(MetricProviderInterface::METRIC_BOT_404), $data, $pool)->verify(42);

        $this->assertSame(ImpactCalculatorInterface::VERDICT_INSUFFICIENT, $row['verdict']);
        $this->assertSame(ImpactCalculator::REASON_NO_COMPARABLE, $row['details']['reason']);
        $this->assertSame('no comparable subjects', str_replace('_', ' ', $row['details']['reason']));
        $this->assertSame(0, $row['subjects_control']);
        $this->assertSame(ImpactCalculator::CALIPER, $row['details']['caliper']);
    }

    /**
     * @return array<string, array{0: float, 1: string}>
     */
    public static function onsetProvider(): array
    {
        return [
            'unchanged' => [1.0, ImpactCalculatorInterface::VERDICT_NO_EFFECT],
            '404s gone' => [0.02, ImpactCalculatorInterface::VERDICT_HELPED],
        ];
    }

    /**
     * @dataProvider onsetProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('onsetProvider')]
    public function testOnsetControlsLetTheMainEstimateDecideAlone(float $lift, string $expected): void
    {
        // Treated and 12 untreated pages all started erroring with the before window. The pre-baseline
        // check is undefined (nothing in the pre window), the caliper-matched main estimate decides.
        $data = [];
        foreach (['/a' => 400, '/b' => 420, '/c' => 380] as $path => $before) {
            $data[$path] = ['p' => [0], 'b' => [$before], 'a' => [round($before * $lift)]];
        }
        $pool = [];
        for ($i = 0; $i < 12; $i++) {
            $before = 300 + 20 * $i;
            $data['/onset-' . $i] = ['p' => [$i % 2], 'b' => [$before], 'a' => [$before + ($i % 3) - 1]];
            $pool[] = '/onset-' . $i;
        }
        for ($i = 0; $i < 30; $i++) {
            $data['/old-' . $i] = ['p' => [20 + $i], 'b' => [20 + $i], 'a' => [20 + $i]];
            $pool[] = '/old-' . $i;
        }
        $row = $this->calculator(self::pathAction(MetricProviderInterface::METRIC_BOT_404), $data, $pool)->verify(42);

        $this->assertSame($expected, $row['verdict']);
        $this->assertNull($row['details']['cross_check']);
        $this->assertSame('main_only', $row['details']['decided_by']);
        $this->assertGreaterThanOrEqual(3, $row['subjects_control']);
        foreach ($row['details']['controls'] as $control) {
            $this->assertStringStartsWith('/onset-', $control, 'only pages alike in both windows');
        }
    }
}
