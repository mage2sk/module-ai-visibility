<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Cron;

use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Panth\AiVisibility\Cron\Verify;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VerifyTest extends TestCase
{
    public function testRunsDueVerifications(): void
    {
        $calculator = $this->createMock(ImpactCalculatorInterface::class);
        $calculator->expects($this->once())->method('verifyDue')->willReturn(2);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        (new Verify($calculator, $logger))->execute();
    }

    public function testNeverThrows(): void
    {
        $calculator = $this->createStub(ImpactCalculatorInterface::class);
        $calculator->method('verifyDue')->willThrowException(new \RuntimeException('db down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('db down'));

        (new Verify($calculator, $logger))->execute();
    }
}
