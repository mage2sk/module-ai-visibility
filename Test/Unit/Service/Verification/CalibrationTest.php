<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Verification;

use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Panth\AiVisibility\Service\Simulator\Prng;
use Panth\AiVisibility\Service\Verification\Bootstrap;
use Panth\AiVisibility\Service\Verification\ControlMatcher;
use Panth\AiVisibility\Service\Verification\ImpactCalculator;
use Panth\AiVisibility\Service\Verification\PoissonRateTest;
use PHPUnit\Framework\TestCase;

/**
 * Calibration of the verdicts on seeded in-memory simulations (200 trials each).
 *
 * Multi-subject: 8 treated + 30 untreated subjects with different base volumes (10-60 events per window),
 * a random site-wide trend (x0.80-1.25) on every subject and per-subject noise in the change (x0.85-1.15),
 * Poisson counts. With no true effect at most ~10% of verdicts may claim "helped" or "worse"; with a
 * +50% true effect at least 80% must be "helped". Single-subject: Poisson counts of about 150 events per
 * window, same thresholds. Set AIV_CALIBRATION_REPORT=1 to print the rates.
 */
class CalibrationTest extends TestCase
{
    private const TRIALS = 200;
    private const MIN_EVENTS = 30;

    public function testMultiSubjectNoEffectRarelyClaimsAChange(): void
    {
        $rates = self::didRates(1.0);
        self::report('DiD, no effect', $rates);

        $this->assertLessThanOrEqual(0.10, $rates['helped'] + $rates['worse']);
    }

    public function testMultiSubjectPlusFiftyPercentIsDetected(): void
    {
        $rates = self::didRates(1.5);
        self::report('DiD, +50%', $rates);

        $this->assertGreaterThanOrEqual(0.80, $rates['helped']);
        $this->assertSame(0.0, $rates['worse']);
    }

    public function testSiteNoEffectRarelyClaimsAChange(): void
    {
        $rates = self::siteRates(1.0, 0.0);
        self::report('Poisson site, no effect', $rates);

        $this->assertLessThanOrEqual(0.10, $rates['helped'] + $rates['worse']);
    }

    public function testSitePlusFiftyPercentIsDetected(): void
    {
        $rates = self::siteRates(1.5, 0.0);
        self::report('Poisson site, +50%', $rates);

        $this->assertGreaterThanOrEqual(0.80, $rates['helped']);
    }

    public function testSiteWithDayToDayVariationIsReportedForReference(): void
    {
        // Daily volume varies +-40% around its mean: more spread than Poisson alone. Not asserted
        // beyond sanity; printed so the limit of the Poisson interval stays visible.
        $rates = self::siteRates(1.0, 0.4);
        self::report('Poisson site, no effect, day-to-day +-40%', $rates);

        $this->assertLessThan(0.5, $rates['helped'] + $rates['worse']);
    }

