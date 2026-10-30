<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Cron;

use Panth\AiVisibility\Cron\Health;
use Panth\AiVisibility\Service\Health\HealthRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HealthTest extends TestCase
{
    public function testRunsAllChecks(): void
    {
        $runner = $this->createMock(HealthRunner::class);
        $runner->expects($this->once())->method('runAll')->willReturn([]);

        (new Health($runner, $this->createStub(LoggerInterface::class)))->execute();
    }

    public function testFailureIsLoggedNotThrown(): void
    {
        $runner = $this->createStub(HealthRunner::class);
        $runner->method('runAll')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('boom'));

        (new Health($runner, $logger))->execute();
    }
}
