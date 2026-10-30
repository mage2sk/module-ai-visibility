<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Insights;

use Panth\AiVisibility\Service\Insights\CoverageInsight;
use Panth\AiVisibility\Service\Insights\ErrorRateInsight;
use Panth\AiVisibility\Service\Insights\InsightProvider;
use Panth\AiVisibility\Service\Insights\LlmsInsight;
use Panth\AiVisibility\Service\Insights\RobotsInsight;
use Panth\AiVisibility\Service\Report\DateRange;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InsightProviderTest extends TestCase
{
    public function testWarningsFirstAndAFailingInsightIsSkipped(): void
    {
        $robots = $this->createStub(RobotsInsight::class);
        $robots->method('collect')->willReturn([['level' => 'info', 'title' => 'R', 'text' => 'r']]);
        $llms = $this->createStub(LlmsInsight::class);
        $llms->method('collect')->willThrowException(new \RuntimeException('table missing'));
        $coverage = $this->createStub(CoverageInsight::class);
        $coverage->method('collect')->willReturn([
            ['level' => 'warning', 'title' => 'C', 'text' => 'c'],
            ['level' => 'bogus', 'title' => 'B', 'text' => 'b'],
        ]);
        $errors = $this->createStub(ErrorRateInsight::class);
        $errors->method('collect')->willReturn([['level' => 'ok', 'title' => 'E', 'text' => 'e']]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('table missing'));

        $provider = new InsightProvider($robots, $llms, $coverage, $errors, $logger);
        $result = $provider->collect(new DateRange('2026-10-01', '2026-10-30', '30'), 0, false);

        $this->assertSame(['C', 'R', 'B', 'E'], array_column($result, 'title'));
        $this->assertSame(['warning', 'info', 'info', 'ok'], array_column($result, 'level'));
    }
}
