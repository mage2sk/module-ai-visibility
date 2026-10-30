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
use Panth\AiVisibility\Service\Recommendation\RedirectGuesser;
use Panth\AiVisibility\Service\Report\BotDirectory;

/**
 * Rule R2 (bot_404): Pages answer client errors (4xx, mostly 404) to AI bots.
 *
 * Looks at the last 7 complete UTC days of raw AI bot hits. Counts every 4xx answer (404 Not Found,
 * 410 Gone, 403, ...), the same statuses as its verification metric bot_404 (path_daily s4xx), so a
 * fix that answers 410 for a dead URL is not verified as "no effect". When the 4xx answers reach
 * recommendations/min_volume, one finding per store view lists the top paths (the subjects of a fix),
 * the bots that hit them and, for the top paths, a redirect target guessed from url_rewrite.
 * robots.txt, llms.txt and /.well-known/ requests are left out (other rules cover them).
 */
class Bot404 implements RecommendationRuleInterface
{
    public const CODE = 'bot_404';
    public const SUBJECT_REF = 'paths:404';
    public const WINDOW_DAYS = 7;
    public const SUBJECT_LIMIT = 50;
    public const GUESS_LIMIT = 10;
    public const EXAMPLE_LIMIT = 10;

    public const STATUS_MIN = 400;
    public const STATUS_MAX = 499;

    public const EXCLUDED_PATHS = ['/robots.txt', '/llms.txt', '/llms-full.txt'];
    public const EXCLUDED_PREFIXES = ['/.well-known/'];

    /**
     * @param CrawlFacts $facts
     * @param RedirectGuesser $redirectGuesser
     * @param BotDirectory $botDirectory
     * @param Config $config
     */
    public function __construct(
        private readonly CrawlFacts $facts,
        private readonly RedirectGuesser $redirectGuesser,
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
        return (string)__('Pages answer client errors (4xx) to AI bots');
    }

    /**
     * @inheritdoc
     */
    public function metric(): string
    {
        return MetricProviderInterface::METRIC_BOT_404;
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

        $summary = $this->facts->hitSummary(
            $storeId,
            $from,
            $to,
            self::STATUS_MIN,
            self::STATUS_MAX,
            self::EXCLUDED_PATHS,
            self::EXCLUDED_PREFIXES
        );
        if ($summary['hits'] <= 0 || $summary['hits'] < $this->config->recommendationsMinVolume()) {
            return [];
        }
        $allHits = $this->facts->hitCount($storeId, $from, $to);
        $paths = $this->facts->topHitPaths(
            $storeId,
            $from,
            $to,
            self::STATUS_MIN,
            self::STATUS_MAX,
            self::SUBJECT_LIMIT,
            self::EXCLUDED_PATHS,
            self::EXCLUDED_PREFIXES
        );
        $bots = $this->facts->topHitBots(
            $storeId,
            $from,
            $to,
            self::STATUS_MIN,
            self::STATUS_MAX,
            5,
            self::EXCLUDED_PATHS,
            self::EXCLUDED_PREFIXES
        );

        $examples = [];
        $guessed = 0;
        foreach (array_slice($paths, 0, self::EXAMPLE_LIMIT) as $i => $row) {
            $guess = $i < self::GUESS_LIMIT ? $this->redirectGuesser->guess($storeId, $row['path']) : null;
            $guessed += $guess === null ? 0 : 1;
            $examples[] = [
                'path' => $row['path'],
                'hits' => $row['hits'],
                'bots' => $row['bots'],
                'last_seen' => $row['last_seen'],
                'redirect_to' => $guess,
            ];
        }
        $topBots = [];
        foreach ($bots as $code => $hits) {
            $topBots[] = ['code' => $code, 'name' => $this->botDirectory->describe($code)['name'], 'hits' => $hits];
        }

        $title = (string)__(
            'AI bots got %1 client errors (4xx, such as 404 Not Found) on %2 pages in the last 7 days',
            number_format($summary['hits']),
            number_format($summary['paths'])
        );
        $evidence = [
            'window' => ['from' => $start->format('Y-m-d'), 'to' => $end->modify('-1 day')->format('Y-m-d')],
            'hits_4xx' => $summary['hits'],
            'paths_4xx' => $summary['paths'],
            'bots' => $summary['bots'],
            'all_bot_hits' => $allHits,
            'share_pct' => $allHits > 0 ? round($summary['hits'] * 100 / $allHits, 1) : 0.0,
            'top_paths' => $examples,
            'redirect_guesses' => $guessed,
            'top_bots' => $topBots,
            'how_to_fix' => (string)__(
                'Add a 301 redirect from each dead URL to the closest live page (a guess is shown where one '
                . 'was found), or restore the page. Fix internal links, sitemap and llms.txt entries that '
                . 'still point to these URLs. A URL that is gone for good may answer 410 Gone instead. If bots '
                . 'get 403 or 429, check the firewall and rate-limit rules for AI bots.'
            ),
        ];
        return [new Finding(
            self::CODE,
            $storeId,
            Finding::SUBJECT_PATH,
            self::SUBJECT_REF,
            $title,
            $evidence,
            $summary['hits'],
            0,
            $this->metric(),
            array_column($paths, 'path')
        )];
    }
}
