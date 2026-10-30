<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

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

/**
 * Deep test E1 (1.1 verification statistics).
 *
 * Expected numbers come from an independent Python re-implementation written from the 1.1 spec
 * (xoshiro128** port, binomial-tail Clopper-Pearson, Simpson-integrated Student t, Wilson/Newcombe)
 * that reproduced every live verdict on store 1 to 4 decimals. Tests named "...Defect..." fail on
 * purpose: they prove a defect found by the deep test.
 */
class V11VerificationTest extends TestCase
{
    /** @var array */
    private array $saved = [];

    /** @var array */
    private array $statuses = [];

    /** @var array */
    private array $baselineCalls = [];

    /** @var array{n: int, x: int} */
    private array $baseline = ['n' => 0, 'x' => 0];

    /**
     * @return array<string, array{0: array, 1: array, 2: int, 3: int, 4: float, 5: float}>
     */
    public static function bootstrapProvider(): array
    {
        $treated = [['bn' => 40, 'bd' => 1, 'an' => 61, 'ad' => 1], ['bn' => 55, 'bd' => 1, 'an' => 80, 'ad' => 1],
            ['bn' => 60, 'bd' => 1, 'an' => 95, 'ad' => 1], ['bn' => 45, 'bd' => 1, 'an' => 70, 'ad' => 1]];
        $control = [['bn' => 42, 'bd' => 1, 'an' => 43, 'ad' => 1], ['bn' => 50, 'bd' => 1, 'an' => 49, 'ad' => 1],
            ['bn' => 58, 'bd' => 1, 'an' => 60, 'ad' => 1], ['bn' => 47, 'bd' => 1, 'an' => 45, 'ad' => 1],
            ['bn' => 61, 'bd' => 1, 'an' => 63, 'ad' => 1], ['bn' => 39, 'bd' => 1, 'an' => 41, 'ad' => 1]];
        return [
            'seed 42, 1000 resamples' => [$treated, $control, 42, 1000, 1.4448067070330692, 1.5728801790591487],
            'seed 7, 200 resamples' => [$treated, $control, 7, 200, 1.4408526415659646, 1.5772227871387026],
        ];
    }

    /**
     * @dataProvider bootstrapProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bootstrapProvider')]
    public function testBootstrapIntervalEqualsIndependentPythonPort(
        array $treated,
        array $control,
        int $seed,
        int $resamples,
        float $low,
        float $high
    ): void {
        $bootstrap = new Bootstrap();
        $this->assertEqualsWithDelta(1.5096677740863789, $bootstrap->ratio($treated, $control), 1e-12);
        $interval = $bootstrap->interval($treated, $control, $seed, $resamples);
        $this->assertNotNull($interval);
        $this->assertSame($resamples, $interval['valid']);
        $this->assertEqualsWithDelta($low, $interval['low'], 1e-12);
        $this->assertEqualsWithDelta($high, $interval['high'], 1e-12);
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: float, 3: float, 4: string, 5: float, 6: float, 7: float}>
     */
    public static function poissonProvider(): array
    {
        return [
            'exact, +30%' => [130, 14, 100, 14, 'exact', 1.3, 0.9939622683562878, 1.7044993024473334],
            'normal above 5000 events' => [6000, 14, 5200, 14, 'normal', 1.1538461538461537, 1.1117842457767826,
                1.1974993815597426],
            'nothing after' => [0, 14, 40, 14, 'exact', 0.0, 0.0, 0.09660822712436137],
            'partial after window (7 of 14 days)' => [45, 7, 100, 14, 'exact', 0.9, 0.6184925526752635,
                1.2917785545497593],
        ];
    }

