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
use Panth\AiVisibility\Service\Report\CatalogLookup;
use Panth\AiVisibility\Service\Report\ReportRepository;
use Panth\AiVisibility\Service\Rollup;
use Panth\AiVisibility\Service\VisitWriter;
use Psr\Log\LoggerInterface;

/**
 * Rule R5 (live_answers_no_sales): pages that AI assistants fetch live while answering, that get
 * AI visitors, but brought no AI-attributed order in the last 30 days.
 *
 * Visits come from panth_aiv_visit (known sources only) and orders from panth_aiv_order (landing path,
 * not canceled), matched by the path hash. Live fetches (bot category live):
 * - product and category pages (landing path resolved in panth_aiv_visit_path_daily): from
 *   panth_aiv_entity_daily, which counts every product and category page without a cap, so the long tail
 *   counts on stores with more than Rollup::PATHS_PER_GROUP live-fetched pages a day. A page's fetches are
 *   those of its entity (every URL of the product or category);
 * - other pages (CMS, home, search, ...): from panth_aiv_path_daily, which keeps only the top
 *   Rollup::PATHS_PER_GROUP paths per day and category (plus paths with repeated errors or slow answers),
 *   so a rarely fetched page of these types can be missed on very large stores.
 * One finding per store view; its subjects are the paths (verified with ai_conversion).
 */
class LiveAnswersNoSales extends GuardedRule
{
    public const CODE = 'live_answers_no_sales';

    /**
     * A page needs at least this many live fetches and this many AI visits in the window
     */
    public const MIN_PER_PATH = 5;

    private const WEIGHT = 3.0;
    private const STATE_CANCELED = 'canceled';
    private const ENTITY_PRODUCT = 'product';
    private const ENTITY_CATEGORY = 'category';

    /**
     * @param RuleSupport $support
     * @param LoggerInterface $logger
     * @param CatalogLookup $catalogLookup
     */
    public function __construct(
        RuleSupport $support,
        LoggerInterface $logger,
        private readonly CatalogLookup $catalogLookup
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
        return (string)__('AI answers send visitors but no sales');
    }

    /**
     * @inheritdoc
     */
    public function metric(): string
    {
        return MetricProviderInterface::METRIC_AI_CONVERSION;
    }

    /**
     * @inheritdoc
     */
    protected function find(int $storeId, \DateTimeImmutable $today): array
    {
        $from = RuleSupport::fromDay($today, RuleSupport::WINDOW_DAYS);
        $to = RuleSupport::toDay($today);
        $flagged = $this->qualifyingPages($storeId, $from, $to);
        $totalVisits = (int)array_sum(array_column($flagged, 'visits'));
        if ($flagged === [] || $totalVisits < $this->support->minVolume()) {
            return [];
        }
        uasort($flagged, static function (array $a, array $b): int {
            return [$b['visits'], $b['fetches'], $a['path']] <=> [$a['visits'], $a['fetches'], $b['path']];
        });
        $flagged = array_slice($flagged, 0, RuleSupport::MAX_SUBJECTS, true);
        $totalFetches = (int)array_sum(array_column($flagged, 'fetches'));
        $examples = array_slice($flagged, 0, RuleSupport::TOP_EXAMPLES, true);
        $names = $this->catalogLookup->entityNames(array_values($examples), $storeId);

        $evidence = [
            'window_days' => RuleSupport::WINDOW_DAYS,
            'from' => $from,
            'to' => $to,
            'pages' => count($flagged),
            'live_fetches' => $totalFetches,
            'ai_visits' => $totalVisits,
            'ai_orders' => 0,
            'min_per_page' => self::MIN_PER_PATH,
            'examples' => [],
        ];
        foreach ($examples as $page) {
            $key = (string)$page['entity_type'] . ':' . (int)$page['entity_id'];
            $evidence['examples'][] = [
                'path' => RuleSupport::clip($page['path']),
                'name' => $names[$key] ?? null,
                'live_fetches' => $page['fetches'],
                'ai_visits' => $page['visits'],
                'ai_orders' => 0,
            ];
        }
        $count = count($flagged);
        $title = $count === 1
            ? __('1 page is read by AI assistants and gets AI visitors, but sold nothing in 30 days')
            : __('%1 pages are read by AI assistants and get AI visitors, but sold nothing in 30 days', $count);

        return [new Finding(
            self::CODE,
            $storeId,
            Finding::SUBJECT_PATH,
            'paths',
            (string)$title,
            $evidence,
            $totalVisits,
            RuleSupport::impactScore(self::WEIGHT, $totalVisits),
            $this->metric(),
            array_column($flagged, 'path')
        )];
    }

