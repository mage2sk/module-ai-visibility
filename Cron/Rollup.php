<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Cron;

use Panth\AiVisibility\Api\RollupInterface;
use Psr\Log\LoggerInterface;

/**
 * Recomputes the daily summary rows of today and yesterday (UTC).
 */
class Rollup
{
    /**
     * @param RollupInterface $rollup
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RollupInterface $rollup,
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
        $this->run();
    }

    /**
     * Roll up yesterday and today
     *
     * @param int|null $now Unix time, null for now
     * @return string[] Days rolled up successfully
     */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $done = [];
        foreach ([gmdate('Y-m-d', $now - 86400), gmdate('Y-m-d', $now)] as $day) {
            try {
                $this->rollup->rollupDay($day);
                $done[] = $day;
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('[Panth_AiVisibility] rollup of %s failed: %s', $day, $e->getMessage()));
            }
        }
        return $done;
    }
}
