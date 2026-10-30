<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Email\WeeklySendMarker;
use Psr\Log\LoggerInterface;

/**
 * Prunes raw and daily rows per the privacy retention settings.
 *
 * Raw rows are removed in whole UTC days only. Before raw hits or visits of a day are removed, a day
 * with a (store, is_simulated) slice that was never summarised (no crawl_daily / visit_daily rows for
 * that slice) is rolled up first; if that rollup fails, no raw rows of that day or any later day are
 * removed in this run. The raw floor (first fully retained day) is recorded before rows are deleted,
 * so later rollups never rebuild a day whose raw rows are gone.
 */
class Retention
{
    public const LANDING_TABLE = 'panth_aiv_landing';
    public const COUNTER_TABLE = 'panth_aiv_counter';
    public const RATE_LIMIT_TABLE = 'panth_aiv_rate_limit';
    public const CANDIDATE_TABLE = 'panth_aiv_candidate';

    /**
     * Discovery candidates that are not confirmed are removed when they were not seen for this many days
     */
    public const CANDIDATE_DAYS = 90;

    /**
     * Days of panth_aiv_counter rows kept (the raw cap only needs today)
     */
    public const COUNTER_DAYS = 3;

    /**
     * Counter rows that hold state rather than a daily count and are never pruned (the weekly email
     * marker; the raw floor rows are kept by their prefix)
     */
    public const KEEP_COUNTERS = [WeeklySendMarker::KEY];

    /**
     * Rows per DELETE ... LIMIT statement on the raw tables
     */
    public const DELETE_BATCH = 10000;

    /**
     * Safety stop for the batch loop
     */
    private const MAX_BATCHES = 100000;

    /**
     * @param ResourceConnection $resource
     * @param Config $config
     * @param RollupInterface $rollup
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly RollupInterface $rollup,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run all pruning steps
     *
     * @param int|null $now Unix time, null for now
     * @return array<string, int> Rows deleted per table, plus 'rolled_up' days
     */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $connection = $this->resource->getConnection();
        $result = [
            'rolled_up' => 0,
            Rollup::HIT_TABLE => 0,
            Rollup::VISIT_TABLE => 0,
            self::LANDING_TABLE => 0,
            Rollup::CRAWL_DAILY_TABLE => 0,
            Rollup::PATH_DAILY_TABLE => 0,
            Rollup::ENTITY_DAILY_TABLE => 0,
            Rollup::VISIT_DAILY_TABLE => 0,
            Rollup::VISIT_PATH_DAILY_TABLE => 0,
            Rollup::OVERFLOW_TABLE => 0,
            self::RATE_LIMIT_TABLE => 0,
            self::COUNTER_TABLE => 0,
            self::CANDIDATE_TABLE => 0,
        ];

        $hitCutoff = Rollup::cutoffDay($this->config->hitRetentionDays(), $now);
        $visitCutoff = Rollup::cutoffDay($this->config->rawVisitRetentionDays(), $now);
        try {
            [$rolled, $failedDay] = $this->rollupUnsummarisedDays($connection, $hitCutoff, $visitCutoff);
            $result['rolled_up'] = $rolled;
            if ($failedDay !== null) {
                $hitCutoff = min($hitCutoff, $failedDay);
                $visitCutoff = min($visitCutoff, $failedDay);
            }
            $this->rollup->raiseRawFloor(RollupInterface::KIND_HITS, $hitCutoff);
            $this->rollup->raiseRawFloor(RollupInterface::KIND_VISITS, $visitCutoff);
            $result[Rollup::HIT_TABLE] = $this->deleteRaw($connection, Rollup::HIT_TABLE, 'hit_id', $hitCutoff);
            $result[Rollup::VISIT_TABLE] = $this->deleteRaw(
                $connection,
                Rollup::VISIT_TABLE,
                'visit_id',
                $visitCutoff
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] raw hit/visit retention failed: ' . $e->getMessage());
        }

        $result[self::LANDING_TABLE] = $this->safely(fn (): int => $this->deleteRaw(
            $connection,
            self::LANDING_TABLE,
            'landing_id',
            Rollup::cutoffDay($this->config->landingRetentionDays(), $now)
        ));

        $monthsCutoff = self::monthsCutoffDay($this->config->dailyRetentionMonths(), $now);
        foreach ([Rollup::CRAWL_DAILY_TABLE, Rollup::PATH_DAILY_TABLE, Rollup::ENTITY_DAILY_TABLE,
                     Rollup::VISIT_DAILY_TABLE, Rollup::VISIT_PATH_DAILY_TABLE, Rollup::OVERFLOW_TABLE] as $table) {
            $result[$table] = $this->safely(fn (): int => (int)$connection->delete(
                $this->resource->getTableName($table),
                ['day < ?' => $monthsCutoff]
            ));
        }

