<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Verification;

use Panth\AiVisibility\Service\Verification\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Expected values were computed independently (hand arithmetic for the ratios, and a separate
 * standard-library Python implementation of the generator, resampling and type 7 quantile for the
 * intervals) and pinned here.
 */
class BootstrapTest extends TestCase
{
    /**
     * Count rows from [before, after] pairs
     *
     * @param array $pairs
     * @return array
     */
    private static function counts(array $pairs): array
    {
        return array_map(static fn (array $p): array => ['bn' => $p[0], 'bd' => 1.0, 'an' => $p[1], 'ad' => 1.0], $pairs);
    }

    /**
     * Ratio rows from [bn, bd, an, ad]
     *
     * @param array $rows
     * @return array
     */
    private static function ratios(array $rows): array
    {
        return array_map(static fn (array $r): array => ['bn' => $r[0], 'bd' => $r[1], 'an' => $r[2], 'ad' => $r[3]], $rows);
    }

    private static function treatedA(): array
    {
        return self::counts([[20, 30], [15, 25], [40, 52], [8, 12], [30, 41]]);
    }

    private static function controlA(): array
    {
        return self::counts([[25, 24], [18, 20], [35, 37], [10, 9], [28, 30], [22, 23], [40, 41], [12, 13]]);
    }

    public function testDidRatioMatchesHandComputation(): void
    {
        // Treated 113 -> 160, control 190 -> 197: (160 / 113) / (197 / 190) = 1.365616998...
        $ratio = (new Bootstrap())->ratio(self::treatedA(), self::controlA());

        $this->assertEqualsWithDelta((160 / 113) / (197 / 190), $ratio, 1e-12);
        $this->assertEqualsWithDelta(1.3656169983379005, $ratio, 1e-12);
    }

    public function testRatioMetricUsesPooledNumeratorOverDenominator(): void
    {
        $treated = self::ratios([[2, 100, 5, 110], [1, 80, 4, 90], [3, 120, 6, 115]]);
        $control = self::ratios([[2, 90, 2, 95], [1, 70, 1, 75], [3, 130, 3, 120], [2, 100, 3, 105]]);

        // T: 6/300 -> 15/315, C: 8/390 -> 9/395.
        $expected = ((15 / 315) / (6 / 300)) / ((9 / 395) / (8 / 390));
        $this->assertEqualsWithDelta($expected, (new Bootstrap())->ratio($treated, $control), 1e-12);
        $this->assertEqualsWithDelta(2.14353547686881, $expected, 1e-12);
    }

    public function testIntervalMatchesIndependentReference(): void
    {
        $interval = (new Bootstrap())->interval(self::treatedA(), self::controlA(), 17, 1000);

        $this->assertNotNull($interval);
        $this->assertEqualsWithDelta(1.2725368894500115, $interval['low'], 1e-9);
        $this->assertEqualsWithDelta(1.539356241676743, $interval['high'], 1e-9);
        $this->assertSame(1000, $interval['valid']);
    }

    public function testRatioMetricIntervalMatchesIndependentReference(): void
    {
        $treated = self::ratios([[2, 100, 5, 110], [1, 80, 4, 90], [3, 120, 6, 115]]);
        $control = self::ratios([[2, 90, 2, 95], [1, 70, 1, 75], [3, 130, 3, 120], [2, 100, 3, 105]]);

        $interval = (new Bootstrap())->interval($treated, $control, 99, 1000);

        $this->assertEqualsWithDelta(1.7276726955110109, $interval['low'], 1e-9);
        $this->assertEqualsWithDelta(3.1219211822660093, $interval['high'], 1e-9);
    }

    public function testSameSeedSameIntervalOtherSeedDiffers(): void
    {
        $bootstrap = new Bootstrap();
        $a = $bootstrap->interval(self::treatedA(), self::controlA(), 17);
        $b = $bootstrap->interval(self::treatedA(), self::controlA(), 17);
        $c = $bootstrap->interval(self::treatedA(), self::controlA(), 18);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        // The point estimate sits inside the interval for both seeds.
        $this->assertLessThan(1.3656169983379005, $c['low']);
        $this->assertGreaterThan(1.3656169983379005, $c['high']);
    }

