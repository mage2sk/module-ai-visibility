<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Cron;

use Panth\AiVisibility\Service\Health\HealthRunner;
use Psr\Log\LoggerInterface;

/**
 * Hourly data-health self-checks (cron job panth_aiv_health).
 */
class Health
{
    /**
     * @param HealthRunner $runner
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly HealthRunner $runner,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run every check and store the results
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->runner->runAll();
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] health checks failed: ' . $e->getMessage());
        }
    }
}
