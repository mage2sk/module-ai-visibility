<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Verification;

/**
 * Difference of two proportions with a 95% interval: Wilson score interval per proportion and the
 * Newcombe hybrid score interval for the difference (Newcombe 1998, method 10). Both behave well for
 * small samples and for proportions at 0 or 1, where the normal (Wald) interval breaks down.
 */
class ProportionDifference
{
    public const Z95 = PoissonRateTest::Z95;

    /**
     * Wilson score interval of x successes out of n
     *
     * @param int $successes
     * @param int $trials
     * @param float $z
     * @return float[]|null [low, high], null when the counts are invalid
     */
    public function wilson(int $successes, int $trials, float $z = self::Z95): ?array
    {
        if ($trials <= 0 || $successes < 0 || $successes > $trials) {
            return null;
        }
        $p = $successes / $trials;
        $z2 = $z * $z;
        $denominator = 1.0 + $z2 / $trials;
        $centre = ($p + $z2 / (2 * $trials)) / $denominator;
        $half = $z * sqrt($p * (1.0 - $p) / $trials + $z2 / (4.0 * $trials * $trials)) / $denominator;
        return [max(0.0, $centre - $half), min(1.0, $centre + $half)];
    }

    /**
     * Difference p1 - p0 with the Newcombe hybrid score interval
     *
     * @param int $successes1
     * @param int $trials1
     * @param int $successes0
     * @param int $trials0
     * @return array{p1: float, p0: float, diff: float, low: float, high: float}|null
     */
    public function newcombe(int $successes1, int $trials1, int $successes0, int $trials0): ?array
    {
        $w1 = $this->wilson($successes1, $trials1);
        $w0 = $this->wilson($successes0, $trials0);
        if ($w1 === null || $w0 === null) {
            return null;
        }
        $p1 = $successes1 / $trials1;
        $p0 = $successes0 / $trials0;
        $diff = $p1 - $p0;
        return [
            'p1' => $p1,
            'p0' => $p0,
            'diff' => $diff,
            'low' => $diff - sqrt(($p1 - $w1[0]) ** 2 + ($w0[1] - $p0) ** 2),
            'high' => $diff + sqrt(($w1[1] - $p1) ** 2 + ($p0 - $w0[0]) ** 2),
        ];
    }
}
