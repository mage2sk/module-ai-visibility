<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Cron;

use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Cron\Discovery;
use Panth\AiVisibility\Cron\Retention;
use Panth\AiVisibility\Cron\Rollup;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Retention as RetentionService;
use Panth\AiVisibility\Service\SourceEvidence;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CronJobsTest extends TestCase
{
    public function testRollupCoversYesterdayAndToday(): void
    {
        $days = [];
        $rollup = $this->createStub(RollupInterface::class);
        $rollup->method('rollupDay')->willReturnCallback(function (string $day, ?int $store = null) use (&$days) {
            $days[] = [$day, $store];
        });
        $now = gmmktime(0, 15, 0, 3, 1, 2026);

        $done = (new Rollup($rollup, $this->createStub(LoggerInterface::class)))->run($now);

        $this->assertSame([['2026-02-28', null], ['2026-03-01', null]], $days);
        $this->assertSame(['2026-02-28', '2026-03-01'], $done);
    }

    public function testRollupFailureOfOneDayDoesNotStopTheOther(): void
    {
        $rollup = $this->createStub(RollupInterface::class);
        $rollup->method('rollupDay')->willReturnCallback(static function (string $day): void {
            if ($day === '2026-02-28') {
                throw new \RuntimeException('boom');
            }
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('2026-02-28'));

        $done = (new Rollup($rollup, $logger))->run(gmmktime(10, 0, 0, 3, 1, 2026));

        $this->assertSame(['2026-03-01'], $done);
    }

    public function testRollupExecuteNeverThrows(): void
    {
        $rollup = $this->createStub(RollupInterface::class);
        $rollup->method('rollupDay')->willThrowException(new \RuntimeException('down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning');

        (new Rollup($rollup, $logger))->execute();
    }

    public function testRetentionExecuteRunsAndSwallowsErrors(): void
    {
        $service = $this->createMock(RetentionService::class);
        $service->expects($this->once())->method('run')->willThrowException(new \RuntimeException('down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('down'));

        (new Retention($service, $logger))->execute();
    }

    public function testDiscoveryIsSkippedWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('discoveryEnabled')->willReturn(false);
        $evidence = $this->createMock(SourceEvidence::class);
        $evidence->expects($this->never())->method('update');

        (new Discovery($config, $evidence, $this->createStub(LoggerInterface::class)))->execute();
    }

    public function testDiscoveryScoresCandidatesAndSwallowsErrors(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('discoveryEnabled')->willReturn(true);
        $evidence = $this->createMock(SourceEvidence::class);
        $evidence->expects($this->once())->method('update')->willThrowException(new \RuntimeException('down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        (new Discovery($config, $evidence, $logger))->execute();
    }
}