    /**
     * @dataProvider poissonProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('poissonProvider')]
    public function testPoissonRateRatioEqualsIndependentValues(
        float $after,
        float $afterExposure,
        float $before,
        float $beforeExposure,
        string $method,
        float $ratio,
        float $low,
        float $high
    ): void {
        $test = (new PoissonRateTest())->rateRatio($after, $afterExposure, $before, $beforeExposure);
        $this->assertNotNull($test);
        $this->assertSame($method, $test['method']);
        $this->assertEqualsWithDelta($ratio, $test['ratio'], 1e-12);
        $this->assertEqualsWithDelta($low, $test['low'], 1e-9);
        $this->assertEqualsWithDelta($high, $test['high'], 1e-9);
    }

    public function testWelchEqualsNumericallyIntegratedStudentT(): void
    {
        $after = [0.10, 0.05, 0.12, 0.08, 0.02, 0.11, 0.07];
        $before = [0.30, 0.42, 0.25, 0.36, 0.31, 0.40, 0.28, 0.33, 0.38, 0.29];
        $test = (new WelchTTest())->interval($after, $before);
        $this->assertNotNull($test);
        $this->assertEqualsWithDelta(-0.25342857142857145, $test['diff'], 1e-12);
        $this->assertEqualsWithDelta(14.91450019387361, $test['df'], 1e-9);
        $this->assertEqualsWithDelta(-0.3006877137772122, $test['low'], 2e-6);
        $this->assertEqualsWithDelta(-0.2061694290799307, $test['high'], 2e-6);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: float, 5: float, 6: float}>
     */
    public static function newcombeProvider(): array
    {
        return [
            '8/10 vs 12/150' => [8, 10, 12, 150, 0.7200000000000001, 0.40538469931954974, 0.8672144121708264],
            '0/10 vs 30/150' => [0, 10, 30, 150, -0.2, -0.27114133079799635, 0.08315774573049844],
            '3/10 vs 30/150' => [3, 10, 30, 150, 0.09999999999999998, -0.10495191102545504, 0.4083785754953447],
        ];
    }

    /**
     * @dataProvider newcombeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('newcombeProvider')]
    public function testNewcombeEqualsIndependentValues(
        int $x1,
        int $n1,
        int $x0,
        int $n0,
        float $diff,
        float $low,
        float $high
    ): void {
        $test = (new ProportionDifference())->newcombe($x1, $n1, $x0, $n0);
        $this->assertNotNull($test);
        $this->assertEqualsWithDelta($diff, $test['diff'], 1e-12);
        $this->assertEqualsWithDelta($low, $test['low'], 1e-12);
        $this->assertEqualsWithDelta($high, $test['high'], 1e-12);
    }

    public function testCalculatorDidIntervalAndControlOrderEqualReference(): void
    {
        $data = [];
        foreach ([1 => [40, 61], 2 => [55, 80], 3 => [60, 95], 4 => [45, 70],
            10 => [42, 43], 11 => [50, 49], 12 => [58, 60], 13 => [47, 45], 14 => [61, 63], 15 => [39, 41]] as $id => $v) {
            $data['product:' . $id] = $v;
        }
        $row = $this->calculator(self::action(), $data, ['10', '11', '12', '13', '14', '15'])->verify(42);

        $this->assertSame(ImpactCalculatorInterface::METHOD_DID, $row['method']);
        $this->assertSame(
            ['product:14', 'product:12', 'product:13', 'product:15', 'product:11', 'product:10'],
            $row['details']['controls']
        );
        $this->assertSame(round(1.5096677740863789, 4), $row['effect']);
        $this->assertSame(round(1.449750268938691, 4), $row['ci_low']);
        $this->assertSame(round(1.5694753602926808, 4), $row['ci_high']);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $row['verdict']);
    }

    /**
     * @return array<string, array{0: bool, 1: float, 2: float, 3: string}>
     */
    public static function boundaryProvider(): array
    {
        return [
            'more: low exactly 1.05' => [false, 1.05, 2.0, 'no_effect'],
            'more: low just above 1.05' => [false, 1.0500001, 2.0, 'helped'],
            'more: high exactly 0.95' => [false, 0.5, 0.95, 'no_effect'],
            'more: high just below 0.95' => [false, 0.5, 0.9499999, 'worse'],
            'less: high exactly 0.95' => [true, 0.1, 0.95, 'no_effect'],
            'less: high just below 0.95' => [true, 0.1, 0.9499999, 'helped'],
            'less: low exactly 1.05' => [true, 1.05, 3.0, 'no_effect'],
            'less: low just above 1.05' => [true, 1.0500001, 3.0, 'worse'],
            'interval across both bounds' => [false, 0.9, 1.1, 'no_effect'],
        ];
    }