    public function testSingleSubjectGroupsGiveADegenerateInterval(): void
    {
        $interval = (new Bootstrap())->interval(self::counts([[10, 15]]), self::counts([[20, 20]]), 5);

        $this->assertSame(1.5, $interval['low']);
        $this->assertSame(1.5, $interval['high']);
    }

    public function testZeroBeforeCountsGiveNoRatio(): void
    {
        $bootstrap = new Bootstrap();
        $treated = self::counts([[0, 5], [0, 3], [0, 1]]);

        $this->assertNull($bootstrap->ratio($treated, self::controlA()));
        $this->assertNull($bootstrap->interval($treated, self::controlA(), 1));
    }

    public function testAllZeroControlsGiveNoRatio(): void
    {
        $bootstrap = new Bootstrap();
        $control = self::counts([[0, 0], [0, 0], [0, 0]]);

        $this->assertNull($bootstrap->ratio(self::treatedA(), $control));
        $this->assertNull($bootstrap->interval(self::treatedA(), $control, 1));
    }

    public function testControlsWithZeroAfterGiveNoRatio(): void
    {
        $this->assertNull((new Bootstrap())->ratio(self::treatedA(), self::counts([[5, 0], [7, 0], [9, 0]])));
    }

    public function testTreatedDroppingToZeroIsAValidZeroRatio(): void
    {
        $bootstrap = new Bootstrap();
        $treated = self::counts([[10, 0], [12, 0], [9, 0]]);

        $this->assertSame(0.0, $bootstrap->ratio($treated, self::controlA()));
        $interval = $bootstrap->interval($treated, self::controlA(), 4);
        $this->assertSame(0.0, $interval['low']);
        $this->assertSame(0.0, $interval['high']);
    }

    public function testMostlyZeroBeforeSubjectsMakeTheIntervalUnstable(): void
    {
        // One treated subject carries all "before" events: about a third of resamples miss it.
        $treated = self::counts([[30, 40], [0, 2], [0, 3]]);

        $this->assertNotNull((new Bootstrap())->ratio($treated, self::controlA()));
        $this->assertNull((new Bootstrap())->interval($treated, self::controlA(), 2));
    }

    public function testHugeCountsStayAccurate(): void
    {
        $treated = self::counts([[1e9, 1.5e9], [2e9, 2.2e9], [3e9, 3.1e9]]);
        $control = self::counts([[1e9, 1e9], [2e9, 2.1e9], [4e9, 4e9]]);
        $bootstrap = new Bootstrap();

        $this->assertEqualsWithDelta(1.1173708920187793, $bootstrap->ratio($treated, $control), 1e-12);
        $interval = $bootstrap->interval($treated, $control, 3);
        $this->assertEqualsWithDelta(1.0187793427230047, $interval['low'], 1e-9);
        $this->assertEqualsWithDelta(1.4788732394366197, $interval['high'], 1e-9);
    }

    public function testEmptyGroupsGiveNoInterval(): void
    {
        $this->assertNull((new Bootstrap())->interval([], self::controlA(), 1));
        $this->assertNull((new Bootstrap())->interval(self::treatedA(), [], 1));
    }

    /**
     * @dataProvider quantileProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('quantileProvider')]
    public function testQuantileIsLinearInterpolation(array $values, float $p, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, Bootstrap::quantile($values, $p), 1e-12);
    }

    /**
     * @return array
     */
    public static function quantileProvider(): array
    {
        return [
            'median of four' => [[1.0, 2.0, 3.0, 4.0], 0.5, 2.5],
            'lower 2.5% of 1..11' => [[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11], 0.025, 1.25],
            'upper 97.5% of 1..11' => [[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11], 0.975, 10.75],
            'single value' => [[7.0], 0.975, 7.0],
            'ends' => [[1.0, 5.0], 1.0, 5.0],
        ];
    }
}
