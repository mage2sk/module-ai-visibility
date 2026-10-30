<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation\Rule;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\GuardedRule;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\RuleSupport;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Rollup;
use Psr\Log\LoggerInterface;

/**
 * Rule R8 (parameter_crawl_waste): more than 30% of AI crawler requests in the last 7 complete UTC days
 * (today left out, like R2 and R3) went to URLs with a query string (layered navigation filters,
 * sorting, tracking parameters).
 *
 * Reads panth_aiv_hit (has_query is only kept on raw hits; 7 days fit the default 14-day retention),
 * known bot categories only. One site finding per store view (verified with canonical_crawl_share).
 */
class ParameterCrawlWaste extends GuardedRule
{
    public const CODE = 'parameter_crawl_waste';

    /**
     * Look-back window on raw hits (complete UTC days, ending yesterday)
     */
    public const WINDOW_DAYS = 7;

    /**
     * Share of requests with a query string (percent) above which the rule fires
     */
    public const MAX_QUERY_PERCENT = 30;

    private const WEIGHT = 2.0;

    /**
     * @param RuleSupport $support
     * @param LoggerInterface $logger
     * @param BotDirectory $botDirectory
     */
    public function __construct(
        RuleSupport $support,
        LoggerInterface $logger,
        private readonly BotDirectory $botDirectory
    ) {
        parent::__construct($support, $logger);
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
        return (string)__('AI crawl wasted on URLs with parameters');
    }

    /**
     * @inheritdoc
     */
    public function metric(): string
    {
        return MetricProviderInterface::METRIC_CANONICAL_CRAWL_SHARE;
    }

    /**
     * @inheritdoc
     */
    protected function find(int $storeId, \DateTimeImmutable $today): array
    {
        $yesterday = $today->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0)->modify('-1 day');
        $from = RuleSupport::fromDay($yesterday, self::WINDOW_DAYS);
        $to = RuleSupport::toDay($yesterday);
        [$where, $bind] = $this->where($storeId, $from, $to);
        $connection = $this->support->connection();
        $hitTable = $this->support->table(Rollup::HIT_TABLE);

        $row = $connection->fetchRow(
            'SELECT COUNT(*) AS total, COALESCE(SUM(h.has_query), 0) AS with_query FROM ' . $hitTable . ' AS h'
            . $where,
            $bind
        ) ?: [];
        $total = (int)($row['total'] ?? 0);
        $withQuery = (int)($row['with_query'] ?? 0);
        if ($total < $this->support->minVolume() || $withQuery * 100 <= $total * self::MAX_QUERY_PERCENT) {
            return [];
        }
        $share = $withQuery / $total;

        $paths = $connection->fetchAll(
            'SELECT h.path_hash, MIN(h.path) AS path, COUNT(*) AS hits FROM ' . $hitTable . ' AS h' . $where
            . ' AND h.has_query = 1 GROUP BY h.path_hash ORDER BY hits DESC, h.path_hash ASC LIMIT '
            . RuleSupport::TOP_EXAMPLES,
            $bind
        );
        $bots = $connection->fetchPairs(
            'SELECT h.bot_code, COUNT(*) AS hits FROM ' . $hitTable . ' AS h' . $where
            . ' AND h.has_query = 1 GROUP BY h.bot_code ORDER BY hits DESC, h.bot_code ASC LIMIT '
            . RuleSupport::TOP_EXAMPLES,
            $bind
        );
        $evidence = [
            'window_days' => self::WINDOW_DAYS,
            'from' => $from,
            'to' => $to,
            'ai_crawler_hits' => $total,
            'hits_with_parameters' => $withQuery,
            'share_percent' => round($share * 100, 1),
            'threshold_percent' => self::MAX_QUERY_PERCENT,
            'examples' => [],
            'bots' => [],
        ];
        foreach ($paths as $path) {
            $evidence['examples'][] = ['path' => RuleSupport::clip((string)$path['path']), 'hits' => (int)$path['hits']];
        }
        foreach ($bots as $code => $hits) {
            $evidence['bots'][] = ['bot' => $this->botDirectory->describe((string)$code)['name'], 'hits' => (int)$hits];
        }
        $title = __(
            '%1% of AI crawler requests go to URLs with parameters (filters, sorting) instead of clean pages',
            (int)round($share * 100)
        );
        return [new Finding(
            self::CODE,
            $storeId,
            Finding::SUBJECT_SITE,
            Finding::SUBJECT_SITE,
            (string)$title,
            $evidence,
            $withQuery,
            RuleSupport::impactScore(self::WEIGHT, $withQuery),
            $this->metric(),
            [Finding::SUBJECT_SITE]
        )];
    }

    /**
     * WHERE clause (window, real rows, known bot categories, store) and its bind values
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @return array{0: string, 1: array}
     */
    private function where(int $storeId, string $from, string $to): array
    {
        $categories = Category::BOT_CATEGORIES;
        $bind = array_merge([RuleSupport::startOf($from), RuleSupport::endExclusive($to)], $categories);
        $where = ' WHERE h.created_at >= ? AND h.created_at < ?'
            . ' AND h.category IN (' . RuleSupport::placeholders(count($categories)) . ') AND ' . $this->support->simulatedCondition('h.is_simulated')
            . RuleSupport::storeSql('h.store_id', $storeId, $bind);
        return [$where, $bind];
    }
}