    /**
     * @dataProvider boundaryProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('boundaryProvider')]
    public function testVerdictBoundariesAreStrict(bool $less, float $low, float $high, string $expected): void
    {
        $this->assertSame($expected, ImpactCalculator::verdict($less, $low, $high));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: int, 4: string|null}>
     */
    public static function windowProvider(): array
    {
        // Applied 2026-03-01 (any time of day), window 14, verify_after 2026-03-16.
        return [
            'applied day itself' => ['2026-03-01 23:00:00', 'insufficient', false, 0, null],
            'day after: change day excluded, nothing complete' => ['2026-03-02 12:00:00', 'insufficient', false, 0, null],
            'two days after: 1 day so far, still pending' => ['2026-03-03 00:00:00', 'helped', false, 1, '2026-03-02'],
            'last second before verify_after' => ['2026-03-15 23:59:59', 'helped', false, 13, '2026-03-14'],
            'verify_after 00:00 UTC: final' => ['2026-03-16 00:00:00', 'helped', true, 14, '2026-03-15'],
            'long after: after window capped at W' => ['2026-05-01 00:00:00', 'helped', true, 14, '2026-03-15'],
        ];
    }

    /**
     * @dataProvider windowProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('windowProvider')]
    public function testVerifyAfterBoundariesInUtcDays(
        string $now,
        string $verdict,
        bool $final,
        int $afterDays,
        ?string $afterEnd
    ): void {
        $row = $this->calculator(
            self::action(['applied_at' => '2026-03-01 23:59:59']),
            self::liftData(2.0),
            ['10', '11', '12', '13', '14', '15'],
            $now
        )->verify(42);

        $this->assertSame($verdict, $row['verdict']);
        $this->assertSame($final, $row['details']['final']);
        $this->assertSame($afterDays, $row['details']['after_days']);
        $this->assertSame(['2026-02-15', '2026-02-28'], $row['details']['before']);
        if ($afterEnd === null) {
            $this->assertSame(ImpactCalculator::REASON_NOT_STARTED, $row['details']['reason']);
            $this->assertNull($row['details']['after']);
        } else {
            $this->assertSame(['2026-03-02', $afterEnd], $row['details']['after']);
        }
        // Only a final computation changes the action status.
        $this->assertSame($final ? [[42, ActionLogInterface::STATUS_VERIFIED]] : [], $this->statuses);
    }

    public function testTamperedVerifyAfterFallsBackToTheConfiguredWindow(): void
    {
        $row = $this->calculator(
            self::action(['verify_after' => '2027-01-01']),
            self::liftData(2.0),
            ['10', '11', '12', '13', '14', '15'],
            '2026-04-01 04:40:00',
            ['verifyWindowDays' => 21]
        )->verify(42);

        $this->assertSame(21, $row['window_days']);
        $this->assertSame(['2026-02-08', '2026-02-28'], $row['details']['before']);
        $this->assertSame(['2026-03-02', '2026-03-22'], $row['details']['after']);
    }

    public function testUndoneActionIsRefusedAndNothingIsWritten(): void
    {
        $calculator = $this->calculator(
            self::action(['status' => ActionLogInterface::STATUS_UNDONE, 'undone_at' => '2026-03-05 10:00:00']),
            self::liftData(2.0)
        );
        try {
            $calculator->verify(42);
            $this->fail('An undone action must not be verified.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('undone', $e->getMessage());
        }
        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->statuses);
    }

    public function testReVerifyingIsIdempotentApartFromComputedAt(): void
    {
        $calculator = $this->calculator(self::action(), self::liftData(1.5), ['10', '11', '12', '13', '14', '15']);
        $first = $calculator->verify(42);
        $second = $calculator->verify(42);
        unset($first['computed_at'], $second['computed_at']);
        $this->assertSame($first, $second);
    }

    /**
     * DEFECT (minor): the verdict is decided on the unrounded interval but the stored / displayed bound is
     * rounded to 4 decimals, so a stored row can read ci_low = 1.0500 with verdict "helped" although the
     * documented rule is "helped when ci_low > 1.05". Bootstrap low 1.05004 here.
     */
    public function testDefectStoredBoundContradictsVerdictAtTheThreshold(): void
    {
        $poisson = $this->createStub(PoissonRateTest::class);
        $poisson->method('rateRatio')->willReturn(
            ['ratio' => 1.2, 'low' => 1.05004, 'high' => 1.4, 'method' => 'exact']
        );
        $row = $this->calculator(
            self::action(['rule_code' => 'llms_not_served', 'rec_subject_type' => 'site', 'subjects' => '["site"]',
                'metric' => MetricProviderInterface::METRIC_LLMS_FETCHES]),
            ['site' => [100, 120]],
            [],
            '2026-03-16 04:40:00',
            [],
            $poisson
        )->verify(42);

        $this->assertSame(
            ImpactCalculator::verdict(false, (float)$row['ci_low'], (float)$row['ci_high']),
            $row['verdict'],
            'stored ci_low ' . $row['ci_low'] . ' must support the stored verdict ' . $row['verdict']
        );
    }

