<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

/**
 * Per-subject metric values over a UTC day range, read from the daily and raw tables.
 *
 * Every value is a numerator / denominator pair. Count metrics use den = 1.0, so the count is num.
 * Ratio metrics (RATIO_METRICS) carry the real denominator, e.g. ai_conversion = orders / visits.
 * The number of underlying events is den for ratio metrics and num for count metrics.
 */
interface MetricProviderInterface
{
    public const METRIC_BOT_HITS = 'bot_hits';
    public const METRIC_SEARCH_BOT_HITS = 'search_bot_hits';
    public const METRIC_LIVE_FETCHES = 'live_fetches';
    public const METRIC_BOT_404 = 'bot_404';
    public const METRIC_BOT_5XX = 'bot_5xx';
    public const METRIC_BOT_AVG_MS = 'bot_avg_ms';
    public const METRIC_AI_VISITS = 'ai_visits';
    public const METRIC_AI_ORDERS = 'ai_orders';
    public const METRIC_AI_CONVERSION = 'ai_conversion';
    public const METRIC_CANONICAL_CRAWL_SHARE = 'canonical_crawl_share';
    public const METRIC_LLMS_FETCHES = 'llms_fetches';
    public const METRIC_CANDIDATE_BACKLOG = 'candidate_backlog';

    public const METRICS = [
        self::METRIC_BOT_HITS,
        self::METRIC_SEARCH_BOT_HITS,
        self::METRIC_LIVE_FETCHES,
        self::METRIC_BOT_404,
        self::METRIC_BOT_5XX,
        self::METRIC_BOT_AVG_MS,
        self::METRIC_AI_VISITS,
        self::METRIC_AI_ORDERS,
        self::METRIC_AI_CONVERSION,
        self::METRIC_CANONICAL_CRAWL_SHARE,
        self::METRIC_LLMS_FETCHES,
        self::METRIC_CANDIDATE_BACKLOG,
    ];

    /**
     * Metrics where a lower value is the improvement
     */
    public const LESS_IS_BETTER = [
        self::METRIC_BOT_404,
        self::METRIC_BOT_5XX,
        self::METRIC_BOT_AVG_MS,
        self::METRIC_CANDIDATE_BACKLOG,
    ];

    /**
     * Metrics whose value is num / den (all others are counts with den = 1.0)
     */
    public const RATIO_METRICS = [
        self::METRIC_BOT_AVG_MS,
        self::METRIC_AI_CONVERSION,
        self::METRIC_CANONICAL_CRAWL_SHARE,
    ];

    /**
     * Metric values per subject for the inclusive UTC day range [fromDay, toDay]
     *
     * Every requested subject is present in the result; subjects without data get num = 0.0 (and
     * den = 1.0 for counts, 0.0 for ratios). Subject refs are the strings Finding::$subjects uses
     * ('site', path, product id, bot code, source code).
     *
     * @param string $metric One of METRICS
     * @param int $storeId Store view ID (0 = all store views)
     * @param string[] $subjects Subject refs
     * @param string $fromDay 'Y-m-d' (UTC)
     * @param string $toDay 'Y-m-d' (UTC)
     * @param bool $simulated Read simulated rows (is_simulated = 1) instead of real rows
     * @return array<string, array{num: float, den: float}>
     * @throws \InvalidArgumentException unsupported metric or invalid day
     */
    public function value(
        string $metric,
        int $storeId,
        array $subjects,
        string $fromDay,
        string $toDay,
        bool $simulated = false
    ): array;

    /**
     * Whether the provider can compute the metric
     *
     * @param string $metric
     * @return bool
     */
    public function supports(string $metric): bool;
}
