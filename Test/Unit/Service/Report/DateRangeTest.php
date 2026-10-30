<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Report;

use Panth\AiVisibility\Service\Report\DateRange;
use PHPUnit\Framework\TestCase;

class DateRangeTest extends TestCase
{
    /**
     * 2026-10-30 23:30:00 UTC (already 2026-10-31 in Asia, still 2026-10-30 in America)
     */
    private const NOW = 1793403000;

    /**
     * @var string
     */
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testNowConstantIsTheExpectedUtcMoment(): void
    {
        $this->assertSame('2026-10-30 23:30:00', gmdate('Y-m-d H:i:s', self::NOW));
    }

    /**
     * @dataProvider presetProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('presetProvider')]
    public function testPresetPeriodsEndToday(string $period, string $from, int $days): void
    {
        $range = DateRange::fromInput($period, null, null, self::NOW);
        $this->assertSame($from, $range->from());
        $this->assertSame('2026-10-30', $range->to());
        $this->assertSame($days, $range->dayCount());
        $this->assertSame($period, $range->period());
        $this->assertFalse($range->isCustom());
        $this->assertFalse($range->wasAdjusted());
    }

    public static function presetProvider(): array
    {
        return [
            '7 days' => ['7', '2026-10-24', 7],
            '30 days' => ['30', '2026-10-01', 30],
            '90 days' => ['90', '2026-08-02', 90],
        ];
    }

    /**
     * @dataProvider badInputProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badInputProvider')]
    public function testBadInputFallsBackToThirtyDays($period, $from, $to): void
    {
        $range = DateRange::fromInput($period, $from, $to, self::NOW);
        $this->assertSame('30', $range->period());
        $this->assertSame('2026-10-01', $range->from());
        $this->assertSame('2026-10-30', $range->to());
    }

    public static function badInputProvider(): array
    {
        return [
            'missing' => [null, null, null],
            'unknown period' => ['365', null, null],
            'array' => [['30'], null, null],
            'sql in period' => ["30' OR 1=1", null, null],
            'custom without dates' => ['custom', null, null],
            'custom bad format' => ['custom', '10/01/2026', '2026-10-05'],
            'custom impossible date' => ['custom', '2026-02-30', '2026-03-05'],
            'custom script' => ['custom', '<script>', '2026-10-05'],
            'custom too old' => ['custom', '1999-12-31', '2026-10-05'],
            'custom with time' => ['custom', '2026-10-01 10:00', '2026-10-05'],
        ];
    }

    public function testInvalidCustomIsFlaggedAsAdjusted(): void
    {
        $this->assertTrue(DateRange::fromInput('custom', 'x', 'y', self::NOW)->wasAdjusted());
        $this->assertFalse(DateRange::fromInput(null, null, null, self::NOW)->wasAdjusted());
    }

    public function testValidCustomRange(): void
    {
        $range = DateRange::fromInput('custom', '2026-09-01', '2026-09-30', self::NOW);
        $this->assertTrue($range->isCustom());
        $this->assertFalse($range->wasAdjusted());
        $this->assertSame('2026-09-01', $range->from());
        $this->assertSame('2026-09-30', $range->to());
        $this->assertSame(30, $range->dayCount());
        $this->assertSame('2026-09-01 00:00:00', $range->startDateTime());
        $this->assertSame('2026-10-01 00:00:00', $range->endDateTimeExclusive());
    }

    public function testReversedCustomRangeIsSwapped(): void
    {
        $range = DateRange::fromInput('custom', '2026-09-30', '2026-09-01', self::NOW);
        $this->assertSame('2026-09-01', $range->from());
        $this->assertSame('2026-09-30', $range->to());
        $this->assertTrue($range->wasAdjusted());
    }

    public function testCustomRangeLongerThan400DaysIsCut(): void
    {
        $range = DateRange::fromInput('custom', '2020-01-01', '2026-10-30', self::NOW);
        $this->assertSame(DateRange::MAX_DAYS, $range->dayCount());
        $this->assertSame('2026-10-30', $range->to());
        $this->assertSame('2025-09-26', $range->from());
        $this->assertTrue($range->wasAdjusted());
        $this->assertCount(400, $range->days());
    }

    public function testExactly400DaysIsKept(): void
    {
        $range = DateRange::fromInput('custom', '2025-09-26', '2026-10-30', self::NOW);
        $this->assertSame(400, $range->dayCount());
        $this->assertFalse($range->wasAdjusted());
    }

    public function testFutureEndIsClampedToToday(): void
    {
        $range = DateRange::fromInput('custom', '2026-10-20', '2027-01-01', self::NOW);
        $this->assertSame('2026-10-30', $range->to());
        $this->assertTrue($range->wasAdjusted());

        $future = DateRange::fromInput('custom', '2027-01-01', '2027-02-01', self::NOW);
        $this->assertSame('2026-10-30', $future->from());
        $this->assertSame('2026-10-30', $future->to());
        $this->assertSame(1, $future->dayCount());
    }

    /**
     * @dataProvider timezoneProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('timezoneProvider')]
    public function testTodayIsTheUtcDayWhateverTheDefaultTimezone(string $timezone): void
    {
        date_default_timezone_set($timezone);
        $range = DateRange::fromInput('7', null, null, self::NOW);
        $this->assertSame('2026-10-30', $range->to());
        $this->assertSame('2026-10-24', $range->from());
        $this->assertSame(
            ['2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'],
            $range->days()
        );
        $this->assertSame('2026-10-31 00:00:00', $range->endDateTimeExclusive());
    }

    public static function timezoneProvider(): array
    {
        return [['UTC'], ['Asia/Kolkata'], ['Pacific/Kiritimati'], ['America/Los_Angeles'], ['Pacific/Pago_Pago']];
    }

    public function testDaysAcrossDstAndMonthEnds(): void
    {
        date_default_timezone_set('Europe/London');
        $range = DateRange::fromInput('custom', '2026-03-28', '2026-04-01', self::NOW);
        $this->assertSame(['2026-03-28', '2026-03-29', '2026-03-30', '2026-03-31', '2026-04-01'], $range->days());
    }
}
