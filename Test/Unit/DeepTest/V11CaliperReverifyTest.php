<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Panth\AiVisibility\Service\Verification\Bootstrap;
use Panth\AiVisibility\Service\Verification\ControlMatcher;
use Panth\AiVisibility\Service\Verification\ImpactCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Independent re-verification of the caliper method (AivTest-RV3): what the pair matching can and cannot see
 */
class V11CaliperReverifyTest extends TestCase
{
    /**
     * Treated pages that grow ~35% per window, untreated pages flat: every flat page between the treated
     * pre and before values is inside the caliper in both windows, so both identifications read the
     * ongoing growth as an effect of a fix that did nothing (parallel-trend assumption, no pre-trend check).
     */
    public function testDifferentialTrendIsReadAsAnEffectByBothIdentifications(): void
    {
        $treatedPre = $treatedBefore = $treatedAfter = [];
        for ($i = 0; $i < 6; $i++) {
            $base = 100.0 + 10 * $i;
            $treatedPre["/t$i"] = ['num' => $base, 'den' => 1.0];
            $treatedBefore["/t$i"] = ['num' => round($base * 1.35), 'den' => 1.0];
            $treatedAfter["/t$i"] = round($base * 1.35 * 1.35);
        }
        $pre = $before = $after = [];
        for ($j = 0; $j < 60; $j++) {
            $level = 95.0 + 2 * $j;                       // flat pages from 95 to 213
            $pre["/c$j"] = ['num' => $level, 'den' => 1.0];
            $before["/c$j"] = ['num' => $level + ($j % 3) - 1, 'den' => 1.0];
            $after["/c$j"] = $level + (($j + 1) % 3) - 1;
        }
        $matcher = new ControlMatcher();
        $pairs = $matcher->matchPairs([$treatedPre, $treatedBefore], [$pre, $before], 50, false, ImpactCalculator::CALIPER);
        $this->assertGreaterThanOrEqual(3, count($pairs), 'flat pages are inside the caliper in both windows');
        $t = [];
        foreach (array_keys($treatedBefore) as $ref) {
            $t[] = ['bn' => $treatedBefore[$ref]['num'], 'bd' => 1.0, 'an' => $treatedAfter[$ref], 'ad' => 1.0];
        }
        $c = [];
        foreach ($pairs as [$ref]) {
            $c[] = ['bn' => $before[$ref]['num'], 'bd' => 1.0, 'an' => $after[$ref], 'ad' => 1.0];
        }
        $boot = new Bootstrap();
        $main = $boot->interval($t, $c, 1, 1000);
        $this->assertNotNull($main);
        // The cross-check (pre baseline, before-matched partners) sees even more growth: the union cannot rescue it.
        $checkPairs = $matcher->matchPairs([$treatedBefore], [$before], 50, false, ImpactCalculator::CALIPER);
        $ct = [];
        foreach (array_keys($treatedBefore) as $ref) {
            $ct[] = ['bn' => $treatedPre[$ref]['num'], 'bd' => 1.0, 'an' => $treatedAfter[$ref], 'ad' => 1.0];
        }
        $cc = [];
        foreach ($checkPairs as [$ref]) {
            $cc[] = ['bn' => $pre[$ref]['num'], 'bd' => 1.0, 'an' => $after[$ref], 'ad' => 1.0];
        }
        $check = $boot->interval($ct, $cc, 1, 1000);
        $this->assertNotNull($check);
        $low = min($main['low'], $check['low']);
        $high = max($main['high'], $check['high']);
        $this->assertGreaterThan(1.05, $low);
        // Documents the current behaviour: a no-effect fix on growing pages is reported as "likely helped".
        $this->assertSame(ImpactCalculator::VERDICT_HELPED, ImpactCalculator::verdict(false, $low, $high));
    }

    /**
     * Recent-onset pages (nothing in the pre window) cannot pair with established pages: the caliper on
     * ln(1 + 0) leaves only partners that also had nothing then.
     */
    public function testRecentOnsetPairsOnlyWithPagesThatAlsoHadNothingBefore(): void
    {
        $treated = [[], []];
        for ($i = 0; $i < 5; $i++) {
            $treated[0]["/n$i"] = ['num' => 0.0, 'den' => 1.0];
            $treated[1]["/n$i"] = ['num' => 120.0 + $i, 'den' => 1.0];
        }
        $cands = [[], []];
        foreach ([0, 1, 2, 3] as $j) {                       // established pages, same before value
            $cands[0]["/e$j"] = ['num' => 1.0 + $j, 'den' => 1.0];
            $cands[1]["/e$j"] = ['num' => 121.0, 'den' => 1.0];
        }
        $cands[0]['/o1'] = ['num' => 0.0, 'den' => 1.0];
        $cands[1]['/o1'] = ['num' => 110.0, 'den' => 1.0];
        $pairs = (new ControlMatcher())->matchPairs($treated, $cands, 50, false, ImpactCalculator::CALIPER);
        $this->assertSame(['/o1'], array_column($pairs, 0));
    }
}
