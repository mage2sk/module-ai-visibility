<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Cron;

use Panth\AiVisibility\Service\Email\WeeklySummarySender;
use Psr\Log\LoggerInterface;

/**
 * Sends the weekly summary email at the configured weekday and hour (cron job panth_aiv_weekly_email, hourly).
 *
 * The sender decides whether this run is due (incl. catch-up later in the same ISO week).
 */
class WeeklyEmail
{
    /**
     * @param WeeklySummarySender $sender
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly WeeklySummarySender $sender,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run the job; never throws
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->sender->sendIfDue();
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] weekly email cron failed: ' . $e->getMessage());
        }
    }
}
