<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Setup\Patch\Data;

use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Setup\Patch\Data\RerollRawRetentionDays;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RerollRawRetentionDaysTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $rolled = [];

    /**
     * @var string[]
     */
    private array $warnings = [];

    private function patch(int $hitDays, int $visitDays, ?string $failingDay = null): RerollRawRetentionDays
    {
        $rollup = $this->createStub(RollupInterface::class);
        $rollup->method('rollupDay')->willReturnCallback(function (string $day, ?int $store = null) use ($failingDay) {
            $this->assertNull($store, 'every store view is re-rolled');
            if ($day === $failingDay) {
                throw new \RuntimeException('Deadlock found');
            }
            $this->rolled[] = $day;
        });
        $config = $this->createStub(Config::class);
        $config->method('hitRetentionDays')->willReturn($hitDays);
        $config->method('rawVisitRetentionDays')->willReturn($visitDays);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });
        return new RerollRawRetentionDays($rollup, $config, $logger, gmmktime(3, 0, 0, 10, 30, 2026));
    }

    public function testReRollsEveryDayInsideTheRawRetentionOldestFirst(): void
    {
        $this->patch(14, 3)->apply();

        $this->assertCount(14, $this->rolled);
        $this->assertSame('2026-10-17', $this->rolled[0]);
        $this->assertSame('2026-10-30', $this->rolled[13]);
        $this->assertSame([], $this->warnings);
    }

    public function testVisitRetentionLongerThanHitRetentionIsCoveredAndTheRangeIsCapped(): void
    {
        $this->patch(14, 30)->apply();
        $this->assertCount(30, $this->rolled);
        $this->assertSame('2026-10-01', $this->rolled[0]);

        $this->rolled = [];
        $this->patch(730, 30)->apply();
        $this->assertCount(RerollRawRetentionDays::MAX_DAYS, $this->rolled);
    }

    public function testAFailingDayIsLoggedAndTheOthersStillRun(): void
    {
        $this->patch(3, 1, '2026-10-29')->apply();

        $this->assertSame(['2026-10-28', '2026-10-30'], $this->rolled);
        $this->assertCount(2, $this->warnings);
        $this->assertStringContainsString('2026-10-29', $this->warnings[0]);
        $this->assertStringContainsString('panth:aivisibility:rollup --days=3', $this->warnings[1]);
    }

    public function testNothingToDoWithoutRetentionAndNoDependencies(): void
    {
        $this->patch(0, 0)->apply();
        $this->assertSame([], $this->rolled);
        $this->assertSame([], RerollRawRetentionDays::getDependencies());
        $this->assertSame([], $this->patch(1, 1)->getAliases());
    }
}
