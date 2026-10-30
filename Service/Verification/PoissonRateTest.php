<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Verification;

/**
 * Rate ratio of two Poisson counts with a 95% interval (single-subject "before / after" verification).
 *
 * ratio = (after / afterExposure) / (before / beforeExposure). Conditional on the total n = after + before,
 * after is Binomial(n, p) with p / (1 - p) = ratio * afterExposure / beforeExposure, so the exact interval is
 * the Clopper-Pearson interval of p mapped back to the ratio scale. It is used up to EXACT_LIMIT events (and
 * always when after = 0); above that the normal approximation on the log scale is used:
 *     exp(ln(ratio) +- 1.96 * sqrt(1 / after + 1 / before))
 * Exposure is the number of days for counts, or the denominator (e.g. visits) for ratio metrics.
 */
class PoissonRateTest
{
    public const METHOD_EXACT = 'exact';
    public const METHOD_NORMAL = 'normal';

    public const EXACT_LIMIT = 5000;

    /**
     * Two-sided 95% standard normal quantile
     */
    public const Z95 = 1.959963984540054;

    private const ALPHA = 0.05;
    private const BISECTION_STEPS = 100;

    /**
     * Rate ratio and its 95% interval
     *
     * @param float $after Events after (rounded to a whole count)
     * @param float $afterExposure
     * @param float $before Events before (rounded to a whole count)
     * @param float $beforeExposure
     * @return array{ratio: float, low: float, high: float, method: string}|null Null when before = 0
     *         or an exposure is not positive (the ratio is undefined)
     */
    public function rateRatio(float $after, float $afterExposure, float $before, float $beforeExposure): ?array
    {
        $a = (int)round(max(0.0, $after));
        $b = (int)round(max(0.0, $before));
        if ($b === 0 || $afterExposure <= 0.0 || $beforeExposure <= 0.0) {
            return null;
        }
        $scale = $beforeExposure / $afterExposure;
        $ratio = ($a / $b) * $scale;
        $n = $a + $b;
        if ($n <= self::EXACT_LIMIT || $a === 0) {
            [$pLow, $pHigh] = $this->clopperPearson($a, $n);
            return [
                'ratio' => $ratio,
                'low' => $pLow / (1.0 - $pLow) * $scale,
                'high' => $pHigh / (1.0 - $pHigh) * $scale,
                'method' => self::METHOD_EXACT,
            ];
        }
        $se = sqrt(1.0 / $a + 1.0 / $b);
        return [
            'ratio' => $ratio,
            'low' => $ratio * exp(-self::Z95 * $se),
            'high' => $ratio * exp(self::Z95 * $se),
            'method' => self::METHOD_NORMAL,
        ];
    }

    /**
     * Exact (Clopper-Pearson) 95% interval of a binomial proportion with k successes in n trials (k < n)
     *
     * @param int $k
     * @param int $n
     * @return float[] [low, high]
     */
    public function clopperPearson(int $k, int $n): array
    {
        $half = self::ALPHA / 2.0;
        if ($k <= 0) {
            // Closed form: (1 - p)^n = alpha / 2.
            return [0.0, 1.0 - $half ** (1.0 / $n)];
        }
        $logFact = [0.0];
        for ($i = 1; $i <= $n; $i++) {
            $logFact[$i] = $logFact[$i - 1] + log($i);
        }
        // P(X >= k | p) grows with p: find p where it equals alpha / 2.
        $low = $this->bisect(fn (float $p): float => $this->tail($logFact, $n, $k, $n, $p) - $half, true);
        $high = 1.0;
        if ($k < $n) {
            // P(X <= k | p) falls with p: find p where it equals alpha / 2.
            $high = $this->bisect(fn (float $p): float => $this->tail($logFact, $n, 0, $k, $p) - $half, false);
        }
        return [$low, $high];
    }

    /**
     * Root of a monotonic function on (0, 1) by bisection
     *
     * @param callable $f
     * @param bool $increasing
     * @return float
     */
    private function bisect(callable $f, bool $increasing): float
    {
        $lo = 0.0;
        $hi = 1.0;
        for ($i = 0; $i < self::BISECTION_STEPS; $i++) {
            $mid = ($lo + $hi) / 2.0;
            $above = $f($mid) > 0.0;
            if ($above === $increasing) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }
        return ($lo + $hi) / 2.0;
    }

    /**
     * P(from <= X <= to) for X ~ Binomial(n, p), summed in log space
     *
     * @param float[] $logFact ln(i!) for i = 0..n
     * @param int $n
     * @param int $from
     * @param int $to
     * @param float $p 0 < p < 1
     * @return float
     */
    private function tail(array $logFact, int $n, int $from, int $to, float $p): float
    {
        $logP = log($p);
        $logQ = log1p(-$p);
        $terms = [];
        $max = -INF;
        for ($j = $from; $j <= $to; $j++) {
            $term = $logFact[$n] - $logFact[$j] - $logFact[$n - $j] + $j * $logP + ($n - $j) * $logQ;
            $terms[] = $term;
            if ($term > $max) {
                $max = $term;
            }
        }
        if ($max === -INF) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($terms as $term) {
            $sum += exp($term - $max);
        }
        return min(1.0, exp($max) * $sum);
    }
}
