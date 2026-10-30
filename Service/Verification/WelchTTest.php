<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Verification;

/**
 * Welch's unequal-variance t-test for the difference of two means, with its confidence interval.
 *
 * Used for the site's average response time: each day is one observation (the day's mean), so the
 * interval reflects the day-to-day spread instead of treating every request as independent.
 * Student t quantiles come from the regularized incomplete beta function (continued fraction) and
 * bisection, so non-integer Welch-Satterthwaite degrees of freedom are handled exactly.
 */
class WelchTTest
{
    private const BISECTION_STEPS = 200;
    private const CF_MAX_ITERATIONS = 300;
    private const CF_EPSILON = 1.0e-15;
    private const TINY = 1.0e-300;

    /**
     * Lanczos coefficients (g = 7, n = 9)
     */
    private const LANCZOS = [
        0.99999999999980993, 676.5203681218851, -1259.1392167224028, 771.32342877765313,
        -176.61502916214059, 12.507343278686905, -0.13857109526572012, 9.9843695780195716e-6,
        1.5056327351493116e-7,
    ];

    /**
     * Difference of means (a - b) with a two-sided confidence interval
     *
     * @param float[] $a
     * @param float[] $b
     * @param float $level Confidence level, e.g. 0.95
     * @return array{diff: float, low: float, high: float, t: float, df: float, se: float, mean_a: float,
     *     mean_b: float}|null Null with fewer than 2 values per group or no variation at all
     */
    public function interval(array $a, array $b, float $level = 0.95): ?array
    {
        $a = array_values(array_map('floatval', $a));
        $b = array_values(array_map('floatval', $b));
        $na = count($a);
        $nb = count($b);
        if ($na < 2 || $nb < 2 || $level <= 0.0 || $level >= 1.0) {
            return null;
        }
        [$meanA, $varA] = self::meanVariance($a);
        [$meanB, $varB] = self::meanVariance($b);
        $ua = $varA / $na;
        $ub = $varB / $nb;
        $se2 = $ua + $ub;
        if ($se2 <= 0.0) {
            return null;
        }
        $se = sqrt($se2);
        $df = $se2 * $se2 / ($ua * $ua / ($na - 1) + $ub * $ub / ($nb - 1));
        $q = $this->tQuantile(1.0 - (1.0 - $level) / 2.0, $df);
        $diff = $meanA - $meanB;
        return [
            'diff' => $diff,
            'low' => $diff - $q * $se,
            'high' => $diff + $q * $se,
            't' => $diff / $se,
            'df' => $df,
            'se' => $se,
            'mean_a' => $meanA,
            'mean_b' => $meanB,
        ];
    }

    /**
     * Student t cumulative distribution function
     *
     * @param float $t
     * @param float $df
     * @return float
     */
    public function tCdf(float $t, float $df): float
    {
        $x = $df / ($df + $t * $t);
        $tail = 0.5 * $this->betaRegularized($df / 2.0, 0.5, $x);
        return $t >= 0.0 ? 1.0 - $tail : $tail;
    }

    /**
     * Student t quantile for a probability p in (0.5, 1)
     *
     * @param float $p
     * @param float $df
     * @return float
     */
    public function tQuantile(float $p, float $df): float
    {
        if ($p <= 0.5) {
            return $p === 0.5 ? 0.0 : -$this->tQuantile(1.0 - $p, $df);
        }
        $low = 0.0;
        $high = 1.0;
        while ($this->tCdf($high, $df) < $p && $high < 1.0e12) {
            $high *= 2.0;
        }
        for ($i = 0; $i < self::BISECTION_STEPS; $i++) {
            $mid = ($low + $high) / 2.0;
            if ($this->tCdf($mid, $df) < $p) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }
        return ($low + $high) / 2.0;
    }

    /**
     * Mean and sample variance (n - 1)
     *
     * @param float[] $values
     * @return float[]
     */
    private static function meanVariance(array $values): array
    {
        $n = count($values);
        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }
        return [$mean, $sum / ($n - 1)];
    }

    /**
     * Regularized incomplete beta function I_x(a, b)
     *
     * @param float $a
     * @param float $b
     * @param float $x
     * @return float
     */
    private function betaRegularized(float $a, float $b, float $x): float
    {
        if ($x <= 0.0) {
            return 0.0;
        }
        if ($x >= 1.0) {
            return 1.0;
        }
        $front = exp(
            $this->lnGamma($a + $b) - $this->lnGamma($a) - $this->lnGamma($b) + $a * log($x) + $b * log(1.0 - $x)
        );
        if ($x < ($a + 1.0) / ($a + $b + 2.0)) {
            return $front * $this->betaFraction($a, $b, $x) / $a;
        }
        return 1.0 - $front * $this->betaFraction($b, $a, 1.0 - $x) / $b;
    }

    /**
     * Continued fraction of the incomplete beta function (modified Lentz)
     *
     * @param float $a
     * @param float $b
     * @param float $x
     * @return float
     */
    private function betaFraction(float $a, float $b, float $x): float
    {
        $qab = $a + $b;
        $qap = $a + 1.0;
        $qam = $a - 1.0;
        $c = 1.0;
        $d = 1.0 - $qab * $x / $qap;
        $d = abs($d) < self::TINY ? self::TINY : $d;
        $d = 1.0 / $d;
        $h = $d;
        for ($m = 1; $m <= self::CF_MAX_ITERATIONS; $m++) {
            $m2 = 2 * $m;
            $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
            $d = 1.0 + $aa * $d;
            $d = abs($d) < self::TINY ? self::TINY : $d;
            $c = 1.0 + $aa / $c;
            $c = abs($c) < self::TINY ? self::TINY : $c;
            $d = 1.0 / $d;
            $h *= $d * $c;
            $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
            $d = 1.0 + $aa * $d;
            $d = abs($d) < self::TINY ? self::TINY : $d;
            $c = 1.0 + $aa / $c;
            $c = abs($c) < self::TINY ? self::TINY : $c;
            $d = 1.0 / $d;
            $delta = $d * $c;
            $h *= $delta;
            if (abs($delta - 1.0) < self::CF_EPSILON) {
                break;
            }
        }
        return $h;
    }

    /**
     * Natural log of the gamma function (Lanczos approximation, x > 0)
     *
     * @param float $x
     * @return float
     */
    private function lnGamma(float $x): float
    {
        if ($x < 0.5) {
            return log(M_PI / abs(sin(M_PI * $x))) - $this->lnGamma(1.0 - $x);
        }
        $x -= 1.0;
        $sum = self::LANCZOS[0];
        for ($i = 1; $i < 9; $i++) {
            $sum += self::LANCZOS[$i] / ($x + $i);
        }
        $t = $x + 7.5;
        return 0.5 * log(2 * M_PI) + ($x + 0.5) * log($t) - $t + log($sum);
    }
}
