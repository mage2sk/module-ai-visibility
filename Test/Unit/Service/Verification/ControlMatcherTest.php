<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Verification;

use Panth\AiVisibility\Service\Verification\ControlMatcher;
use PHPUnit\Framework\TestCase;

class ControlMatcherTest extends TestCase
{
    /**
     * Count values
     *
     * @param array<string, float> $counts
     * @return array
     */
    private static function counts(array $counts): array
    {
        $out = [];
        foreach ($counts as $ref => $num) {
            $out[$ref] = ['num' => (float)$num, 'den' => 1.0];
        }
        return $out;
    }

    public function testPicksClosestBeforeValuesOnTheLogScale(): void
    {
        $treated = self::counts(['/a' => 10]);
        $pool = self::counts(['/x' => 1000, '/y' => 12, '/z' => 3, '/w' => 9]);

        $this->assertSame(['/w', '/y'], (new ControlMatcher())->match($treated, $pool, 2, false));
    }

    public function testExcludesTreatedAndZeroEventCandidates(): void
    {
        $treated = self::counts(['/a' => 10, '/b' => 20]);
        $pool = self::counts(['/a' => 10, '/b' => 20, '/c' => 0, '/d' => 11, '/e' => 19]);

        $result = (new ControlMatcher())->match($treated, $pool, 10, false);

        $this->assertSame(['/e', '/d'], $result);
    }

    public function testTreatedTakeTurnsSoControlsFollowTheirSpread(): void
    {
        $treated = self::counts(['/small' => 5, '/big' => 500]);
        $pool = self::counts(['/s1' => 5, '/s2' => 6, '/s3' => 4, '/b1' => 490, '/b2' => 520]);

        $result = (new ControlMatcher())->match($treated, $pool, 4, false);

        // Largest treated first: big picks b1, small picks s1, big picks b2, small picks s2 (6 is closer to 5
        // than 4 on the log scale: ln(7/6) < ln(6/5)).
        $this->assertSame(['/b1', '/s1', '/b2', '/s2'], $result);
    }

    public function testRespectsTheMaximumAndIsDeterministic(): void
    {
        $treated = self::counts(['/a' => 10, '/b' => 30, '/c' => 50]);
        $pool = [];
        for ($i = 1; $i <= 200; $i++) {
            $pool['/p' . $i] = ['num' => (float)$i, 'den' => 1.0];
        }
        $matcher = new ControlMatcher();

        $first = $matcher->match($treated, $pool, 50, false);
        $this->assertCount(50, $first);
        $this->assertSame($first, $matcher->match($treated, array_reverse($pool, true), 50, false));
        $this->assertCount(50, array_unique($first));
    }

    public function testRatioMetricsMatchOnVolumeAndNumerator(): void
    {
        $treated = ['/a' => ['num' => 2.0, 'den' => 100.0]];
        $pool = [
            '/tiny' => ['num' => 0.0, 'den' => 1.0],
            '/near' => ['num' => 3.0, 'den' => 110.0],
            '/huge' => ['num' => 2.0, 'den' => 10000.0],
            '/none' => ['num' => 0.0, 'den' => 0.0],
        ];

        $this->assertSame(['/near', '/huge', '/tiny'], (new ControlMatcher())->match($treated, $pool, 5, true));
    }

    public function testNumericRefsAndEmptyInputs(): void
    {
        $matcher = new ControlMatcher();
        $treated = ['product:1' => ['num' => 10.0, 'den' => 1.0]];

        $this->assertSame([], $matcher->match([], self::counts(['/x' => 1]), 5, false));
        $this->assertSame([], $matcher->match($treated, [], 5, false));
        $this->assertSame([], $matcher->match($treated, self::counts(['/x' => 1]), 0, false));
        $this->assertSame(['12'], $matcher->match(self::counts(['7' => 10]), self::counts(['12' => 9]), 5, false));
    }

    public function testMatchesOnBothWindowsTogether(): void
    {
        // Treated: 10 in the pre window, 40 in the before window (an extreme week).
        $treated = [self::counts(['/t' => 10]), self::counts(['/t' => 40])];
        $candidates = [
            self::counts(['/pre-only' => 10, '/before-only' => 40, '/both' => 11, '/t' => 10]),
            self::counts(['/pre-only' => 12, '/before-only' => 40, '/both' => 38, '/t' => 40]),
        ];
        $matcher = new ControlMatcher();

        $this->assertSame(
            ['/both', '/pre-only', '/before-only'],
            $matcher->matchOnWindows($treated, $candidates, 5, false)
        );
        // One window only would pick the subject alike in that window.
        $this->assertSame(['/before-only'], $matcher->match($treated[1], $candidates[1], 1, false));
        $this->assertSame(['/pre-only'], $matcher->match($treated[0], $candidates[0], 1, false));
    }

    public function testPairsNameTheTreatedSubjectAndMissingValuesCountAsZero(): void
    {
        $treated = [self::counts(['/big' => 100, '/small' => 2]), self::counts(['/big' => 120, '/small' => 3])];
        $candidates = [
            // '/late' has nothing in the pre window (not listed) but events in the before window.
            self::counts(['/b1' => 90, '/s1' => 2, '/gone' => 0]),
            self::counts(['/b1' => 125, '/s1' => 4, '/late' => 3, '/gone' => 0]),
        ];
        $pairs = (new ControlMatcher())->matchPairs($treated, $candidates, 10, false);

        $this->assertSame([['/b1', '/big'], ['/s1', '/small'], ['/late', '/big']], $pairs);
        $this->assertNotContains('/gone', array_column($pairs, 0), 'no events in any window');
        $this->assertSame([], (new ControlMatcher())->matchPairs([[]], $candidates, 10, false));
        $this->assertSame([], (new ControlMatcher())->matchPairs([], [], 10, false));
    }

    public function testCaliperKeepsOnlyControlsAlikeInEveryWindow(): void
    {
        // Treated started at the before window: nothing in the pre window, 400 in the before window.
        $treated = [self::counts(['/new' => 0]), self::counts(['/new' => 400])];
        $candidates = [
            self::counts(['/old-busy' => 60, '/onset' => 1, '/onset-small' => 0, '/old' => 2]),
            self::counts(['/old-busy' => 62, '/onset' => 300, '/onset-small' => 100, '/old' => 3]),
        ];
        $matcher = new ControlMatcher();

        // Without a caliper every candidate is taken, however far.
        $this->assertCount(4, $matcher->matchPairs($treated, $candidates, 10, false));
        // Caliper 0.7: only /onset is close in both windows (pre ln 2/1 = 0.69, before ln 401/301 = 0.29);
        // /onset-small is too far in the before window, the old pages in the pre window. Caliper 0.5: none.
        $pairs = $matcher->matchPairs($treated, $candidates, 10, false, 0.7);
        $this->assertSame([['/onset', '/new']], $pairs);
        $this->assertSame([], $matcher->matchPairs($treated, $candidates, 10, false, 0.5));
    }
}
