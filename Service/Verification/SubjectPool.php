<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Verification;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Model\Source\Category;

/**
 * Candidate control subjects: the subjects of one type and store view with the most of the metric in a
 * day range (bounded by a limit).
 *
 * Returns plain refs in the form actions store them (paths, entity IDs, bot / source codes); the
 * ControlMatcher then picks the closest ones. Crawl counts of products and categories come from the
 * uncapped daily entity counts (bot categories of the metric); error and speed metrics only offer subjects
 * that had errors / timed hits in the range (panth_aiv_path_daily), so error metrics get controls even
 * when the erroring paths are not among the busiest; visit metrics read panth_aiv_visit_path_daily.
 */
class SubjectPool
{
    public const DEFAULT_LIMIT = 500;

    /**
     * panth_aiv_path_daily column of the error / speed metrics: candidates must have that metric
     */
    private const STATUS_COLUMNS = [
        MetricProvider::METRIC_BOT_404 => 's4xx',
        MetricProvider::METRIC_BOT_5XX => 's5xx',
        MetricProvider::METRIC_BOT_AVG_MS => 'timed_hits',
    ];

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Subjects with the most of the metric first
     *
     * @param string $metric
     * @param string $subjectType Finding::SUBJECT_* (not site)
     * @param int $storeId 0 = all store views
     * @param string $fromDay
     * @param string $toDay
     * @param bool $simulated
     * @param int $limit
     * @return string[]
     */
    public function candidates(
        string $metric,
        string $subjectType,
        int $storeId,
        string $fromDay,
        string $toDay,
        bool $simulated,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $sources = MetricProvider::sources($metric, $subjectType);
        if ($sources === [] || $subjectType === Finding::SUBJECT_SITE || $limit < 1) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $daily = [$fromDay, $toDay];
        $raw = [$fromDay . ' 00:00:00', MetricProvider::nextDay($toDay) . ' 00:00:00'];
        $statusColumn = self::STATUS_COLUMNS[$metric] ?? null;
        $visits = in_array(MetricProvider::SOURCE_VISIT_PATH_DAILY, $sources, true);
        switch ($subjectType) {
            case Finding::SUBJECT_PRODUCT:
            case Finding::SUBJECT_CATEGORY:
                if ($statusColumn !== null || $visits) {
                    // Error / speed / visit metrics: only entities with that metric in the range.
                    $alias = $visits ? 'v' : 'p';
                    $column = $alias . '.' . ($visits ? 'visits' : $statusColumn);
                    $table = $visits ? MetricProvider::VISIT_PATH_TABLE : MetricProvider::PATH_TABLE;
                    $select = $connection->select()
                        ->from([$alias => $this->table($table)], ['k' => $alias . '.entity_id'])
                        ->where($alias . '.entity_type = ?', $subjectType)
                        ->where($alias . '.entity_id IS NOT NULL')
                        ->group($alias . '.entity_id')
                        ->having('SUM(' . $column . ') > 0');
                    if (!$visits) {
                        $select->where('p.category IN (?)', Category::BOT_CATEGORIES);
                    }
                    $this->filters($select, $alias, 'day', $daily, $storeId, $simulated);
                    $order = 'SUM(' . $column . ')';
                    break;
                }
                $select = $connection->select()
                    ->from(['p' => $this->table(MetricProvider::SUBJECT_CRAWL_TABLE)], ['k' => 'p.entity_id'])
                    ->where('p.entity_type = ?', $subjectType)
                    ->where('p.entity_id IS NOT NULL')
                    ->where('p.category IN (?)', MetricProvider::crawlCategories($metric))
                    ->group('p.entity_id');
                $this->filters($select, 'p', 'day', $daily, $storeId, $simulated);
                $order = 'SUM(p.hits)';
                break;
            case Finding::SUBJECT_BOT:
                if (in_array(MetricProvider::SOURCE_RAW_HIT, $sources, true)
                    && !in_array(MetricProvider::SOURCE_CRAWL_DAILY, $sources, true)
                ) {
                    $select = $connection->select()
                        ->from(['h' => $this->table(MetricProvider::HIT_TABLE)], ['k' => 'h.bot_code'])
                        ->where('h.category IN (?)', Category::BOT_CATEGORIES)
                        ->group('h.bot_code');
                    $this->filters($select, 'h', 'created_at', $raw, $storeId, $simulated);
                    $order = 'COUNT(*)';
                    break;
                }
                $select = $connection->select()
                    ->from(['d' => $this->table(MetricProvider::CRAWL_TABLE)], ['k' => 'd.bot_code'])
                    ->where('d.category IN (?)', Category::BOT_CATEGORIES)
                    ->group('d.bot_code');
                $this->filters($select, 'd', 'day', $daily, $storeId, $simulated);
                $order = 'SUM(d.hits)';
                break;
            case Finding::SUBJECT_SOURCE:
                $select = $connection->select()
                    ->from(['v' => $this->table(MetricProvider::VISIT_DAILY_TABLE)], ['k' => 'v.source_code'])
                    ->group('v.source_code');
                $this->filters($select, 'v', 'day', $daily, $storeId, $simulated);
                $order = 'SUM(v.visits)';
                break;
            case Finding::SUBJECT_PATH:
                if (in_array(MetricProvider::SOURCE_RAW_HIT, $sources, true)) {
                    $select = $connection->select()
                        ->from(['h' => $this->table(MetricProvider::HIT_TABLE)], ['k' => new Expression('MIN(h.path)')])
                        ->where('h.category IN (?)', Category::BOT_CATEGORIES)
                        ->group('h.path_hash');
                    $this->filters($select, 'h', 'created_at', $raw, $storeId, $simulated);
                    $order = 'COUNT(*)';
                } elseif (in_array(MetricProvider::SOURCE_PATH_DAILY, $sources, true)) {
                    $column = 'p.' . ($statusColumn ?? 'hits');
                    $select = $connection->select()
                        ->from(['p' => $this->table(MetricProvider::PATH_TABLE)], ['k' => new Expression('MIN(p.path)')])
                        ->where(
                            'p.category IN (?)',
                            $statusColumn !== null ? Category::BOT_CATEGORIES : MetricProvider::crawlCategories($metric)
                        )
                        ->group('p.path_hash')
                        ->having('SUM(' . $column . ') > 0');
                    $this->filters($select, 'p', 'day', $daily, $storeId, $simulated);
                    $order = 'SUM(' . $column . ')';
                } else {
                    $select = $connection->select()
                        ->from(
                            ['v' => $this->table(MetricProvider::VISIT_PATH_TABLE)],
                            ['k' => new Expression('MIN(v.path)')]
                        )
                        ->group('v.path_hash');
                    $this->filters($select, 'v', 'day', $daily, $storeId, $simulated);
                    $order = 'SUM(v.visits)';
                }
                break;
            default:
                return [];
        }
        $select->order([new Expression($order . ' DESC'), 'k ASC'])->limit($limit);
        $out = [];
        foreach ($connection->fetchCol($select) as $key) {
            $key = (string)$key;
            if ($key !== '') {
                $out[] = $key;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Baseline of the coverage method: catalogue entities (with a live URL in url_rewrite, treated ones
     * left out) that no AI bot fetched during $lookback, and how many of them got a first fetch in $window
     *
     * @param string $metric Crawl count metric (decides the bot categories)
     * @param string $entityType product | category
     * @param int $storeId 0 = all store views
     * @param int[] $excludeIds Treated entity IDs
     * @param string[] $lookback [from, to] days that must have no fetch
     * @param string[] $window [from, to] days in which a first fetch counts
     * @param bool $simulated
     * @return array{n: int, x: int} Baseline size and how many were fetched in the window
     */
    public function coverageBaseline(
        string $metric,
        string $entityType,
        int $storeId,
        array $excludeIds,
        array $lookback,
        array $window,
        bool $simulated
    ): array {
        if (!in_array($entityType, [Finding::SUBJECT_PRODUCT, Finding::SUBJECT_CATEGORY], true)) {
            return ['n' => 0, 'x' => 0];
        }
        $connection = $this->resource->getConnection();
        $daily = $connection->quoteIdentifier($this->table(MetricProvider::SUBJECT_CRAWL_TABLE));
        $rewrite = $connection->quoteIdentifier($this->table(MetricProvider::URL_REWRITE_TABLE));
        $categories = MetricProvider::crawlCategories($metric);
        $fetched = static function (string $alias, array $range, array &$bind) use (
            $daily,
            $entityType,
            $categories,
            $storeId,
            $simulated
        ): string {
            $sql = 'SELECT 1 FROM ' . $daily . ' AS ' . $alias . ' WHERE ' . $alias . '.entity_type = ? AND '
                . $alias . '.entity_id = b.entity_id AND ' . $alias . '.day >= ? AND ' . $alias . '.day <= ? AND '
                . $alias . '.is_simulated = ? AND ' . $alias . '.category IN ('
                . implode(', ', array_fill(0, count($categories), '?')) . ')';
            array_push($bind, $entityType, $range[0], $range[1], $simulated ? 1 : 0, ...$categories);
            if ($storeId > 0) {
                $sql .= ' AND ' . $alias . '.store_id = ?';
                $bind[] = $storeId;
            }
            return $sql;
        };
        $bind = [];
        $inWindow = $fetched('w', $window, $bind);
        $inner = 'SELECT DISTINCT u.entity_id FROM ' . $rewrite . ' AS u WHERE u.entity_type = ?'
            . ' AND u.redirect_type = 0';
        $bind[] = $entityType;
        if ($storeId > 0) {
            $inner .= ' AND u.store_id = ?';
            $bind[] = $storeId;
        }
        $exclude = array_values(array_unique(array_filter(array_map('intval', $excludeIds))));
        if ($exclude !== []) {
            $inner .= ' AND u.entity_id NOT IN (' . implode(', ', array_fill(0, count($exclude), '?')) . ')';
            array_push($bind, ...$exclude);
        }
        $innerBind = [];
        $inLookback = str_replace('b.entity_id', 'u.entity_id', $fetched('l', $lookback, $innerBind));
        $inner .= ' AND NOT EXISTS (' . $inLookback . ')';
        array_push($bind, ...$innerBind);
        $row = $connection->fetchRow(
            'SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN EXISTS (' . $inWindow . ') THEN 1 ELSE 0 END), 0) AS x'
            . ' FROM (' . $inner . ') AS b',
            $bind
        );
        return ['n' => (int)($row['n'] ?? 0), 'x' => (int)($row['x'] ?? 0)];
    }

    /**
     * Range, store and simulation filters
     *
     * @param Select $select
     * @param string $alias
     * @param string $column day | created_at
     * @param string[] $range Daily: [from, to] inclusive; raw: [from, end) exclusive
     * @param int $storeId
     * @param bool $simulated
     * @return void
     */
    private function filters(
        Select $select,
        string $alias,
        string $column,
        array $range,
        int $storeId,
        bool $simulated
    ): void {
        $select->where($alias . '.' . $column . ' >= ?', $range[0])
            ->where(
                $alias . '.' . $column . ($column === 'day' ? ' <= ?' : ' < ?'),
                $range[1]
            )
            ->where($alias . '.is_simulated = ?', $simulated ? 1 : 0);
        if ($storeId > 0) {
            $select->where($alias . '.store_id = ?', $storeId);
        }
    }

    /**
     * Prefixed table name
     *
     * @param string $name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
