<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Simulator\Prng;
use Panth\AiVisibility\Service\Verification\Bootstrap;
use Panth\AiVisibility\Service\Verification\ControlMatcher;
use Panth\AiVisibility\Service\Verification\ImpactCalculator;
use Panth\AiVisibility\Service\Verification\ImpactStore;
use Panth\AiVisibility\Service\Verification\PoissonRateTest;
use Panth\AiVisibility\Service\Verification\ProportionDifference;
use Panth\AiVisibility\Service\Verification\SubjectPool;
use Panth\AiVisibility\Service\Verification\WelchTTest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Final 1.1 re-verification. "testDefect..." fails on purpose: nothing changed at the applied day, but
 * the treated pages only started erroring at the start of the before window (pre window = 0), so the
 * pre-baseline cross-check is undefined and the bias-corrected main estimate alone decides.
 */
class V11FinalReverifyTest extends TestCase
{
    public function testDefectNewlyErroringPagesWithUnchangedRateAreJudgedWorse(): void
    {
        $trials = 20;
        $wrong = 0;
        $effects = [];
        for ($trial = 0; $trial < $trials; $trial++) {
            $prng = new Prng($trial, 'frv-new');
            $data = [];
            for ($j = 0; $j < 300; $j++) {
                $lambda = 2.0 + 60.0 * ($j / 299) ** 2;
                $data['/old-' . $j] = [self::poisson($prng, $lambda), self::poisson($prng, $lambda), self::poisson($prng, $lambda)];
            }
            $treated = [];
            for ($k = 0; $k < 6; $k++) {
                // [before, after, pre]: 30 a day since the first before day, also after the change.
                $data['/new-' . $k] = [self::poisson($prng, 420.0), self::poisson($prng, 420.0), 0];
                $treated[] = '/new-' . $k;
            }
            $pool = array_values(array_diff(array_keys($data), $treated));
            $row = $this->calculator($trial + 1, $treated, $data, $pool)->verify($trial + 1, true);
            $effects[] = $row['verdict'] . ' ' . $row['effect'];
            $wrong += in_array(
                $row['verdict'],
                [ImpactCalculatorInterface::VERDICT_HELPED, ImpactCalculatorInterface::VERDICT_WORSE],
                true
            ) ? 1 : 0;
        }
        $this->assertLessThanOrEqual(
            (int)ceil(0.10 * $trials),
            $wrong,
            sprintf('unchanged new 404 pages judged an effect in %d of %d trials: %s', $wrong, $trials, implode(', ', $effects))
        );
    }

    private static function poisson(Prng $prng, float $lambda): int
    {
        if ($lambda > 50) {
            // Normal approximation keeps the loop short for large rates.
            $u1 = ($prng->int(0, 999999) + 0.5) / 1000000.0;
            $u2 = ($prng->int(0, 999999) + 0.5) / 1000000.0;
            return max(0, (int)round($lambda + sqrt($lambda) * sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2)));
        }
        $limit = exp(-$lambda);
        $k = 0;
        $product = 1.0;
        while (true) {
            $product *= ($prng->int(0, 999999) + 0.5) / 1000000.0;
            if ($product <= $limit) {
                return $k;
            }
            $k++;
        }
    }

    private function calculator(int $actionId, array $treated, array $data, array $pool): ImpactCalculator
    {
        $action = [
            'action_id' => $actionId, 'rec_id' => null, 'rule_code' => 'bot_404', 'store_id' => 1,
            'metric' => MetricProviderInterface::METRIC_BOT_404, 'subjects' => json_encode($treated),
            'applied_at' => '2026-03-01 10:00:00', 'undone_at' => null, 'verify_after' => '2026-03-16',
            'status' => ActionLogInterface::STATUS_PENDING, 'is_simulated' => 0, 'rec_subject_type' => 'path',
        ];
        $store = $this->createStub(ImpactStore::class);
        $store->method('getAction')->willReturn($action);
        $metrics = $this->createStub(MetricProviderInterface::class);
        $metrics->method('supports')->willReturn(true);
        $metrics->method('value')->willReturnCallback(
            static function (string $metric, int $s, array $subjects, string $from, string $to) use ($data) {
                // before = [02-15, 02-28], after = [03-02, 03-15], pre = [02-01, 02-14]
                $index = $from >= '2026-03-02' ? 1 : ($to <= '2026-02-14' ? 2 : 0);
                $out = [];
                foreach ($subjects as $ref) {
                    $out[$ref] = ['num' => (float)($data[$ref][$index] ?? 0), 'den' => 1.0];
                }
                return $out;
            }
        );
        $subjectPool = $this->createStub(SubjectPool::class);
        $subjectPool->method('candidates')->willReturn($pool);
        $cfg = $this->createStub(Config::class);
        foreach (['verifyMinEvents' => 30, 'verifyMaxControls' => 50, 'verifyWindowDays' => 14,
            'hitRetentionDays' => 14, 'rawVisitRetentionDays' => 30] as $method => $value) {
            $cfg->method($method)->willReturn($value);
        }
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(strtotime('2026-03-16 04:40:00 UTC'));
        return new ImpactCalculator(
            $store, $metrics, $subjectPool, new ControlMatcher(), new Bootstrap(), new PoissonRateTest(),
            new ProportionDifference(), new WelchTTest(), $cfg, $clock, new Json(), $this->createStub(LoggerInterface::class)
        );
    }
}
