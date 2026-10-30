<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Simulator;

/**
 * Small deterministic pseudo-random generator (xoshiro128**) for the traffic simulator.
 *
 * Uses only 32-bit integer arithmetic (shifts, xor, multiplication by 5 and 9), so the
 * sequence is identical on every 64-bit PHP build from 8.1 on. The state is derived from
 * SHA-256 of the seed and a stream key, which gives independent streams per day and
 * per scenario element: changing one part of a scenario never reshuffles the others.
 * mt_rand() / random_int() are deliberately not used.
 */
class Prng
{
    private const MASK = 0xFFFFFFFF;
    private const RANGE = 4294967296;

    /**
     * @var int[]
     */
    private array $state;

    /**
     * @param int $seed
     * @param string $stream Stream key, e.g. "2026-01-31|bot:gptbot"
     */
    public function __construct(int $seed, string $stream = '')
    {
        $hex = hash('sha256', 'panth_aiv_sim|' . $seed . '|' . $stream);
        $state = [];
        for ($i = 0; $i < 4; $i++) {
            $state[] = (int) hexdec(substr($hex, $i * 8, 8));
        }
        if (($state[0] | $state[1] | $state[2] | $state[3]) === 0) {
            $state[0] = 1;
        }
        $this->state = $state;
    }

    /**
     * Next raw value, 0 .. 2^32-1
     *
     * @return int
     */
    public function nextInt32(): int
    {
        [$s0, $s1, $s2, $s3] = $this->state;
        $result = (self::rotl(($s1 * 5) & self::MASK, 7) * 9) & self::MASK;
        $t = ($s1 << 9) & self::MASK;
        $s2 ^= $s0;
        $s3 ^= $s1;
        $s1 ^= $s2;
        $s0 ^= $s3;
        $s2 ^= $t;
        $s3 = self::rotl($s3, 11);
        $this->state = [$s0, $s1, $s2, $s3];
        return $result;
    }

    /**
     * Uniform integer in [min, max] (both inclusive), without modulo bias
     *
     * @param int $min
     * @param int $max
     * @return int
     */
    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        $range = $max - $min + 1;
        if ($range > self::RANGE) {
            throw new \InvalidArgumentException('Range is larger than 2^32.');
        }
        $limit = self::RANGE - (self::RANGE % $range);
        do {
            $value = $this->nextInt32();
        } while ($value >= $limit);
        return $min + ($value % $range);
    }

    /**
     * True with a probability of $permille / 1000
     *
     * @param int $permille
     * @return bool
     */
    public function chance(int $permille): bool
    {
        if ($permille <= 0) {
            return false;
        }
        if ($permille >= 1000) {
            return true;
        }
        return $this->int(0, 999) < $permille;
    }

    /**
     * Index picked with integer weights (zero weights are never picked)
     *
     * @param int[] $weights Keys are returned as given
     * @return int|string
     */
    public function weighted(array $weights)
    {
        $total = 0;
        foreach ($weights as $weight) {
            $total += max(0, (int) $weight);
        }
        if ($total <= 0) {
            throw new \InvalidArgumentException('Weights must contain a positive value.');
        }
        $roll = $this->int(0, $total - 1);
        foreach ($weights as $key => $weight) {
            $roll -= max(0, (int) $weight);
            if ($roll < 0) {
                return $key;
            }
        }
        // Unreachable; keeps static analysis happy.
        return array_key_last($weights);
    }

    /**
     * Integer in [min, max] skewed towards min (minimum of two uniform draws)
     *
     * Used for "popular head" path picks and for short lags with a long tail.
     *
     * @param int $min
     * @param int $max
     * @return int
     */
    public function skewedLow(int $min, int $max): int
    {
        return min($this->int($min, $max), $this->int($min, $max));
    }

    /**
     * Rotate a 32-bit value left
     *
     * @param int $value
     * @param int $bits
     * @return int
     */
    private static function rotl(int $value, int $bits): int
    {
        return (($value << $bits) | ($value >> (32 - $bits))) & self::MASK;
    }
}
