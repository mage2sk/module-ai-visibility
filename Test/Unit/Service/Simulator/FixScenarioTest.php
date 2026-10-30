<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Simulator;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Service\Recommendation\Rule\NeverCrawledProducts;
use Panth\AiVisibility\Service\Simulator\Scenario;
use Panth\AiVisibility\Service\Simulator\TrafficGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Planted-fix scenarios (--scenario=fix-helped|fix-no-effect): determinism and planted effect sizes.
 */
class FixScenarioTest extends TestCase
{
    private const APPLIED_DAY = '2026-03-08';
    private const WINDOW = 7;
    private const FIRST_DAY = '2026-03-01';
    private const DAYS = 15;

    /**
     * @return array<string, string[]>
     */
    private static function paths(): array
    {
        $paths = ['product' => [], 'category' => ['/category-1.html', '/category-2.html'], 'cms' => ['/about-us']];
        for ($i = 1; $i <= 40; $i++) {
            $paths['product'][] = sprintf('/product-%02d.html', $i);
        }
        return $paths;
    }

    /**
     * Treated paths: every other one of the 20 most popular products (as the simulator picks them)
     *
     * @return string[]
     */
    private static function treatedPaths(): array
    {
        $treated = [];
        for ($i = 1; $i <= 20; $i += 2) {
            $treated[] = sprintf('/product-%02d.html', $i);
        }
        return $treated;
    }

    /**
     * Generate all days; returns hits per day and summed stats
     *
     * @param string $scenario
     * @param int $seed
     * @param bool $withTreatment
     * @return array{days: array<string, array>, stats: array<string, int>}
     */
    private function simulateDays(string $scenario, int $seed = 7, bool $withTreatment = true): array
    {
        $start = TrafficGenerator::dayStart(self::FIRST_DAY);
        $end = $start + self::DAYS * 86400 - 1;
        $generator = new TrafficGenerator(
            Scenario::standard()->withFix($scenario, self::WINDOW),
            $seed,
            1,
            self::paths(),
            $end,
            $withTreatment ? ['paths' => self::treatedPaths(), 'applied_day' => self::APPLIED_DAY] : []
        );
        $days = [];
        $stats = [];
        for ($index = 0; $index < self::DAYS; $index++) {
            $day = gmdate('Y-m-d', $start + $index * 86400);
            $result = $generator->generateDay($day, $index, self::DAYS);
            $days[$day] = $result['hits'];
            foreach ($result['stats'] as $key => $value) {
                $stats[$key] = ($stats[$key] ?? 0) + $value;
            }
        }
        return ['days' => $days, 'stats' => $stats];
    }

    /**
     * Product hits [treated, untreated] in the before and after windows, counted from the rows
     *
     * @param array<string, array> $days
     * @return array{before: int[], after: int[]}
     */
    private static function windows(array $days): array
    {
        $treated = array_flip(self::treatedPaths());
        $products = array_flip(self::paths()['product']);
        $applied = TrafficGenerator::dayStart(self::APPLIED_DAY);
        $result = ['before' => [0, 0], 'after' => [0, 0]];
        foreach ($days as $day => $hits) {
            $offset = intdiv(TrafficGenerator::dayStart($day) - $applied, 86400);
            $window = $offset >= -self::WINDOW && $offset <= -1 ? 'before'
                : ($offset >= 1 && $offset <= self::WINDOW ? 'after' : null);
            if ($window === null) {
                continue;
            }
            foreach ($hits as $hit) {
                if (isset($products[$hit['path']])) {
                    $result[$window][isset($treated[$hit['path']]) ? 0 : 1]++;
                }
            }
        }
        return $result;
    }

    public function testScenarioDataAndGrowthOff(): void
    {
        $scenario = Scenario::standard()->withFix(Scenario::FIX_HELPED, 14);
        $fix = $scenario->fix();
        $this->assertSame('fix-helped', $fix['name']);
        $this->assertSame(500, $fix['effect_permille']);
        $this->assertSame(14, $fix['window_days']);
        $this->assertSame(Scenario::FIX_TREATED, $fix['treated']);
        $this->assertSame(NeverCrawledProducts::CODE, $fix['rule_code']);
        $this->assertSame(MetricProviderInterface::METRIC_BOT_HITS, $fix['metric']);
        $this->assertSame(0, $scenario->get('growth_permille_per_day'));
        $this->assertSame(0, Scenario::standard()->withFix(Scenario::FIX_NO_EFFECT, 7)->fix()['effect_permille']);
        $this->assertNull(Scenario::standard()->fix());
        // with() keeps the planted fix (the 1.0 derivation mechanism).
        $this->assertSame($fix, $scenario->with(['hidden_permille' => 100])->fix());
    }

