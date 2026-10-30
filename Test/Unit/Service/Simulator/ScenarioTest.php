<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Simulator;

use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Simulator\Scenario;
use PHPUnit\Framework\TestCase;

class ScenarioTest extends TestCase
{
    /**
     * @dataProvider expectedProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('expectedProvider')]
    public function testExpectedMaths(int $scale, int $perDay, int $dayIndex, int $weekday, array $expected): void
    {
        $this->assertSame($expected, Scenario::standard($scale)->expected($perDay, $dayIndex, $weekday));
    }

    /**
     * @return array<string, array>
     */
    public static function expectedProvider(): array
    {
        return [
            // Friday weight 1000, no growth on day 0.
            'base' => [1000, 100, 0, 5, [100, 0]],
            // Monday 1040 per mille.
            'weekday' => [1000, 100, 0, 1, [104, 0]],
            // Growth 4 per mille per day: day 50 => x1.2.
            'growth' => [1000, 100, 50, 5, [120, 0]],
            // Half scale with a fractional result: 7 * 0.5 = 3.5.
            'fraction' => [500, 7, 0, 5, [3, 500000000]],
            // Saturday 900, scale 2.
            'scaled weekend' => [2000, 10, 0, 6, [18, 0]],
        ];
    }

    public function testScaleIsClamped(): void
    {
        $this->assertSame(10, Scenario::standard(1)->scalePermille());
        $this->assertSame(100000, Scenario::standard(5000000)->scalePermille());
        $this->assertSame(10, Scenario::standard(1000)->with(['scale_permille' => 0])->scalePermille());
    }

    public function testWithReplacesOnlyGivenKeys(): void
    {
        $base = Scenario::standard();
        $planted = $base->with(['hidden_permille' => 500]);
        $this->assertSame(300, $base->get('hidden_permille'));
        $this->assertSame(500, $planted->get('hidden_permille'));
        $this->assertSame($base->bots(), $planted->bots());
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scenario::standard()->get('nope');
    }

    public function testBotsAndSourcesAreConsistent(): void
    {
        $scenario = Scenario::standard();
        $this->assertCount(22, $scenario->bots());
        $this->assertCount(10, $scenario->sources());
        foreach ($scenario->bots() as $code => $bot) {
            $this->assertTrue(Category::isValid($bot['category']), $code);
            $this->assertCount(24, $scenario->hourWeights($bot['hours']), $code);
            if ($bot['source'] !== null) {
                $this->assertSame('live', $bot['category'], $code);
                $this->assertArrayHasKey($bot['source'], $scenario->sources(), $code);
            }
            $this->assertLessThan(1000, array_sum($bot['mix']) + 0, $code);
            $this->assertLessThan(1000, array_sum($bot['status']), $code);
        }
        foreach (array_keys($scenario->get('order_sources')) as $code) {
            $this->assertArrayHasKey($code, $scenario->sources());
        }
    }
}
