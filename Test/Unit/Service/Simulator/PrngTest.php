<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Simulator;

use Panth\AiVisibility\Service\Simulator\Prng;
use PHPUnit\Framework\TestCase;

class PrngTest extends TestCase
{
    /**
     * Pinned xoshiro128** output for seed 7, empty stream (cross-checked against an
     * independent reference implementation and against PHP 8.1 and 8.4).
     */
    public function testKnownSequence(): void
    {
        $prng = new Prng(7, '');
        $values = [];
        for ($i = 0; $i < 6; $i++) {
            $values[] = $prng->nextInt32();
        }
        $this->assertSame([3334075507, 1803074527, 597993050, 3404741313, 4202553117, 3953606886], $values);
    }

    public function testSameSeedAndStreamRepeatAndOtherStreamsDiffer(): void
    {
        $a = new Prng(42, '2026-01-01|bot:gptbot');
        $b = new Prng(42, '2026-01-01|bot:gptbot');
        $c = new Prng(42, '2026-01-01|bot:claudebot');
        $d = new Prng(43, '2026-01-01|bot:gptbot');
        $seqA = $seqB = $seqC = $seqD = [];
        for ($i = 0; $i < 20; $i++) {
            $seqA[] = $a->nextInt32();
            $seqB[] = $b->nextInt32();
            $seqC[] = $c->nextInt32();
            $seqD[] = $d->nextInt32();
        }
        $this->assertSame($seqA, $seqB);
        $this->assertNotSame($seqA, $seqC);
        $this->assertNotSame($seqA, $seqD);
    }

    public function testValuesStayInside32Bits(): void
    {
        $prng = new Prng(1, 'range');
        for ($i = 0; $i < 5000; $i++) {
            $value = $prng->nextInt32();
            $this->assertGreaterThanOrEqual(0, $value);
            $this->assertLessThanOrEqual(0xFFFFFFFF, $value);
        }
    }

    public function testIntIsInclusiveAndCoversBothEnds(): void
    {
        $prng = new Prng(3, 'int');
        $seen = [];
        for ($i = 0; $i < 2000; $i++) {
            $value = $prng->int(-2, 2);
            $this->assertGreaterThanOrEqual(-2, $value);
            $this->assertLessThanOrEqual(2, $value);
            $seen[$value] = true;
        }
        ksort($seen);
        $this->assertSame([-2, -1, 0, 1, 2], array_keys($seen));
        $this->assertSame(5, $prng->int(5, 5));
        $this->assertSame(5, $prng->int(5, 1));
    }

    public function testChanceEdgesAndRate(): void
    {
        $prng = new Prng(5, 'chance');
        $hits = 0;
        for ($i = 0; $i < 10000; $i++) {
            $this->assertFalse($prng->chance(0));
            $this->assertTrue($prng->chance(1000));
            $hits += $prng->chance(300) ? 1 : 0;
        }
        $this->assertGreaterThan(2800, $hits);
        $this->assertLessThan(3200, $hits);
    }

    public function testWeightedNeverPicksZeroWeight(): void
    {
        $prng = new Prng(9, 'weighted');
        $counts = ['a' => 0, 'b' => 0, 'c' => 0];
        for ($i = 0; $i < 3000; $i++) {
            $counts[$prng->weighted(['a' => 1, 'b' => 0, 'c' => 3])]++;
        }
        $this->assertSame(0, $counts['b']);
        $this->assertGreaterThan($counts['a'] * 2, $counts['c']);
    }

    public function testWeightedRejectsAllZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Prng(1))->weighted(['a' => 0]);
    }

    public function testSkewedLowFavoursSmallValues(): void
    {
        $prng = new Prng(11, 'skew');
        $low = 0;
        for ($i = 0; $i < 4000; $i++) {
            $value = $prng->skewedLow(0, 99);
            $this->assertGreaterThanOrEqual(0, $value);
            $this->assertLessThanOrEqual(99, $value);
            $low += $value < 50 ? 1 : 0;
        }
        // P(min of two uniforms < half) = 75 %.
        $this->assertGreaterThan(2800, $low);
        $this->assertLessThan(3200, $low);
    }
}