    public function testUnknownScenarioIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fix-helped, fix-no-effect');
        Scenario::standard()->withFix('fix-maybe', 7);
    }

    public function testSameSeedSameRowsOtherSeedOtherRows(): void
    {
        $first = $this->simulateDays(Scenario::FIX_HELPED);
        $this->assertSame(sha1(json_encode($first)), sha1(json_encode($this->simulateDays(Scenario::FIX_HELPED))));
        $this->assertNotSame(sha1(json_encode($first)), sha1(json_encode($this->simulateDays(Scenario::FIX_HELPED, 8))));
    }

    public function testFixHelpedRaisesTreatedHitsByAboutHalfWhileControlsStayFlat(): void
    {
        $run = $this->simulateDays(Scenario::FIX_HELPED);
        $windows = self::windows($run['days']);
        [$treatedBefore, $untreatedBefore] = $windows['before'];
        [$treatedAfter, $untreatedAfter] = $windows['after'];

        $this->assertGreaterThan(500, $treatedBefore);
        $treatedRatio = $treatedAfter / $treatedBefore;
        $controlRatio = $untreatedAfter / $untreatedBefore;
        $this->assertGreaterThan(1.35, $treatedRatio);
        $this->assertLessThan(1.65, $treatedRatio);
        $this->assertGreaterThan(0.9, $controlRatio);
        $this->assertLessThan(1.1, $controlRatio);
        $did = $treatedRatio / $controlRatio;
        $this->assertGreaterThan(1.35, $did);
        $this->assertLessThan(1.65, $did);

        // The generator's own statistics match the rows.
        $this->assertSame($treatedBefore, $run['stats']['fix_treated_before']);
        $this->assertSame($treatedAfter, $run['stats']['fix_treated_after']);
        $this->assertSame($untreatedBefore, $run['stats']['fix_control_before']);
        $this->assertSame($untreatedAfter, $run['stats']['fix_control_after']);
    }

    public function testFixNoEffectKeepsTreatedHitsFlat(): void
    {
        $run = $this->simulateDays(Scenario::FIX_NO_EFFECT);
        $windows = self::windows($run['days']);
        $treatedRatio = $windows['after'][0] / $windows['before'][0];
        $controlRatio = $windows['after'][1] / $windows['before'][1];
        $this->assertSame(0, $run['stats']['fix_extra_hits']);
        $this->assertGreaterThan(0.88, $treatedRatio / $controlRatio);
        $this->assertLessThan(1.12, $treatedRatio / $controlRatio);
    }

    public function testExtraHitsAreOnlyOnTreatedPathsAfterTheAppliedDay(): void
    {
        $helped = $this->simulateDays(Scenario::FIX_HELPED);
        $none = $this->simulateDays(Scenario::FIX_NO_EFFECT);
        $treated = array_flip(self::treatedPaths());
        $extra = 0;
        foreach ($helped['days'] as $day => $hits) {
            $base = $none['days'][$day];
            if ($day <= self::APPLIED_DAY) {
                // Up to and including the applied day both scenarios write exactly the same rows.
                $this->assertSame($base, $hits, $day);
                continue;
            }
            $untreated = static fn(array $rows): array => array_values(
                array_filter($rows, static fn(array $row): bool => !isset($treated[$row['path']]))
            );
            $this->assertSame($untreated($base), $untreated($hits), $day);
            $extra += count($hits) - count($base);
            foreach ($hits as $hit) {
                $this->assertSame(1, $hit['is_simulated']);
                $this->assertSame('sim', $hit['origin']);
            }
        }
        $this->assertSame($helped['stats']['fix_extra_hits'], $extra);
        $this->assertGreaterThan(0, $extra);
    }

    public function testWithoutTreatmentNoFixStatisticsAreAdded(): void
    {
        $run = $this->simulateDays(Scenario::FIX_HELPED, 7, false);
        $this->assertArrayNotHasKey('fix_extra_hits', $run['stats']);
    }
}
