<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Upgrade from 1.0: re-rolls every day whose raw rows are still kept, so the summary data added in 1.1
 * (per-product counts in panth_aiv_entity_daily, path error / speed columns, timed and query-string hit
 * counts in panth_aiv_crawl_daily, visits per landing path) also exists for those days.
 *
 * Covers the raw hit and raw visit retention (at most MAX_DAYS days), oldest day first. The rollup is
 * idempotent and per slice safe: it never replaces daily rows of a day whose raw rows were partly pruned.
 * Days older than the raw retention keep their 1.0 rows without the new detail. A failing day is logged
 * and skipped; the patch never stops setup:upgrade. On a new installation there is nothing to re-roll.
 */
class RerollRawRetentionDays implements DataPatchInterface
{
    /**
     * Upper bound of days re-rolled, whatever the retention settings
     */
    public const MAX_DAYS = 60;

    /**
     * @param RollupInterface $rollup
     * @param Config $config
     * @param LoggerInterface $logger
     * @param int|null $now Unix time (tests)
     */
    public function __construct(
        private readonly RollupInterface $rollup,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ?int $now = null
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        try {
            $days = self::days(
                max($this->config->hitRetentionDays(), $this->config->rawVisitRetentionDays()),
                $this->now ?? time()
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Panth AI Visibility: re-rolling recent days skipped: ' . $e->getMessage());
            return $this;
        }
        $failed = 0;
        foreach ($days as $day) {
            try {
                $this->rollup->rollupDay($day);
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->warning(
                    sprintf('Panth AI Visibility: re-rolling %s after the upgrade failed: %s', $day, $e->getMessage())
                );
            }
        }
        if ($failed > 0) {
            $this->logger->warning(sprintf(
                'Panth AI Visibility: %d day(s) were not re-rolled; run bin/magento panth:aivisibility:rollup --days=%d',
                $failed,
                count($days)
            ));
        }
        return $this;
    }

    /**
     * UTC days to re-roll, oldest first: today and the days before it inside the retention (capped)
     *
     * @param int $retentionDays
     * @param int $now
     * @return string[]
     */
    public static function days(int $retentionDays, int $now): array
    {
        $count = min(self::MAX_DAYS, max(0, $retentionDays));
        $today = (int)strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC');
        $out = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $out[] = gmdate('Y-m-d', $today - $i * 86400);
        }
        return $out;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }
}
