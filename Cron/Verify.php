<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Cron;

use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies applied fixes whose verification window has ended (cron job panth_aiv_verify).
 */
class Verify
{
    /**
     * @param ImpactCalculatorInterface $calculator
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ImpactCalculatorInterface $calculator,
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
            $this->calculator->verifyDue();
        } catch (\Throwable $e) {
            $this->logger->warning('Panth AI Visibility: verification cron failed: ' . $e->getMessage());
        }
    }
}