    /**
     * DEFECT (major): with the default retention (raw hits 14 days, raw visits 30 days) a metric read from raw
     * rows cannot be verified: parameter_crawl_waste (R8, canonical_crawl_share, site) is ALWAYS
     * "insufficient" at verify_after (its before window starts 29 days back), and with a window above 14 days
     * (allowed 7-56) R5 live_answers_no_sales (ai_conversion by path) and R9 ai_visitors_out_of_stock
     * (ai_visits by product) are always insufficient too. Even with W = 14, verifying one day late (cron
     * missed a day) loses R5 / R9 for good. Data is plentiful in every case.
     *
     * @return array<string, array{0: array, 1: array, 2: string, 3: int}>
     */
    public static function rawSourceProvider(): array
    {
        return [
            'R8 canonical share, W 14, on verify_after' => [
                ['rule_code' => 'parameter_crawl_waste', 'rec_subject_type' => 'site', 'subjects' => '["site"]',
                    'metric' => MetricProviderInterface::METRIC_CANONICAL_CRAWL_SHARE],
                ['site' => [[5000, 9000], [7000, 9000]]],
                '2026-03-16 04:40:00',
                14,
            ],
            'R9 ai_visits by product, W 15, on verify_after' => [
                ['rule_code' => 'ai_visitors_out_of_stock', 'metric' => MetricProviderInterface::METRIC_AI_VISITS,
                    'verify_after' => '2026-03-17'],
                self::liftData(2.0),
                '2026-03-17 04:40:00',
                15,
            ],
            'R9 ai_visits by product, W 14, verified one day late' => [
                ['rule_code' => 'ai_visitors_out_of_stock', 'metric' => MetricProviderInterface::METRIC_AI_VISITS],
                self::liftData(2.0),
                '2026-03-17 04:40:00',
                14,
            ],
        ];
    }

    /**
     * @dataProvider rawSourceProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rawSourceProvider')]
    public function testDefectRawSourceMetricsCannotBeVerifiedWithDefaultRetention(
        array $override,
        array $data,
        string $now,
        int $window
    ): void {
        $row = $this->calculator(self::action($override), $data, ['10', '11', '12', '13', '14', '15'], $now)
            ->verify(42);

        $this->assertSame($window, $row['window_days']);
        $this->assertNotSame(
            ImpactCalculator::REASON_DATA_EXPIRED,
            $row['details']['reason'],
            'a fix with ample data must be verifiable with the default retention settings'
        );
    }

    /**
     * DEFECT (minor, CLI only): re-verifying a final verdict after the raw rows expired replaces "verified /
     * helped" with "insufficient" and flips the action status, contradicting "re-verifying replaces the impact
     * row with the same result".
     */
    public function testDefectReVerifyingAfterRetentionDestroysAFinalVerdict(): void
    {
        $action = self::action(['rule_code' => 'ai_visitors_out_of_stock',
            'metric' => MetricProviderInterface::METRIC_AI_VISITS]);
        $first = $this->calculator($action, self::liftData(2.0), ['10', '11', '12', '13', '14', '15'])->verify(42);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $first['verdict']);

        $later = $this->calculator(
            $action + ['status' => ActionLogInterface::STATUS_VERIFIED],
            self::liftData(2.0),
            ['10', '11', '12', '13', '14', '15'],
            '2026-03-20 10:00:00'
        )->verify(42);

