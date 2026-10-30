<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

use Panth\AiVisibility\Model\BotMatch;

/**
 * Writes raw crawler hits.
 *
 * Both methods enforce bots/raw_daily_cap through panth_aiv_counter (key "raw_hits" per UTC day);
 * above the cap they upsert panth_aiv_overflow_daily instead of writing a raw row.
 */
interface HitWriterInterface
{
    /**
     * Raw code prefix of discovery candidates ("cand:<candidate_id>"), shared by every class
     */
    public const CANDIDATE_PREFIX = 'cand:';

    public const ORIGIN_PHP = 'php';
    public const ORIGIN_LOG = 'log';
    public const ORIGIN_SIM = 'sim';

    /**
     * Record a hit by a known AI bot
     *
     * @param int $storeId
     * @param BotMatch $bot
     * @param string $path URL path without query (max 512 characters)
     * @param bool $hasQuery
     * @param int $status HTTP status, 0 when unknown
     * @param int $durationMs
     * @param string $origin php | log | sim
     * @param int|null $timestamp Unix time of the request, null for now
     * @return void
     */
    public function recordHit(
        int $storeId,
        BotMatch $bot,
        string $path,
        bool $hasQuery,
        int $status,
        int $durationMs,
        string $origin = 'php',
        ?int $timestamp = null
    ): void;

    /**
     * Record a hit by a discovery candidate (unknown bot-like User-Agent)
     *
     * @param int $storeId
     * @param string $userAgent
     * @param string $path URL path without query (max 512 characters)
     * @param bool $hasQuery
     * @param int $status HTTP status, 0 when unknown
     * @param int $durationMs
     * @param string $origin php | log | sim
     * @param int|null $timestamp Unix time of the request, null for now
     * @return void
     */
    public function recordCandidateHit(
        int $storeId,
        string $userAgent,
        string $path,
        bool $hasQuery,
        int $status,
        int $durationMs,
        string $origin = 'php',
        ?int $timestamp = null
    ): void;
}