    /**
     * Verdict shares over the DiD trials
     *
     * @param float $trueEffect
     * @return array<string, float>
     */
    private static function didRates(float $trueEffect): array
    {
        $bootstrap = new Bootstrap();
        $matcher = new ControlMatcher();
        $counts = array_fill_keys(ImpactCalculatorInterface::VERDICTS, 0);
        for ($trial = 1; $trial <= self::TRIALS; $trial++) {
            $prng = new Prng($trial, 'calibration|did|' . $trueEffect);
            $trend = 0.80 + 0.45 * self::uniform($prng);
            $before = [];
            $after = [];
            for ($s = 0; $s < 38; $s++) {
                $ref = 's' . $s;
                $base = 10 + $prng->int(0, 50);
                $noise = 0.85 + 0.30 * self::uniform($prng);
                $lift = $s < 8 ? $trueEffect : 1.0;
                $before[$ref] = ['num' => (float)self::poisson($prng, $base), 'den' => 1.0];
                $after[$ref] = ['num' => (float)self::poisson($prng, $base * $trend * $noise * $lift), 'den' => 1.0];
            }
            $treatedBefore = array_slice($before, 0, 8, true);
            $controls = $matcher->match($treatedBefore, array_slice($before, 8, null, true), 50, false);
            $treated = [];
            $events = 0.0;
            foreach (array_keys($treatedBefore) as $ref) {
                $treated[] = ['bn' => $before[$ref]['num'], 'bd' => 1.0, 'an' => $after[$ref]['num'], 'ad' => 1.0];
                $events += $before[$ref]['num'] + $after[$ref]['num'];
            }
            $control = [];
            foreach ($controls as $ref) {
                $control[] = ['bn' => $before[$ref]['num'], 'bd' => 1.0, 'an' => $after[$ref]['num'], 'ad' => 1.0];
            }
            $verdict = ImpactCalculatorInterface::VERDICT_INSUFFICIENT;
            $interval = $events >= self::MIN_EVENTS && count($control) >= 3
                ? $bootstrap->interval($treated, $control, $trial, ImpactCalculatorInterface::BOOTSTRAP_RESAMPLES)
                : null;
            if ($interval !== null) {
                $verdict = ImpactCalculator::verdict(false, $interval['low'], $interval['high']);
            }
            $counts[$verdict]++;
        }
        return array_map(static fn (int $n): float => $n / self::TRIALS, $counts);
    }

    /**
     * Verdict shares over the single-subject trials
     *
     * @param float $trueEffect
     * @param float $dailySpread Day-to-day variation of the daily mean (0 = pure Poisson)
     * @return array<string, float>
     */
    private static function siteRates(float $trueEffect, float $dailySpread): array
    {
        $test = new PoissonRateTest();
        $counts = array_fill_keys(ImpactCalculatorInterface::VERDICTS, 0);
        for ($trial = 1; $trial <= self::TRIALS; $trial++) {
            $prng = new Prng($trial, 'calibration|site|' . $trueEffect . '|' . $dailySpread);
            $before = 0;
            $after = 0;
            for ($day = 0; $day < 14; $day++) {
                $before += self::poisson($prng, 10.7 * (1 + $dailySpread * (2 * self::uniform($prng) - 1)));
                $after += self::poisson($prng, 10.7 * $trueEffect * (1 + $dailySpread * (2 * self::uniform($prng) - 1)));
            }
            $verdict = ImpactCalculatorInterface::VERDICT_INSUFFICIENT;
            $result = $before + $after >= self::MIN_EVENTS ? $test->rateRatio($after, 14, $before, 14) : null;
            if ($result !== null) {
                $verdict = ImpactCalculator::verdict(false, $result['low'], $result['high']);
            }
            $counts[$verdict]++;
        }
        return array_map(static fn (int $n): float => $n / self::TRIALS, $counts);
    }

    /**
     * Uniform in [0, 1)
     *
     * @param Prng $prng
     * @return float
     */
    private static function uniform(Prng $prng): float
    {
        return $prng->nextInt32() / 4294967296;
    }

    /**
     * Poisson draw (Knuth; means here stay below 100)
     *
     * @param Prng $prng
     * @param float $mean
     * @return int
     */
    private static function poisson(Prng $prng, float $mean): int
    {
        $limit = exp(-max(0.0, $mean));
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= self::uniform($prng);
        } while ($p > $limit);
        return $k - 1;
    }

    /**
     * Print rates when AIV_CALIBRATION_REPORT is set
     *
     * @param string $label
     * @param array $rates
     * @return void
     */
    private static function report(string $label, array $rates): void
    {
        if (getenv('AIV_CALIBRATION_REPORT')) {
            // phpcs:ignore Magento2.Security.LanguageConstruct
            echo sprintf(
                "\n%s: helped %.1f%%, no effect %.1f%%, worse %.1f%%, insufficient %.1f%%",
                $label,
                $rates['helped'] * 100,
                $rates['no_effect'] * 100,
                $rates['worse'] * 100,
                $rates['insufficient'] * 100
            );
        }
    }
}
