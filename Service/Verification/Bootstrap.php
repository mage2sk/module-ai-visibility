<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Verification;

use Panth\AiVisibility\Service\Simulator\Prng;

/**
 * Difference-in-differences ratio and its deterministic percentile bootstrap interval.
 *
 * A subject row is ['bn' => before num, 'bd' => before den, 'an' => after num, 'ad' => after den]; count
 * metrics use den = 1.0 per subject, so the pooled value Σnum / Σden of a group is its mean count and the
 * ratio of after / before means equals the ratio of the sums. The effect is
 *     (T_after / T_before) / (C_after / C_before)
 * with T and C the pooled treated and control values. The interval resamples treated and control subjects
 * with replacement (independently, same group sizes), using the simulator's xoshiro128** generator seeded
 * with the action ID, so the same data always gives the same interval on every PHP build.
 * Quantiles use linear interpolation between order statistics (Hyndman-Fan type 7).
 */
class Bootstrap
{
    public const STREAM = 'bootstrap';

    /**
     * Share of resamples that must give a defined ratio for the interval to be reported
     */
    public const MIN_VALID_SHARE = 0.95;

    /**
     * Pooled DiD ratio, or null when a pooled value it divides by is zero (or a denominator sum is zero)
     *
     * @param array[] $treated Subject rows
     * @param array[] $control Subject rows
     * @return float|null
     */
    public function ratio(array $treated, array $control): ?float
    {
        return self::ratioOfSums(self::sums($treated), self::sums($control));
    }

    /**
     * Percentile bootstrap interval of the DiD ratio
     *
     * @param array[] $treated Subject rows (list)
     * @param array[] $control Subject rows (list)
     * @param int $seed Action ID
     * @param int $resamples
     * @param float $level Confidence level, e.g. 0.95
     * @return array{low: float, high: float, valid: int}|null Null when either group is empty or fewer
     *         than MIN_VALID_SHARE of the resamples give a defined ratio
     */
    public function interval(
        array $treated,
        array $control,
        int $seed,
        int $resamples = 1000,
        float $level = 0.95
    ): ?array {
        $treated = array_values(array_map(static fn (array $r): array => self::row($r), $treated));
        $control = array_values(array_map(static fn (array $r): array => self::row($r), $control));
        $nT = count($treated);
        $nC = count($control);
        if ($nT === 0 || $nC === 0 || $resamples < 1) {
            return null;
        }
        $prng = new Prng($seed, self::STREAM);
        $values = [];
        for ($r = 0; $r < $resamples; $r++) {
            $t = [0.0, 0.0, 0.0, 0.0];
            for ($i = 0; $i < $nT; $i++) {
                $pick = $treated[$prng->int(0, $nT - 1)];
                $t[0] += $pick[0];
                $t[1] += $pick[1];
                $t[2] += $pick[2];
                $t[3] += $pick[3];
            }
            $c = [0.0, 0.0, 0.0, 0.0];
            for ($i = 0; $i < $nC; $i++) {
                $pick = $control[$prng->int(0, $nC - 1)];
                $c[0] += $pick[0];
                $c[1] += $pick[1];
                $c[2] += $pick[2];
                $c[3] += $pick[3];
            }
            $value = self::ratioOfSums($t, $c);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        $valid = count($values);
        if ($valid === 0 || $valid < self::MIN_VALID_SHARE * $resamples) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $alpha = (1.0 - $level) / 2.0;
        return [
            'low' => self::quantile($values, $alpha),
            'high' => self::quantile($values, 1.0 - $alpha),
            'valid' => $valid,
        ];
    }

    /**
     * Type 7 quantile of sorted values
     *
     * @param float[] $sorted Ascending, not empty
     * @param float $p 0..1
     * @return float
     */
    public static function quantile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 1) {
            return (float)$sorted[0];
        }
        $h = ($n - 1) * max(0.0, min(1.0, $p));
        $lo = (int)floor($h);
        $hi = min($n - 1, $lo + 1);
        return (float)$sorted[$lo] + ($h - $lo) * ((float)$sorted[$hi] - (float)$sorted[$lo]);
    }

    /**
     * Sums [bn, bd, an, ad] of subject rows
     *
     * @param array[] $rows
     * @return float[]
     */
    private static function sums(array $rows): array
    {
        $sum = [0.0, 0.0, 0.0, 0.0];
        foreach ($rows as $row) {
            $row = self::row($row);
            for ($k = 0; $k < 4; $k++) {
                $sum[$k] += $row[$k];
            }
        }
        return $sum;
    }

    /**
     * DiD ratio from treated and control sums
     *
     * @param float[] $t
     * @param float[] $c
     * @return float|null
     */
    private static function ratioOfSums(array $t, array $c): ?float
    {
        if ($t[1] <= 0.0 || $t[3] <= 0.0 || $c[1] <= 0.0 || $c[3] <= 0.0) {
            return null;
        }
        $treatBefore = $t[0] / $t[1];
        $controlBefore = $c[0] / $c[1];
        $controlAfter = $c[2] / $c[3];
        if ($treatBefore <= 0.0 || $controlBefore <= 0.0 || $controlAfter <= 0.0) {
            return null;
        }
        return (($t[2] / $t[3]) / $treatBefore) / ($controlAfter / $controlBefore);
    }

    /**
     * Subject row as a numeric list [bn, bd, an, ad] (negative values count as 0)
     *
     * @param array $row
     * @return float[]
     */
    private static function row(array $row): array
    {
        return [
            max(0.0, (float)($row['bn'] ?? 0)),
            max(0.0, (float)($row['bd'] ?? 0)),
            max(0.0, (float)($row['an'] ?? 0)),
            max(0.0, (float)($row['ad'] ?? 0)),
        ];
    }
}
