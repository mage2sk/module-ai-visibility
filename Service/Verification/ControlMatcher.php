<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Verification;

/**
 * Picks control subjects for a multi-subject action: the untreated subjects whose values are closest to
 * the treated subjects' values in one or more windows.
 *
 * The verification matches on two windows, the window before the before window ("pre") AND the before
 * window itself (matchOnWindows). The rules pick their subjects by how extreme they were just before the
 * change (top 4xx / slow paths of the last 7 days), so treated subjects tend to fall back to their usual
 * level afterwards; a control that was just as high in the before window AND just as high in the window
 * before that falls back alike, so the fall-back cancels out in the difference in differences. Matching
 * on one window only fails either way: on the before window, lucky controls of randomly chosen subjects
 * regress and inflate the effect; on the earlier window, extreme-selected treated subjects regress while
 * their controls do not.
 *
 * The caller passes candidates of the same subject type and store view; treated subjects are always
 * excluded and so are candidates without any event in all matched windows (they carry no information).
 * Distance is the sum over the windows of the log-scale distance, so a path with 10 hits is close to one
 * with 12, not to one with 1,000:
 *     Σ_windows |ln(1 + num_c) - ln(1 + num_t)| + |ln(1 + den_c) - ln(1 + den_t)|
 * (the den term is 0 for counts, where den = 1). Treated subjects take turns (largest volume over the
 * windows first) and each takes its nearest unused candidate (matching without replacement), so the
 * controls follow the spread of the treated group instead of clustering around one value. Ties break on
 * the subject ref, so the result is deterministic.
 * With a caliper (matchPairs) a candidate qualifies only when every axis is within the caliper of the
 * treated subject; a treated subject without such a candidate gets no further controls.
 */
class ControlMatcher
{
    /**
     * Matched control refs (at most $max), in pick order, matched on one window
     *
     * @param array<string, array{num: float, den: float}> $treatedBefore Treated subject => window value
     * @param array<string, array{num: float, den: float}> $candidatesBefore Candidate subject => window value
     * @param int $max
     * @param bool $ratio Ratio metric (events = den) instead of a count (events = num)
     * @return string[]
     */
    public function match(array $treatedBefore, array $candidatesBefore, int $max, bool $ratio): array
    {
        return $this->matchOnWindows([$treatedBefore], [$candidatesBefore], $max, $ratio);
    }

    /**
     * Matched control refs (at most $max), in pick order, matched on several windows at once
     *
     * Every treated subject must be listed in the first treated window; a subject missing from another
     * window (treated or candidate) has nothing in it.
     *
     * @param array[] $treatedWindows List of [subject => [num, den]], one per window
     * @param array[] $candidateWindows List of [subject => [num, den]], same window order
     * @param int $max
     * @param bool $ratio
     * @return string[]
     */
    public function matchOnWindows(array $treatedWindows, array $candidateWindows, int $max, bool $ratio): array
    {
        return array_column($this->matchPairs($treatedWindows, $candidateWindows, $max, $ratio), 0);
    }

    /**
     * Matched [control ref, treated ref] pairs (at most $max), in pick order, matched on several windows
     *
     * @param array[] $treatedWindows List of [subject => [num, den]], one per window
     * @param array[] $candidateWindows List of [subject => [num, den]], same window order
     * @param int $max
     * @param bool $ratio
     * @param float $caliper Largest gap on any axis (ln(1 + value) per window); INF = no caliper
     * @return array<int, array{0: string, 1: string}>
     */
    public function matchPairs(
        array $treatedWindows,
        array $candidateWindows,
        int $max,
        bool $ratio,
        float $caliper = INF
    ): array {
        $treatedWindows = array_values($treatedWindows);
        $candidateWindows = array_values($candidateWindows);
        if ($max < 1 || $treatedWindows === [] || ($treatedWindows[0] ?? []) === []) {
            return [];
        }
        $windows = count($treatedWindows);
        $treatedRefs = [];
        foreach ($treatedWindows[0] as $ref => $unused) {
            $treatedRefs[(string)$ref] = true;
        }
        $candidateRefs = [];
        foreach ($candidateWindows as $values) {
            foreach (array_keys($values) as $ref) {
                $ref = (string)$ref;
                if (!isset($treatedRefs[$ref])) {
                    $candidateRefs[$ref] = true;
                }
            }
        }

        $pool = [];
        foreach (array_keys($candidateRefs) as $ref) {
            [$point, $volume] = self::point($candidateWindows, $windows, (string)$ref, $ratio);
            if ($volume > 0.0) {
                $pool[(string)$ref] = $point;
            }
        }
        if ($pool === []) {
            return [];
        }
        ksort($pool, SORT_STRING);

        $treated = [];
        foreach (array_keys($treatedRefs) as $ref) {
            [$point, $volume] = self::point($treatedWindows, $windows, (string)$ref, $ratio);
            $treated[] = ['ref' => (string)$ref, 'volume' => $volume, 'point' => $point];
        }
        usort($treated, static function (array $a, array $b): int {
            return [$b['volume'], $a['ref']] <=> [$a['volume'], $b['ref']];
        });

        $chosen = [];
        $open = array_fill_keys(array_keys($treated), true);
        while (count($chosen) < $max && $pool !== [] && $open !== []) {
            foreach ($treated as $index => $subject) {
                if (count($chosen) >= $max || $pool === []) {
                    break;
                }
                if (!isset($open[$index])) {
                    continue;
                }
                $best = null;
                $bestDistance = INF;
                foreach ($pool as $ref => $point) {
                    $distance = 0.0;
                    foreach ($point as $axis => $coordinate) {
                        $gap = abs($coordinate - $subject['point'][$axis]);
                        if ($gap > $caliper) {
                            continue 2;
                        }
                        $distance += $gap;
                    }
                    if ($distance < $bestDistance) {
                        $bestDistance = $distance;
                        $best = (string)$ref;
                    }
                }
                if ($best === null) {
                    // No unused candidate within the caliper: this treated subject gets no more controls.
                    unset($open[$index]);
                    continue;
                }
                $chosen[] = [$best, $subject['ref']];
                unset($pool[$best]);
            }
        }
        return $chosen;
    }

    /**
     * Log-scale point of a subject over the windows and its event volume
     *
     * @param array[] $windows
     * @param int $count
     * @param string $ref
     * @param bool $ratio
     * @return array{0: float[], 1: float}
     */
    private static function point(array $windows, int $count, string $ref, bool $ratio): array
    {
        $point = [];
        $volume = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $value = $windows[$i][$ref] ?? null;
            $num = max(0.0, (float)($value['num'] ?? 0));
            $den = max(0.0, (float)($value['den'] ?? 0));
            $point[] = log1p($num);
            $point[] = $ratio ? log1p($den) : 0.0;
            $volume += $ratio ? $den : $num;
        }
        return [$point, $volume];
    }
}
