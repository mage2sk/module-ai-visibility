<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation\Rule;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Api\RecommendationRuleInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\CrawlFacts;
use Panth\AiVisibility\Service\Report\BotDirectory;

/**
 * Rule R3 (bot_errors_or_slow): Pages fail or respond slowly for AI bots.
 *
 * Last 7 complete UTC days of raw AI bot hits, up to two findings per store view:
 * - server errors (5xx) once their count reaches recommendations/min_volume (metric bot_5xx);
 * - slow pages: paths with at least MIN_PATH_HITS timed hits averaging over SLOW_MS, once the hits on
 *   those paths reach recommendations/min_volume (metric bot_avg_ms).
 * Subjects are the affected paths (most hits first).
 */
class BotErrorsOrSlow implements RecommendationRuleInterface
{
    public const CODE = 'bot_errors_or_slow';
    public const SUBJECT_REF_ERRORS = 'paths:5xx';
    public const SUBJECT_REF_SLOW = 'paths:slow';
    public const WINDOW_DAYS = 7;
    public const SLOW_MS = 2000;
    public const MIN_PATH_HITS = 3;
    public const SUBJECT_LIMIT = 50;
    public const EXAMPLE_LIMIT = 10;

    /**
     * @param CrawlFacts $facts
     * @param BotDirectory $botDirectory
     * @param Config $config
     */
    public function __construct(
        private readonly CrawlFacts $facts,
        private readonly BotDirectory $botDirectory,
        private readonly Config $config
    ) {
    }

    /**
     * @inheritdoc
     */
    public function code(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    public function label(): string
    {
        return (string)__('Pages fail or respond slowly for AI bots');
    }

    /**
     * @inheritdoc
     */
    public function metric(): string
    {
        return MetricProviderInterface::METRIC_BOT_5XX;
    }

    /**
     * @inheritdoc
     */
    public function evaluate(int $storeId, \DateTimeImmutable $today): array
    {
        $end = $today->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $start = $end->modify('-' . self::WINDOW_DAYS . ' days');
        $from = $start->format('Y-m-d H:i:s');
        $to = $end->format('Y-m-d H:i:s');
        $window = ['from' => $start->format('Y-m-d'), 'to' => $end->modify('-1 day')->format('Y-m-d')];
        $minVolume = $this->config->recommendationsMinVolume();

        $findings = [];
        $errors = $this->facts->hitSummary($storeId, $from, $to, 500, 599);
        $allHits = null;
        if ($errors['hits'] > 0 && $errors['hits'] >= $minVolume) {
            $allHits = $this->facts->hitCount($storeId, $from, $to);
            $findings[] = $this->errorFinding($storeId, $from, $to, $window, $errors, $allHits);
        }

        $slow = $this->facts->slowPaths($storeId, $from, $to, self::SLOW_MS, self::MIN_PATH_HITS, self::SUBJECT_LIMIT);
        if ($slow['hits'] > 0 && $slow['hits'] >= $minVolume) {
            $allHits ??= $this->facts->hitCount($storeId, $from, $to);
            $findings[] = $this->slowFinding($storeId, $window, $slow, $allHits);
        }
        return $findings;
    }

    /**
     * Finding for server errors
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @param array $window
     * @param array{hits: int, paths: int, bots: int} $errors
     * @param int $allHits
     * @return Finding
     */
    private function errorFinding(int $storeId, string $from, string $to, array $window, array $errors, int $allHits): Finding
    {
        $paths = $this->facts->topHitPaths($storeId, $from, $to, 500, 599, self::SUBJECT_LIMIT);
        $topBots = [];
        foreach ($this->facts->topHitBots($storeId, $from, $to, 500, 599, 5) as $code => $hits) {
            $topBots[] = ['code' => $code, 'name' => $this->botDirectory->describe($code)['name'], 'hits' => $hits];
        }
        $examples = [];
        foreach (array_slice($paths, 0, self::EXAMPLE_LIMIT) as $row) {
            $examples[] = [
                'path' => $row['path'],
                'errors' => $row['hits'],
                'status' => $row['max_status'],
                'avg_ms' => $row['avg_ms'],
                'last_seen' => $row['last_seen'],
            ];
        }
        return new Finding(
            self::CODE,
            $storeId,
            Finding::SUBJECT_PATH,
            self::SUBJECT_REF_ERRORS,
            (string)__(
                'AI bots got %1 server errors (5xx) on %2 pages in the last 7 days',
                number_format($errors['hits']),
                number_format($errors['paths'])
            ),
            [
                'window' => $window,
                'errors_5xx' => $errors['hits'],
                'paths' => $errors['paths'],
                'bots' => $errors['bots'],
                'all_bot_hits' => $allHits,
                'share_pct' => $allHits > 0 ? round($errors['hits'] * 100 / $allHits, 1) : 0.0,
                'top_paths' => $examples,
                'top_bots' => $topBots,
                'how_to_fix' => (string)__(
                    'Open these URLs and check var/log and the web server error log for the failing requests. '
                    . 'Bots that keep getting errors crawl less and may drop the pages from AI answers.'
                ),
            ],
            $errors['hits'],
            0,
            MetricProviderInterface::METRIC_BOT_5XX,
            array_column($paths, 'path')
        );
    }

    /**
     * Finding for slow pages
     *
     * @param int $storeId
     * @param array $window
     * @param array $slow
     * @param int $allHits
     * @return Finding
     */
    private function slowFinding(int $storeId, array $window, array $slow, int $allHits): Finding
    {
        $examples = [];
        foreach (array_slice($slow['rows'], 0, self::EXAMPLE_LIMIT) as $row) {
            $examples[] = [
                'path' => $row['path'],
                'hits' => $row['hits'],
                'avg_ms' => $row['avg_ms'],
                'max_ms' => $row['max_ms'],
            ];
        }
        return new Finding(
            self::CODE,
            $storeId,
            Finding::SUBJECT_PATH,
            self::SUBJECT_REF_SLOW,
            (string)__(
                '%1 pages took over %2 seconds on average to answer AI bots (%3 requests, last 7 days)',
                number_format($slow['paths']),
                number_format(self::SLOW_MS / 1000),
                number_format($slow['hits'])
            ),
            [
                'window' => $window,
                'slow_paths' => $slow['paths'],
                'slow_hits' => $slow['hits'],
                'threshold_ms' => self::SLOW_MS,
                'min_hits_per_path' => self::MIN_PATH_HITS,
                'all_bot_hits' => $allHits,
                'share_pct' => $allHits > 0 ? round($slow['hits'] * 100 / $allHits, 1) : 0.0,
                'top_paths' => $examples,
                'how_to_fix' => (string)__(
                    'Make sure these pages are served from the full-page cache (check for uncacheable blocks), '
                    . 'and profile slow product or category pages. AI bots often give up on slow answers.'
                ),
            ],
            $slow['hits'],
            0,
            MetricProviderInterface::METRIC_BOT_AVG_MS,
            array_column($slow['rows'], 'path')
        );
    }
}
