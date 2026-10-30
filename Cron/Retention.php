<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Cron;

use Panth\AiVisibility\Service\Retention as RetentionService;
use Psr\Log\LoggerInterface;

/**
 * Daily pruning of raw and summary rows per the retention settings.
 */
class Retention
{
    /**
     * @param RetentionService $retention
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RetentionService $retention,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Cron entry point
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->retention->run();
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] retention failed: ' . $e->getMessage());
        }
    }
}
