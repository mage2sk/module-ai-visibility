<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Simulator;

use Panth\AiVisibility\Service\Simulator\Prng;
use Panth\AiVisibility\Service\Simulator\Scenario;
use Panth\AiVisibility\Service\Simulator\TrafficGenerator;
use PHPUnit\Framework\TestCase;

class TrafficGeneratorTest extends TestCase
{
    private const LAST_DAY = '2026-03-03';

    /**
     * @return array<string, string[]>
     */
    private static function paths(): array
    {
        $paths = ['product' => [], 'category' => [], 'cms' => ['/about-us', '/contact']];
        for ($i = 1; $i <= 40; $i++) {
            $paths['product'][] = '/product-' . $i . '.html';
        }
        for ($i = 1; $i <= 8; $i++) {
            $paths['category'][] = '/category-' . $i . '.html';
        }
        return $paths;
    }

    private function generator(?Scenario $scenario = null, int $seed = 7, string $lastDay = self::LAST_DAY): TrafficGenerator
    {
        return new TrafficGenerator(
            $scenario ?? Scenario::standard(),
            $seed,
            1,
            self::paths(),
            TrafficGenerator::dayStart($lastDay) + 86399
        );
    }

    /**
     * The same fingerprint is produced on PHP 8.1 and 8.4: any change to the generator,
     * the scenario defaults or the PRNG shows up here.
     */
    public function testOutputFingerprintIsStable(): void
    {
        $generator = $this->generator();
        $all = [];
        foreach (['2026-03-01', '2026-03-02', '2026-03-03'] as $index => $day) {
            $all[] = $generator->generateDay($day, $index, 3, 11, 12);
        }
        $all[] = $generator->orderAttribution(1001, 1772500000);
        $this->assertSame('5111c426c1e9773c056dd8bd03fb121dbb7880c2', sha1(json_encode($all)));
    }

    public function testSameSeedSameRowsOtherSeedOtherRows(): void
    {
        $a = $this->generator()->generateDay('2026-03-02', 5, 10, 1, 2);
        $b = $this->generator()->generateDay('2026-03-02', 5, 10, 1, 2);
        $c = $this->generator(null, 8)->generateDay('2026-03-02', 5, 10, 1, 2);
        $this->assertSame($a, $b);
        $this->assertNotSame($a['hits'], $c['hits']);
    }

    public function testRowsAreFlaggedSimulatedAndWellFormed(): void
    {
        $result = $this->generator()->generateDay('2026-03-02', 1, 3, 11, 12);
        $known = array_merge(...array_values(self::paths()));
        $known = array_merge($known, ['/', '/robots.txt', '/llms.txt', '/sitemap.xml']);
        $this->assertNotEmpty($result['hits']);
        foreach ($result['hits'] as $hit) {
            $this->assertSame(1, $hit['is_simulated']);
            $this->assertSame('sim', $hit['origin']);
            $this->assertSame(1, $hit['store_id']);
            $this->assertSame(sha1($hit['path']), $hit['path_hash']);
            $this->assertIsInt($hit['duration_ms']);
            $this->assertStringStartsWith('2026-03-02 ', $hit['created_at']);
            if ($hit['status'] === 404) {
                $this->assertMatchesRegularExpression('#^/retired-item-\d+\.html$#', $hit['path']);
            } else {
                $this->assertContains($hit['path'], $known);
            }
            if ($hit['status'] >= 500) {
                $this->assertGreaterThanOrEqual(1500, $hit['duration_ms']);
            }
        }
        foreach (array_merge($result['visits'], $result['landings']) as $row) {
            $this->assertSame(1, $row['is_simulated']);
        }
        $statuses = array_count_values(array_column($result['hits'], 'status'));
        $this->assertGreaterThan(0.9 * count($result['hits']), $statuses[200]);
    }

    public function testDailyCountsStayWithinExpectedBounds(): void
    {
        $scenario = Scenario::standard();
        $generator = $this->generator($scenario, 7, '2026-04-30');
        $start = TrafficGenerator::dayStart('2026-04-01');
        for ($index = 0; $index < 30; $index++) {
            $dayStart = $start + $index * 86400;
            $day = gmdate('Y-m-d', $dayStart);
            $weekday = (int) gmdate('N', $dayStart);
            $result = $generator->generateDay($day, $index, 30);
            $perBot = array_count_values(array_column($result['hits'], 'bot_code'));
            foreach ($scenario->bots() as $code => $bot) {
                [$whole] = $scenario->expected((int) $bot['per_day'], $index, $weekday);
                $spread = intdiv(($whole + 1) * 15, 100);
                $count = $perBot[$code] ?? 0;
                $this->assertGreaterThanOrEqual($whole - $spread, $count, $day . ' ' . $code);
                $this->assertLessThanOrEqual($whole + 1 + $spread, $count, $day . ' ' . $code);
            }
        }
    }

    public function testLagIsBoundedAndSkewedShort(): void
    {
        $generator = $this->generator();
        $prng = new Prng(7, 'lag-test');
        $short = 0;
        for ($i = 0; $i < 2000; $i++) {
            $lag = $generator->lag($prng);
            $this->assertGreaterThanOrEqual(4, $lag);
            $this->assertLessThanOrEqual(900, $lag);
            $short += $lag < 452 ? 1 : 0;
        }
        $this->assertGreaterThan(1300, $short);
    }

