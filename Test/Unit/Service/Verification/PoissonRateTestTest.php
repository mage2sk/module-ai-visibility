<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Verification;

use Panth\AiVisibility\Service\Verification\PoissonRateTest;
use PHPUnit\Framework\TestCase;

/**
 * Exact intervals were computed independently in Python (binomial tails from lgamma, bisection) and
 * checked against textbook Clopper-Pearson values; normal-approximation values by hand.
 */
class PoissonRateTestTest extends TestCase
{
    public function testTextbookClopperPearsonValues(): void
    {
        $test = new PoissonRateTest();

        [$low, $high] = $test->clopperPearson(5, 10);
        $this->assertEqualsWithDelta(0.18708602844739863, $low, 1e-9);
        $this->assertEqualsWithDelta(0.8129139715526013, $high, 1e-9);

        [$low, $high] = $test->clopperPearson(0, 10);
        $this->assertSame(0.0, $low);
        $this->assertEqualsWithDelta(1 - 0.025 ** 0.1, $high, 1e-12);
        $this->assertEqualsWithDelta(0.30849710781876083, $high, 1e-9);
    }

    /**
     * @dataProvider rateProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rateProvider')]
    public function testRateRatioMatchesReference(
        float $after,
        float $afterExposure,
        float $before,
        float $beforeExposure,
        float $ratio,
        float $low,
        float $high,
        string $method
    ): void {
        $result = (new PoissonRateTest())->rateRatio($after, $afterExposure, $before, $beforeExposure);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta($ratio, $result['ratio'], 1e-12);
        $this->assertEqualsWithDelta($low, $result['low'], 1e-7);
        $this->assertEqualsWithDelta($high, $result['high'], 1e-7);
        $this->assertSame($method, $result['method']);
    }

    /**
     * @return array
     */
    public static function rateProvider(): array
    {
        return [
            '30 vs 20 over 14 days' => [30, 14, 20, 14, 1.5, 0.8241318992309099, 2.7867543069625764, 'exact'],
            'nothing after' => [0, 14, 40, 14, 0.0, 0.0, 0.09660822712436137, 'exact'],
            'unequal exposure' => [3, 7, 10, 14, 0.6, 0.10610798096952646, 2.3302372209680984, 'exact'],
            'ratio metric exposure' => [150, 3000, 100, 2500, 1.25, 0.9641234974812195, 1.626454106657008, 'exact'],
            // Normal approximation: 4/3 * exp(+-1.96 * sqrt(1/4000 + 1/3000)).
            'large counts' => [4000, 14, 3000, 14, 4 / 3, 1.2716871010753927, 1.3979679248727246, 'normal'],
        ];
    }

    public function testNormalApproximationByHand(): void
    {
        $result = (new PoissonRateTest())->rateRatio(4000, 1, 3000, 1);
        $se = sqrt(1 / 4000 + 1 / 3000);

        $this->assertEqualsWithDelta((4 / 3) * exp(-1.959963984540054 * $se), $result['low'], 1e-12);
        $this->assertEqualsWithDelta((4 / 3) * exp(1.959963984540054 * $se), $result['high'], 1e-12);
    }

    public function testExactAndNormalAgreeAtTheSwitchOver(): void
    {
        $test = new PoissonRateTest();
        $exact = $test->rateRatio(2600, 1, 2400, 1);
        $normal = $test->rateRatio(2601, 1, 2400, 1);

        $this->assertSame(PoissonRateTest::METHOD_EXACT, $exact['method']);
        $this->assertSame(PoissonRateTest::METHOD_NORMAL, $normal['method']);
        $this->assertEqualsWithDelta($exact['low'], $normal['low'], 0.002);
        $this->assertEqualsWithDelta($exact['high'], $normal['high'], 0.002);
    }

    public function testZeroBeforeOrBadExposureIsUndefined(): void
    {
        $test = new PoissonRateTest();

        $this->assertNull($test->rateRatio(10, 14, 0, 14));
        $this->assertNull($test->rateRatio(10, 0, 5, 14));
        $this->assertNull($test->rateRatio(10, 14, 5, 0));
    }

    public function testHugeCountsAndZeroAfterWithManyBeforeEvents(): void
    {
        $test = new PoissonRateTest();

        $huge = $test->rateRatio(2e9, 14, 1e9, 14);
        $this->assertEqualsWithDelta(2.0, $huge['ratio'], 1e-12);
        $this->assertLessThan(2.0, $huge['low']);
        $this->assertGreaterThan(1.999, $huge['low']);

        $none = $test->rateRatio(0, 14, 100000, 14);
        $this->assertSame(PoissonRateTest::METHOD_EXACT, $none['method']);
        $this->assertSame(0.0, $none['low']);
        $p = 1 - 0.025 ** (1 / 100000);
        $this->assertEqualsWithDelta($p / (1 - $p), $none['high'], 1e-12);
    }

    public function testIntervalAlwaysContainsTheRatio(): void
    {
        $test = new PoissonRateTest();
        foreach ([[1, 1], [1, 9], [9, 1], [17, 23], [250, 260], [3000, 2900]] as [$a, $b]) {
            $result = $test->rateRatio($a, 14, $b, 14);
            $this->assertLessThanOrEqual($result['ratio'], $result['low']);
            $this->assertGreaterThanOrEqual($result['ratio'], $result['high']);
        }
    }
}