        $this->assertSame($first['verdict'], $later['verdict'], 'a final verdict must survive re-verification');
        $this->assertNotContains([42, ActionLogInterface::STATUS_INSUFFICIENT], $this->statuses);
    }

    public function testCoverageEqualsLiveReference(): void
    {
        // Live action #106 on store 1: 10/10 treated fetched vs baseline 30/48 -> helped, +60% (+11.4% to +82.6%).
        $data = [];
        foreach (range(1, 10) as $id) {
            $data['product:' . $id] = [0, 1];
        }
        $this->baseline = ['n' => 48, 'x' => 30];
        $row = $this->calculator(
            self::action(['subjects' => json_encode(array_map('strval', range(1, 10)))]),
            $data
        )->verify(42);

        $this->assertSame(ImpactCalculatorInterface::METHOD_COVERAGE, $row['method']);
        $this->assertSame(ImpactCalculatorInterface::VERDICT_HELPED, $row['verdict']);
        $this->assertSame(1.6, $row['effect']);
        $this->assertSame(round(1.1143903208202934, 4), $row['ci_low']);
        $this->assertSame(round(1.8261957836925558, 4), $row['ci_high']);
        $this->assertSame(round(0.071494, 6), $row['details']['coverage']['diff_low']);
    }

    /**
     * DEFECT (major): the coverage baseline must be products that were never crawled (spec must-do C: "never
     * crawled as of applied - 2W", R4: "not crawled in 90 days"), but the calculator only checks a W-day
     * lookback [applied - 2W, applied - W - 1]. Intermittently crawled products enter the baseline, so its
     * first-fetch share is inflated: live action #101 (10 never-crawled products, nothing changed) was reported
     * "likely made it worse, -100% (-100% to -50.2%)" against 30/48; the spec baseline was 1/7 (no effect).
     */
    public function testDefectCoverageBaselineLooksBackOnlyOneWindow(): void
    {
        $data = [];
        foreach (range(1, 10) as $id) {
            $data['product:' . $id] = [0, 0];
        }
        $this->baseline = ['n' => 48, 'x' => 30];
        $row = $this->calculator(
            self::action(['subjects' => json_encode(array_map('strval', range(1, 10)))]),
            $data
        )->verify(42);

        $this->assertSame(ImpactCalculatorInterface::METHOD_COVERAGE, $row['method']);
        $lookback = $this->baselineCalls[0][4];
        $this->assertLessThanOrEqual(
            '2025-12-01',
            $lookback[0],
            'baseline products must be never crawled over the R4 horizon (90 days), not just ' . implode('..', $lookback)
        );
        $this->assertNotSame(ImpactCalculatorInterface::VERDICT_WORSE, $row['verdict'], 'nothing can drop below zero fetches');
    }

    /**
     * DEFECT (major): controls are matched on the same noisy "before" counts that form the DiD denominator, so
     * when the treated subjects are busier than most candidates (R4/R6/R9 subjects are the popular head) the
     * matcher picks candidates that were lucky in the before window; they regress to their mean afterwards and
     * the DiD ratio is inflated. Null world below (nothing changes for anyone): the method says "likely helped"
     * far more often than the 2.5% a 95% interval allows. Live on store 1 (fix-no-effect, scale 0.1, 20 seeds)
     * it said "likely helped" 6 / 20 times, mean effect 1.26, controls 470 -> 385 while all untreated products
     * stayed flat; matching on the earlier window [applied - 2W, applied - W - 1] gave 0 / 8 and effect ~1.08.
     */
    public function testDefectMatchingOnTheBeforeWindowInflatesNullEffects(): void
    {
        $trials = 40;
        $helped = 0;
        for ($trial = 0; $trial < $trials; $trial++) {
            $prng = new \Panth\AiVisibility\Service\Simulator\Prng($trial, 'e1-null');
            $data = [];
            $rates = [];
            foreach (range(1, 10) as $id) {
                $data['product:' . $id] = [self::poisson($prng, 6.0), self::poisson($prng, 6.0)];
                $rates['product:' . $id] = 6.0;
            }
            $pool = [];
            for ($j = 0; $j < 300; $j++) {
                $lambda = 0.5 + 5.5 * $j / 299;
                $data['product:' . (100 + $j)] = [self::poisson($prng, $lambda), self::poisson($prng, $lambda)];
                $rates['product:' . (100 + $j)] = $lambda;
                $pool[] = (string)(100 + $j);
            }
            // Earlier window [applied - 2W, applied - W - 1]: an independent draw at the same rates (the
            // harness otherwise answers every range before the applied day with the before value).
            foreach ($rates as $ref => $lambda) {
                $data[$ref][2] = self::poisson($prng, $lambda);
            }
            $row = $this->calculator(
                self::action(['action_id' => $trial + 1, 'subjects' => json_encode(array_map('strval', range(1, 10)))]),
                $data,
                $pool
            )->verify($trial + 1);
            $helped += $row['verdict'] === ImpactCalculatorInterface::VERDICT_HELPED ? 1 : 0;
        }
        $this->assertLessThanOrEqual(
            (int)ceil(0.10 * $trials),
            $helped,
            sprintf('null world (no change anywhere) judged "likely helped" in %d of %d trials', $helped, $trials)
        );
    }

    /**
     * DEFECT (major): controls matched on the earlier window only. The 4xx and slow-page rules pick their
     * subjects BY EXTREMENESS in the days before the change (the top paths of the last 7 days), so the
     * treated paths fall back towards their usual level afterwards while controls that only look like them
     * in the earlier window do not. Null world below (nothing changes for anyone, treated = the 10 paths
     * with the most 404s in the before window): matching on the earlier window alone said "likely helped"
     * in most trials (live repro: top 10 404 paths of a week, nothing changed, helped -56.7%
     * [-83.8%, -5.4%]). Matching on both windows (earlier AND before) makes the controls equally extreme
     * in the before window, so they fall back alike.
     */
    public function testDefectExtremeSelectedSubjectsRegressingToTheirMeanAreNotAnEffect(): void
    {
        $trials = 40;
        $wrong = 0;
        $verdicts = [];
        for ($trial = 0; $trial < $trials; $trial++) {
            $prng = new \Panth\AiVisibility\Service\Simulator\Prng($trial, 'e1-extreme');
            $data = [];
            $before = [];
            for ($j = 0; $j < 400; $j++) {
                $lambda = 1.0 + 7.0 * $j / 399;
                $ref = '/retired-' . $j;
                // [before, after, earlier window]: independent draws at the same rate.
                $data[$ref] = [
                    self::poisson($prng, $lambda),
                    self::poisson($prng, $lambda),
                    self::poisson($prng, $lambda),
                ];
                $before[$ref] = $data[$ref][0];
            }
            uksort($before, static fn ($a, $b) => [$before[$b], $a] <=> [$before[$a], $b]);
            $treated = array_slice(array_keys($before), 0, 10);
            $pool = array_values(array_diff(array_keys($data), $treated));
            $row = $this->calculator(
                self::action([
                    'action_id' => $trial + 1,
                    'rule_code' => 'bot_404',
                    'metric' => MetricProviderInterface::METRIC_BOT_404,
                    'subjects' => json_encode($treated),
                    'rec_subject_type' => 'path',
                ]),
                $data,
                $pool
            )->verify($trial + 1);
            $verdicts[$row['verdict']] = ($verdicts[$row['verdict']] ?? 0) + 1;
            $wrong += in_array(
                $row['verdict'],
                [ImpactCalculatorInterface::VERDICT_HELPED, ImpactCalculatorInterface::VERDICT_WORSE],
                true
            ) ? 1 : 0;
        }
        $this->assertArrayNotHasKey('insufficient', $verdicts, 'every trial gets a verdict');
        $this->assertLessThanOrEqual(
            (int)ceil(0.10 * $trials),
            $wrong,
            sprintf('null world with extreme-selected treated judged an effect in %d of %d trials', $wrong, $trials)
        );
    }

    /**
     * Poisson draw (Knuth) from the simulator PRNG
     *
     * @param \Panth\AiVisibility\Service\Simulator\Prng $prng
     * @param float $lambda
     * @return int
     */
    private static function poisson(\Panth\AiVisibility\Service\Simulator\Prng $prng, float $lambda): int
    {
        $limit = exp(-$lambda);
        $k = 0;
        $product = 1.0;
        while (true) {
            $product *= ($prng->int(0, 999999) + 0.5) / 1000000.0;
            if ($product <= $limit) {
                return $k;
            }
            $k++;
        }
    }

    /**
     * Action applied on 2026-03-01 with a 14-day window
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
            'rec_subject_type' => '',
        ];
    }

    /**
     * Treated products 1-4 lifted by $lift, controls 10-15 flat: ref => [before, after]
     *
     * @param float $lift
     * @return array
     */
    private static function liftData(float $lift): array
    {
        $data = [];
        foreach ([1 => 40, 2 => 55, 3 => 60, 4 => 45] as $id => $before) {
            $data['product:' . $id] = [$before, round($before * $lift)];
        }
        foreach ([10 => 42, 11 => 50, 12 => 58, 13 => 47, 14 => 61, 15 => 39] as $id => $before) {
            $data['product:' . $id] = [$before, $before];
        }
        return $data;
    }

    /**
     * Calculator over in-memory data. $data[ref] = [before, after] (counts) or [[num, den], [num, den]];
     * a range that starts before the applied day is "before", anything else "after"; partial after windows
     * are scaled by their length.
     *
     * @param array|null $action
     * @param array $data
     * @param string[] $pool
     * @param string $now
     * @param array $config
     * @param PoissonRateTest|null $poisson
     * @return ImpactCalculator
     */
    private function calculator(
        ?array $action,
        array $data,
        array $pool = [],
        string $now = '2026-03-16 04:40:00',
        array $config = [],
        ?PoissonRateTest $poisson = null
    ): ImpactCalculator {
        $appliedDay = substr((string)($action['applied_at'] ?? '2026-03-01'), 0, 10);
        $store = $this->createStub(ImpactStore::class);
        $store->method('getAction')->willReturn($action);
        $store->method('dueActionIds')->willReturn([]);
        $store->method('saveImpact')->willReturnCallback(function (array $row): void {
            $this->saved[] = $row;
        });
        $store->method('setActionStatus')->willReturnCallback(function (int $id, string $status): void {
            $this->statuses[] = [$id, $status];
        });
        $metrics = $this->createStub(MetricProviderInterface::class);
        $metrics->method('supports')->willReturnCallback(
            static fn (string $m): bool => in_array($m, MetricProviderInterface::METRICS, true)
        );
        $metrics->method('value')->willReturnCallback(
            static function (string $metric, int $store, array $subjects, string $from, string $to) use ($data, $appliedDay) {
                $ratio = in_array($metric, MetricProviderInterface::RATIO_METRICS, true);
                $isBefore = $from < $appliedDay;
                // A range ending before the last before day is the earlier (control matching) window: it
                // reads the optional third value, else the before value.
                $isEarlier = $isBefore && $to < gmdate('Y-m-d', (int)strtotime($appliedDay . ' UTC') - 86400);
                $days = (int)round((strtotime($to . ' UTC') - strtotime($from . ' UTC')) / 86400) + 1;
                $out = [];
                foreach ($subjects as $ref) {
                    $pair = $data[$ref][$isEarlier && isset($data[$ref][2]) ? 2 : ($isBefore ? 0 : 1)] ?? null;
                    if ($pair === null) {
                        $out[$ref] = ['num' => 0.0, 'den' => $ratio ? 0.0 : 1.0];
                        continue;
                    }
                    $pair = is_array($pair) ? $pair : [$pair, 1.0];
                    $scale = $isBefore ? 1.0 : min(1.0, $days / 14);
                    $out[$ref] = ['num' => (float)$pair[0] * $scale, 'den' => $ratio ? (float)$pair[1] * $scale : 1.0];
                }
                return $out;
            }
        );
        $subjectPool = $this->createStub(SubjectPool::class);
        $subjectPool->method('candidates')->willReturn($pool);
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
            $poisson ?? new PoissonRateTest(),
            new ProportionDifference(),
            new WelchTTest(),
            $cfg,
            $clock,
            new Json(),
            $this->createStub(LoggerInterface::class)
        );
    }
}