    public function testEveryReferralVisitFollowsALiveFetchOfTheSamePage(): void
    {
        $sources = Scenario::standard()->sources();
        foreach ($sources as $code => $source) {
            $sources[$code]['direct_per_day'] = 0;
        }
        $scenario = Scenario::standard()->with(['sources' => $sources]);
        $result = $this->generator($scenario)->generateDay('2026-03-02', 1, 3);
        $live = [];
        foreach ($result['hits'] as $hit) {
            if ($hit['category'] === 'live' && $hit['status'] === 200) {
                $live[$hit['path']][] = strtotime($hit['created_at'] . ' UTC');
            }
        }
        $this->assertNotEmpty($result['visits']);
        foreach ($result['visits'] as $visit) {
            $at = strtotime($visit['created_at'] . ' UTC');
            $matched = false;
            foreach ($live[$visit['landing_path']] ?? [] as $fetchedAt) {
                $matched = $matched || ($at - $fetchedAt >= 4 && $at - $fetchedAt <= 900);
            }
            $this->assertTrue($matched, 'No live fetch before visit to ' . $visit['landing_path']);
            $this->assertContains($visit['medium'], ['referrer', 'utm']);
            $this->assertSame($visit['medium'] === 'utm', $visit['referrer_host'] === null);
        }
    }

    public function testHiddenShareMatchesPlantedValue(): void
    {
        $generator = $this->generator(null, 7, '2026-04-30');
        $clicks = 0;
        $hidden = 0;
        $start = TrafficGenerator::dayStart('2026-04-01');
        for ($index = 0; $index < 30; $index++) {
            $stats = $generator->generateDay(gmdate('Y-m-d', $start + $index * 86400), $index, 30)['stats'];
            $clicks += $stats['clicks'];
            $hidden += $stats['hidden'];
        }
        $this->assertGreaterThan(500, $clicks);
        $share = intdiv($hidden * 1000, $clicks);
        $this->assertGreaterThan(250, $share);
        $this->assertLessThan(350, $share);
    }

    public function testPlantedCandidatesExactCountsAndWindow(): void
    {
        $generator = $this->generator(null, 7, '2026-03-30');
        $start = TrafficGenerator::dayStart('2026-03-01');
        for ($index = 0; $index < 30; $index++) {
            $result = $generator->generateDay(gmdate('Y-m-d', $start + $index * 86400), $index, 30, 11, 12);
            $candHits = array_values(array_filter($result['hits'], static fn($h) => $h['bot_code'] === 'cand:11'));
            $candVisits = array_values(
                array_filter($result['visits'], static fn($v) => $v['source_code'] === 'cand:12')
            );
            $botActive = $index >= 30 - 21;
            $this->assertCount($botActive ? 27 : 0, $candHits, 'day ' . $index);
            if ($botActive) {
                $paths = array_count_values(array_column($candHits, 'path'));
                $this->assertSame(2, $paths['/llms.txt']);
                $this->assertSame(1, $paths['/robots.txt']);
                foreach ($candHits as $hit) {
                    $this->assertSame('unknown', $hit['category']);
                    $this->assertSame(200, $hit['status']);
                }
            }
            $sourceActive = $index >= 30 - 14;
            $this->assertCount($sourceActive ? 3 : 0, $candVisits, 'day ' . $index);
            foreach ($candVisits as $visit) {
                $this->assertSame('assistant.example.invalid', $visit['referrer_host']);
                $this->assertSame('referrer', $visit['medium']);
            }
        }
    }

    public function testNothingIsWrittenAfterThePeriodEnd(): void
    {
        $result = $this->generator()->generateDay(self::LAST_DAY, 2, 3, 11, 12);
        foreach (['hits', 'visits', 'landings'] as $key) {
            foreach ($result[$key] as $row) {
                $this->assertLessThanOrEqual(self::LAST_DAY . ' 23:59:59', $row['created_at']);
            }
        }
    }

    public function testOrderAttributionIsDeterministicAndBounded(): void
    {
        $generator = $this->generator();
        $orderTs = 1772500000;
        $row = $generator->orderAttribution(55, $orderTs);
        $this->assertSame($row, $this->generator()->orderAttribution(55, $orderTs));
        $this->assertSame(55, $row['order_id']);
        $this->assertSame('referral', $row['channel']);
        $this->assertNull($row['probability']);
        $this->assertSame(1, $row['is_simulated']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $orderTs), $row['created_at']);
        $firstAt = strtotime($row['first_at'] . ' UTC');
        $lastAt = strtotime($row['last_at'] . ' UTC');
        $this->assertGreaterThanOrEqual($orderTs - 259200, $firstAt);
        $this->assertLessThanOrEqual($orderTs - 600, $firstAt);
        $this->assertGreaterThanOrEqual($firstAt, $lastAt);
        $this->assertLessThanOrEqual($orderTs, $lastAt);
        $this->assertContains($row['landing_path'], self::paths()['product']);
    }

    public function testRequiresAtLeastOnePagePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrafficGenerator(Scenario::standard(), 1, 1, ['product' => [], 'category' => [], 'cms' => []], 0);
    }
}