    /**
     * Pages with enough live fetches and AI visits but no AI order, most visited first: hash => page
     *
     * Each page needs MIN_PER_PATH live fetches and MIN_PER_PATH AI visits. One statement: visits and
     * orders are joined before the SCAN_LIMIT cut, so a qualifying page is never lost behind more-fetched
     * pages that have no visitors or did sell. Product and category pages read their live fetches from
     * panth_aiv_entity_daily (uncapped), other pages from panth_aiv_path_daily.
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @return array<string, array{path: string, entity_type: ?string, entity_id: ?int, fetches: int, visits: int}>
     */
    private function qualifyingPages(int $storeId, string $from, string $to): array
    {
        $bind = [];
        $entityPages = 'SELECT vs.path_hash AS path_hash, em.path AS path, em.entity_type AS entity_type,'
            . ' em.entity_id AS entity_id, SUM(e.hits) AS fetches, MAX(vs.visits) AS visits'
            . ' FROM (' . $this->visitsSql($storeId, $from, $to, $bind) . ') AS vs'
            . ' INNER JOIN (' . $this->entityMapSql($storeId, $from, $to, $bind) . ') AS em'
            . ' ON em.path_hash = vs.path_hash'
            . ' INNER JOIN ' . $this->support->table(Rollup::ENTITY_DAILY_TABLE) . ' AS e'
            . ' ON e.entity_type = em.entity_type AND e.entity_id = em.entity_id'
            . ' LEFT JOIN (' . $this->ordersSql($storeId, $from, $to, $bind) . ') AS os'
            . ' ON os.path_hash = vs.path_hash'
            . ' WHERE e.day >= ? AND e.day <= ? AND e.category = ?'
            . ' AND ' . $this->support->simulatedCondition('e.is_simulated');
        array_push($bind, $from, $to, Category::LIVE);
        $entityPages .= RuleSupport::storeSql('e.store_id', $storeId, $bind)
            . ' AND os.path_hash IS NULL'
            . ' GROUP BY vs.path_hash, em.path, em.entity_type, em.entity_id HAVING SUM(e.hits) >= ?';
        $bind[] = self::MIN_PER_PATH;

        $otherPages = 'SELECT p.path_hash AS path_hash, MIN(p.path) AS path, MAX(p.entity_type) AS entity_type,'
            . ' MAX(p.entity_id) AS entity_id, SUM(p.hits) AS fetches, MAX(vs.visits) AS visits'
            . ' FROM ' . $this->support->table(ReportRepository::PATH_TABLE) . ' AS p'
            . ' INNER JOIN (' . $this->visitsSql($storeId, $from, $to, $bind) . ') AS vs'
            . ' ON vs.path_hash = p.path_hash'
            . ' LEFT JOIN (' . $this->ordersSql($storeId, $from, $to, $bind) . ') AS os'
            . ' ON os.path_hash = p.path_hash'
            . ' LEFT JOIN (' . $this->entityMapSql($storeId, $from, $to, $bind) . ') AS ex'
            . ' ON ex.path_hash = p.path_hash'
            . ' WHERE p.day >= ? AND p.day <= ? AND p.category = ?'
            . ' AND ' . $this->support->simulatedCondition('p.is_simulated');
        array_push($bind, $from, $to, Category::LIVE);
        $otherPages .= RuleSupport::storeSql('p.store_id', $storeId, $bind)
            . ' AND os.path_hash IS NULL AND ex.path_hash IS NULL'
            . ' GROUP BY p.path_hash HAVING SUM(p.hits) >= ?';
        $bind[] = self::MIN_PER_PATH;

        $sql = 'SELECT u.path_hash, u.path, u.entity_type, u.entity_id, u.fetches, u.visits FROM ('
            . $entityPages . ' UNION ALL ' . $otherPages . ') AS u'
            . ' ORDER BY u.visits DESC, u.fetches DESC, u.path_hash ASC LIMIT ' . RuleSupport::SCAN_LIMIT;

        $pages = [];
        foreach ($this->support->connection()->fetchAll($sql, $bind) as $row) {
            $pages[(string)$row['path_hash']] = [
                'path' => (string)$row['path'],
                'entity_type' => $row['entity_type'] !== null ? (string)$row['entity_type'] : null,
                'entity_id' => $row['entity_id'] !== null ? (int)$row['entity_id'] : null,
                'fetches' => (int)$row['fetches'],
                'visits' => (int)$row['visits'],
            ];
        }
        return $pages;
    }

