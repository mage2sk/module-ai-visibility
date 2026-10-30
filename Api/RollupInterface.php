<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

/**
 * Rebuilds the daily summary tables from the raw tables.
 *
 * Raw rows are only complete for days on or after the "raw floor" of their table: the first day the
 * retention cron kept when it last deleted raw rows. Daily rows of older days can no longer be rebuilt
 * from raw rows, so they are never deleted by a rollup.
 */
interface RollupInterface
{
    /**
     * Raw hits (panth_aiv_hit), feeding panth_aiv_crawl_daily and panth_aiv_path_daily
     */
    public const KIND_HITS = 'hits';

    /**
     * Raw visits (panth_aiv_visit), feeding panth_aiv_visit_daily
     */
    public const KIND_VISITS = 'visits';

    /**
     * Idempotent recompute of the *_daily rows for one UTC day
     *
     * Works per (store, is_simulated) slice: a slice without raw rows is left alone, and a slice of a
     * day before the raw floor is only filled when it has no daily rows yet.
     *
     * @param string $day UTC day, 'Y-m-d'
     * @param int|null $storeId Limit to one store view, null for all
     * @return void
     */
    public function rollupDay(string $day, ?int $storeId = null): void;

    /**
     * Whether every raw row of the day is still present (day on or after the raw floor)
     *
     * @param string $day UTC day, 'Y-m-d'
     * @param string $kind self::KIND_HITS or self::KIND_VISITS
     * @return bool
     */
    public function isRawComplete(string $day, string $kind): bool;

    /**
     * Record that raw rows before $day are about to be deleted (the floor never moves back)
     *
     * @param string $kind self::KIND_HITS or self::KIND_VISITS
     * @param string $day UTC day, 'Y-m-d'
     * @return void
     */
    public function raiseRawFloor(string $kind, string $day): void;
}