        $result[self::RATE_LIMIT_TABLE] = $this->safely(fn (): int => (int)$connection->delete(
            $this->resource->getTableName(self::RATE_LIMIT_TABLE),
            ['expires_at < ?' => $now]
        ));
        $result[self::COUNTER_TABLE] = $this->safely(fn (): int => (int)$connection->delete(
            $this->resource->getTableName(self::COUNTER_TABLE),
            [
                'day < ?' => Rollup::cutoffDay(self::COUNTER_DAYS, $now),
                // The raw floor rows and state rows must survive even when nothing refreshed them for days.
                'counter_key NOT LIKE ?' => Rollup::FLOOR_KEY_PREFIX . '%',
                'counter_key NOT IN (?)' => self::KEEP_COUNTERS,
            ]
        ));
        // Confirmed candidates stay: their rules point at them.
        $result[self::CANDIDATE_TABLE] = $this->safely(fn (): int => (int)$connection->delete(
            $this->resource->getTableName(self::CANDIDATE_TABLE),
            [
                'status <> ?' => 'confirmed',
                'last_seen < ?' => Rollup::cutoffDay(self::CANDIDATE_DAYS, $now) . ' 00:00:00',
            ]
        ));
        return $result;
    }

    /**
     * Day N calendar months before $now (UTC); the day of month is clamped to the target month
     *
     * @param int $months
     * @param int $now
     * @return string 'Y-m-d'
     */
    public static function monthsCutoffDay(int $months, int $now): string
    {
        $year = (int)gmdate('Y', $now);
        $month = (int)gmdate('n', $now) - max(0, $months);
        $day = (int)gmdate('j', $now);
        while ($month < 1) {
            $month += 12;
            $year--;
        }
        $lastDay = (int)gmdate('t', gmmktime(0, 0, 0, $month, 1, $year));
        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $lastDay));
    }

    /**
     * Roll up raw days about to be removed that have no summary rows yet
     *
     * @param AdapterInterface $connection
     * @param string $hitCutoff
     * @param string $visitCutoff
     * @return array{0: int, 1: ?string} Days rolled up, earliest day whose rollup failed
     */
    private function rollupUnsummarisedDays(AdapterInterface $connection, string $hitCutoff, string $visitCutoff): array
    {
        $days = array_merge(
            $this->unsummarisedDays($connection, Rollup::HIT_TABLE, Rollup::CRAWL_DAILY_TABLE, $hitCutoff),
            $this->unsummarisedDays($connection, Rollup::VISIT_TABLE, Rollup::VISIT_DAILY_TABLE, $visitCutoff)
        );
        $days = array_values(array_unique($days));
        sort($days);

        $rolled = 0;
        $failedDay = null;
        foreach ($days as $day) {
            try {
                $this->rollup->rollupDay($day);
                $rolled++;
            } catch (\Throwable $e) {
                $failedDay ??= $day;
                $this->logger->warning(sprintf(
                    '[Panth_AiVisibility] rollup of %s before retention failed, its raw rows are kept: %s',
                    $day,
                    $e->getMessage()
                ));
            }
        }
        return [$rolled, $failedDay];
    }

    /**
     * Days before the cutoff with a (store, is_simulated) slice that has raw rows but no daily rows
     *
     * @param AdapterInterface $connection
     * @param string $rawTable
     * @param string $dailyTable
     * @param string $cutoffDay
     * @return string[]
     */
    private function unsummarisedDays(
        AdapterInterface $connection,
        string $rawTable,
        string $dailyTable,
        string $cutoffDay
    ): array {
        $rawSlices = [];
        $rows = $connection->fetchAll(
            'SELECT DATE(created_at) AS day, store_id, is_simulated FROM '
            . $connection->quoteIdentifier($this->resource->getTableName($rawTable))
            . ' WHERE created_at < ? GROUP BY DATE(created_at), store_id, is_simulated',
            [$cutoffDay . ' 00:00:00']
        );
        foreach ($rows as $row) {
            $rawSlices[self::sliceKey($row)] = substr((string)$row['day'], 0, 10);
        }
        if ($rawSlices === []) {
            return [];
        }
        $rawDays = array_values(array_unique($rawSlices));
        $summarised = [];
        foreach (array_chunk($rawDays, Rollup::LOOKUP_CHUNK) as $chunk) {
            $found = $connection->fetchAll(
                'SELECT day, store_id, is_simulated FROM '
                . $connection->quoteIdentifier($this->resource->getTableName($dailyTable))
                . ' WHERE day IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')'
                . ' GROUP BY day, store_id, is_simulated',
                $chunk
            );
            foreach ($found as $row) {
                $summarised[self::sliceKey($row)] = true;
            }
        }
        $days = [];
        foreach ($rawSlices as $key => $day) {
            if (!isset($summarised[$key])) {
                $days[$day] = $day;
            }
        }
        return array_values($days);
    }

    /**
     * "day|store|simulated" key of a grouped row
     *
     * @param array $row
     * @return string
     */
    private static function sliceKey(array $row): string
    {
        return substr((string)$row['day'], 0, 10) . '|' . (int)$row['store_id'] . '|' . (int)$row['is_simulated'];
    }

    /**
     * Delete raw rows created before a UTC day, in batches
     *
     * @param AdapterInterface $connection
     * @param string $table
     * @param string $idColumn
     * @param string $cutoffDay
     * @return int
     */
    private function deleteRaw(AdapterInterface $connection, string $table, string $idColumn, string $cutoffDay): int
    {
        $sql = 'DELETE FROM ' . $connection->quoteIdentifier($this->resource->getTableName($table))
            . ' WHERE created_at < ? ORDER BY created_at, ' . $connection->quoteIdentifier($idColumn)
            . ' LIMIT ' . self::DELETE_BATCH;
        $total = 0;
        for ($i = 0; $i < self::MAX_BATCHES; $i++) {
            $deleted = (int)$connection->query($sql, [$cutoffDay . ' 00:00:00'])->rowCount();
            $total += $deleted;
            if ($deleted < self::DELETE_BATCH) {
                break;
            }
        }
        return $total;
    }

    /**
     * Run one pruning step; a failure is logged and counts as 0 rows
     *
     * @param callable $step
     * @return int
     */
    private function safely(callable $step): int
    {
        try {
            return (int)$step();
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] retention step failed: ' . $e->getMessage());
            return 0;
        }
    }
}