    /**
     * Landing paths with at least MIN_PER_PATH AI visits from known sources: path_hash, visits
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @param array $bind Bind list the values are appended to
     * @return string
     */
    private function visitsSql(int $storeId, string $from, string $to, array &$bind): string
    {
        array_push(
            $bind,
            RuleSupport::startOf($from),
            RuleSupport::endExclusive($to),
            VisitWriter::CANDIDATE_PREFIX . '%'
        );
        $sql = 'SELECT v.path_hash, COUNT(*) AS visits'
            . ' FROM ' . $this->support->table(VisitWriter::TABLE_VISIT) . ' AS v'
            . ' WHERE v.created_at >= ? AND v.created_at < ? AND v.source_code NOT LIKE ?'
            . ' AND ' . $this->support->simulatedCondition('v.is_simulated')
            . RuleSupport::storeSql('v.store_id', $storeId, $bind)
            . ' GROUP BY v.path_hash HAVING COUNT(*) >= ?';
        $bind[] = self::MIN_PER_PATH;
        return $sql;
    }

    /**
     * Landing path hashes of AI-attributed orders that were not canceled: path_hash
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @param array $bind Bind list the values are appended to
     * @return string
     */
    private function ordersSql(int $storeId, string $from, string $to, array &$bind): string
    {
        array_push($bind, RuleSupport::startOf($from), RuleSupport::endExclusive($to), self::STATE_CANCELED);
        return 'SELECT SHA1(o.landing_path) AS path_hash'
            . ' FROM ' . $this->support->table(ReportRepository::ORDER_TABLE) . ' AS o'
            . ' INNER JOIN ' . $this->support->table(ReportRepository::SALES_ORDER_TABLE) . ' AS so'
            . ' ON so.entity_id = o.order_id'
            . ' WHERE o.created_at >= ? AND o.created_at < ? AND o.landing_path IS NOT NULL'
            . ' AND so.state <> ? AND ' . $this->support->simulatedCondition('o.is_simulated')
            . RuleSupport::storeSql('so.store_id', $storeId, $bind)
            . ' GROUP BY SHA1(o.landing_path)';
    }

    /**
     * Landing paths resolved to a product or category page in the window: path_hash, path, entity
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @param array $bind Bind list the values are appended to
     * @return string
     */
    private function entityMapSql(int $storeId, string $from, string $to, array &$bind): string
    {
        array_push($bind, $from, $to, self::ENTITY_PRODUCT, self::ENTITY_CATEGORY);
        return 'SELECT vp.path_hash, MIN(vp.path) AS path, MAX(vp.entity_type) AS entity_type,'
            . ' MAX(vp.entity_id) AS entity_id'
            . ' FROM ' . $this->support->table(Rollup::VISIT_PATH_DAILY_TABLE) . ' AS vp'
            . ' WHERE vp.day >= ? AND vp.day <= ? AND vp.entity_type IN (?, ?) AND vp.entity_id IS NOT NULL'
            . ' AND ' . $this->support->simulatedCondition('vp.is_simulated')
            . RuleSupport::storeSql('vp.store_id', $storeId, $bind)
            . ' GROUP BY vp.path_hash';
    }
}
