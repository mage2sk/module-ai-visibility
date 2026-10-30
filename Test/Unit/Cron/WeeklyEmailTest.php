<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Cron;

use Panth\AiVisibility\Cron\WeeklyEmail;
use Panth\AiVisibility\Service\Email\WeeklySummarySender;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WeeklyEmailTest extends TestCase
{
    public function testRunsTheScheduledSend(): void
    {
        $sender = $this->createMock(WeeklySummarySender::class);
        $sender->expects($this->once())->method('sendIfDue')->willReturn(true);

        (new WeeklyEmail($sender, $this->createStub(LoggerInterface::class)))->execute();
    }

    public function testNeverThrows(): void
    {
        $sender = $this->createStub(WeeklySummarySender::class);
        $sender->method('sendIfDue')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('boom'));

        (new WeeklyEmail($sender, $logger))->execute();
    }
}
