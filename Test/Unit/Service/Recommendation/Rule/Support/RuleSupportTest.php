<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule\Support;

use Panth\AiVisibility\Service\Recommendation\Rule\Support\RuleSupport;
use PHPUnit\Framework\TestCase;

class RuleSupportTest extends TestCase
{
    public function testDayWindowIsUtcAndInclusive(): void
    {
        $today = new \DateTimeImmutable('2026-03-01 23:30:00', new \DateTimeZone('America/New_York'));
        $this->assertSame('2026-03-02', RuleSupport::toDay($today), 'converted to the UTC day');
        $this->assertSame('2026-02-01', RuleSupport::fromDay($today, 30));
        $this->assertSame('2026-03-02', RuleSupport::fromDay($today, 1));
        $this->assertSame('2026-03-01 00:00:00', RuleSupport::endExclusive('2026-02-28'));
        $this->assertSame('2026-02-28 00:00:00', RuleSupport::startOf('2026-02-28'));
    }

    /**
     * @dataProvider scoreProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scoreProvider')]
    public function testImpactScore(float $weight, int $volume, int $expected): void
    {
        $this->assertSame($expected, RuleSupport::impactScore($weight, $volume));
    }

    /**
     * @return array<string, array{0: float, 1: int, 2: int}>
     */
    public static function scoreProvider(): array
    {
        return [
            'nothing' => [3.0, 0, 0],
            'ten' => [3.0, 10, 31],
            'hundred' => [3.0, 100, 60],
            'capped' => [5.0, 1000000, 100],
            'no weight' => [0.0, 50, 0],
        ];
    }

    public function testStoreSqlAndPlaceholders(): void
    {
        $bind = ['x'];
        $this->assertSame('', RuleSupport::storeSql('t.store_id', 0, $bind));
        $this->assertSame(' AND t.store_id = ?', RuleSupport::storeSql('t.store_id', 3, $bind));
        $this->assertSame(['x', 3], $bind);
        $this->assertSame('?, ?, ?', RuleSupport::placeholders(3));
        $this->assertSame('?', RuleSupport::placeholders(0));
    }
}
