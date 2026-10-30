<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Verification;

use Panth\AiVisibility\Service\Verification\WelchTTest;
use PHPUnit\Framework\TestCase;

/**
 * Reference values computed independently with SciPy 1.10: scipy.stats.t.ppf / t.cdf and
 * scipy.stats.ttest_ind(equal_var=False) with the Welch-Satterthwaite degrees of freedom.
 */
class WelchTTestTest extends TestCase
{
    private const AFTER = [1210.0, 1350.0, 1190.0, 1420.0, 1305.0, 1288.0];
    private const BEFORE = [1500.0, 1610.0, 1475.0, 1580.0, 1702.0, 1555.0, 1530.0];

    /**
     * @dataProvider quantiles
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('quantiles')]
    public function testQuantileMatchesReference(float $p, float $df, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, (new WelchTTest())->tQuantile($p, $df), 1e-8);
    }

    public static function quantiles(): array
    {
        return [
            'df 1' => [0.975, 1.0, 12.7062047364],
            'df 2.5' => [0.975, 2.5, 3.5746548420],
            'df 10' => [0.975, 10.0, 2.2281388520],
            'df 30.7' => [0.975, 30.7, 2.0403215378],
            'one-sided 95%, df 4' => [0.95, 4.0, 2.1318467813],
            '99.5%, df 7' => [0.995, 7.0, 3.4994832974],
        ];
    }

    /**
     * @dataProvider cdfCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cdfCases')]
    public function testCdfMatchesReference(float $t, float $df, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, (new WelchTTest())->tCdf($t, $df), 1e-10);
    }

    public static function cdfCases(): array
    {
        return [
            'centre' => [0.0, 5.0, 0.5],
            'positive' => [1.5, 3.0, 0.884708067378],
            'negative, fractional df' => [-2.2, 8.4, 0.028712267338],
            'Cauchy' => [4.0, 1.0, 0.922020869623],
        ];
    }

    public function testIntervalOnRawValues(): void
    {
        $result = (new WelchTTest())->interval(self::AFTER, self::BEFORE);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(-270.7380952381, $result['diff'], 1e-8);
        $this->assertEqualsWithDelta(45.3885030944, $result['se'], 1e-8);
        $this->assertEqualsWithDelta(10.1424381700, $result['df'], 1e-8);
        $this->assertEqualsWithDelta(-5.9649046957, $result['t'], 1e-8);
        $this->assertEqualsWithDelta(-371.6777965996, $result['low'], 1e-6);
        $this->assertEqualsWithDelta(-169.7983938766, $result['high'], 1e-6);
    }

    public function testIntervalOnLogValuesGivesARatioOfGeometricMeans(): void
    {
        $result = (new WelchTTest())->interval(array_map('log', self::AFTER), array_map('log', self::BEFORE));

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(8.9754339667, $result['df'], 1e-8);
        $this->assertEqualsWithDelta(0.8262589761, exp($result['diff']), 1e-9);
        $this->assertEqualsWithDelta(0.7675052764, exp($result['low']), 1e-8);
        $this->assertEqualsWithDelta(0.8895103612, exp($result['high']), 1e-8);
    }

    /**
     * @dataProvider degenerate
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('degenerate')]
    public function testDegenerateInputGivesNull(array $a, array $b): void
    {
        $this->assertNull((new WelchTTest())->interval($a, $b));
    }

    public static function degenerate(): array
    {
        return [
            'one value after' => [[1.0], [1.0, 2.0]],
            'one value before' => [[1.0, 2.0], [3.0]],
            'no variation at all' => [[5.0, 5.0, 5.0], [7.0, 7.0]],
        ];
    }
}
