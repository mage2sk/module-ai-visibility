<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Verification;

use Panth\AiVisibility\Service\Verification\ProportionDifference;
use PHPUnit\Framework\TestCase;

/**
 * Reference values computed independently with SciPy 1.10 (binomtest(...).proportion_ci(method='wilson'))
 * and the closed-form Newcombe hybrid score interval; (56/70, 48/80) is the worked example of
 * Newcombe (1998), method 10: 0.0524 to 0.3339.
 */
class ProportionDifferenceTest extends TestCase
{
    /**
     * @dataProvider wilsonCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('wilsonCases')]
    public function testWilsonMatchesReference(int $x, int $n, float $low, float $high): void
    {
        $interval = (new ProportionDifference())->wilson($x, $n);
        $this->assertNotNull($interval);
        $this->assertEqualsWithDelta($low, $interval[0], 1e-9);
        $this->assertEqualsWithDelta($high, $interval[1], 1e-9);
    }

    public static function wilsonCases(): array
    {
        return [
            '81 of 263' => [81, 263, 0.2552885199, 0.3662095770],
            'none of 10' => [0, 10, 0.0, 0.2775327999],
            'all of 10' => [10, 10, 0.7224672001, 1.0],
            '15 of 148' => [15, 148, 0.0623863995, 0.1604872417],
        ];
    }

    /**
     * @dataProvider newcombeCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('newcombeCases')]
    public function testNewcombeMatchesReference(array $counts, float $diff, float $low, float $high): void
    {
        $result = (new ProportionDifference())->newcombe(...$counts);
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta($diff, $result['diff'], 1e-9);
        $this->assertEqualsWithDelta($low, $result['low'], 1e-9);
        $this->assertEqualsWithDelta($high, $result['high'], 1e-9);
        $this->assertEqualsWithDelta($counts[0] / $counts[1], $result['p1'], 1e-12);
        $this->assertEqualsWithDelta($counts[2] / $counts[3], $result['p0'], 1e-12);
    }

    public static function newcombeCases(): array
    {
        return [
            'Newcombe 1998 example' => [[56, 70, 48, 80], 0.2, 0.0524314724, 0.3338726540],
            '9/10 vs 3/10' => [[9, 10, 3, 10], 0.6, 0.1705227239, 0.8090179735],
            '6/7 vs 2/7' => [[6, 7, 2, 7], 0.5714285714, 0.0582279275, 0.8062496375],
            'zero baseline' => [[5, 56, 0, 29], 0.0892857143, -0.0381371479, 0.1925600139],
            'coverage helped' => [[12, 40, 30, 600], 0.25, 0.1290025917, 0.4050040328],
            'coverage unclear' => [[3, 40, 30, 600], 0.025, -0.0282591545, 0.1495195936],
        ];
    }

    /**
     * @dataProvider invalidCounts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCounts')]
    public function testInvalidCountsGiveNull(array $counts): void
    {
        $this->assertNull((new ProportionDifference())->newcombe(...$counts));
    }

    public static function invalidCounts(): array
    {
        return [
            'empty treated group' => [[0, 0, 1, 10]],
            'empty baseline' => [[1, 10, 0, 0]],
            'more successes than trials' => [[11, 10, 1, 10]],
            'negative' => [[-1, 10, 1, 10]],
        ];
    }
}
