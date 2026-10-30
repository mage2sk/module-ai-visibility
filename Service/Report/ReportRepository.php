<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Report;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Model\Source\AttributionModel;
use Panth\AiVisibility\Model\Source\Category;

/**
 * Read-only queries behind the dashboard.
 *
 * Crawler and visit figures come from the *_daily summary tables only (fast at any traffic
 * level); orders come from panth_aiv_order joined to sales_order, by the order's created_at.
 * Every method filters by an inclusive UTC day range, an optional store view (0 = all) and
 * leaves out simulated rows unless $includeSim is true.
 */
class ReportRepository
{
    public const CRAWL_TABLE = 'panth_aiv_crawl_daily';
    public const PATH_TABLE = 'panth_aiv_path_daily';
    public const VISIT_DAILY_TABLE = 'panth_aiv_visit_daily';
    public const ORDER_TABLE = 'panth_aiv_order';
    public const CANDIDATE_TABLE = 'panth_aiv_candidate';
    public const SALES_ORDER_TABLE = 'sales_order';

    public const OTHER_LABEL = 'Other';
    private const STATE_CANCELED = 'canceled';
    private const BOT_TABLE_LIMIT = 50;

    /**
     * @param ResourceConnection $resource
     * @param BotDirectory $botDirectory
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly BotDirectory $botDirectory
    ) {
    }

    /**
     * AI crawler hits (known bot categories) and live-answer fetches
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array{hits: int, live: int}
     */
    public function crawlTotals(DateRange $range, int $storeId, bool $includeSim): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(
                ['d' => $this->table(self::CRAWL_TABLE)],
                [
                    'hits' => new Expression('COALESCE(SUM(d.hits), 0)'),
                    'live' => new Expression(
                        "COALESCE(SUM(CASE WHEN d.category = 'live' THEN d.hits ELSE 0 END), 0)"
                    ),
                ]
            )
            ->where('d.category IN (?)', Category::BOT_CATEGORIES);
        $this->applyDailyFilters($select, 'd', $range, $storeId, $includeSim);
        $row = $connection->fetchRow($select) ?: [];
        return ['hits' => (int)($row['hits'] ?? 0), 'live' => (int)($row['live'] ?? 0)];
    }

    /**
     * Confirmed AI visits in the range
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return int
     */
    public function visitTotal(DateRange $range, int $storeId, bool $includeSim): int
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(['v' => $this->table(self::VISIT_DAILY_TABLE)], ['visits' => new Expression('COALESCE(SUM(v.visits), 0)')]);
        $this->applyDailyFilters($select, 'v', $range, $storeId, $includeSim);
        return (int)$connection->fetchOne($select);
    }

    /**
     * Attributed orders that are not canceled, and their base grand total
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array{orders: int, revenue: float}
     */
    public function orderTotals(DateRange $range, int $storeId, bool $includeSim): array
    {
        $select = $this->orderSelect($range, $storeId, $includeSim, [
            'orders' => new Expression('COUNT(*)'),
            'revenue' => new Expression('COALESCE(SUM(so.base_grand_total), 0)'),
        ]);
        $row = $this->connection()->fetchRow($select) ?: [];
        return ['orders' => (int)($row['orders'] ?? 0), 'revenue' => (float)($row['revenue'] ?? 0)];
    }

    /**
     * Daily hits stacked by bot company: the top companies by hits, the rest as "Other"
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @param int $top
     * @return array<string, array<string, int>> company => [day => hits] (every day of the range)
     */
    public function hitsByCompany(DateRange $range, int $storeId, bool $includeSim, int $top = 5): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(
                ['d' => $this->table(self::CRAWL_TABLE)],
                ['day' => 'd.day', 'bot_code' => 'd.bot_code', 'hits' => new Expression('SUM(d.hits)')]
            )
            ->where('d.category IN (?)', Category::BOT_CATEGORIES)
            ->group(['d.day', 'd.bot_code']);
        $this->applyDailyFilters($select, 'd', $range, $storeId, $includeSim);

        $byCompany = [];
        $totals = [];
        foreach ($connection->fetchAll($select) as $row) {
            $company = $this->botDirectory->describe((string)$row['bot_code'])['company'];
            $day = substr((string)$row['day'], 0, 10);
            $hits = (int)$row['hits'];
            $byCompany[$company][$day] = ($byCompany[$company][$day] ?? 0) + $hits;
            $totals[$company] = ($totals[$company] ?? 0) + $hits;
        }
        if ($totals === []) {
            return [];
        }
        uksort($totals, static function (string $a, string $b) use ($totals): int {
            return [$totals[$b], $a] <=> [$totals[$a], $b];
        });
        $keep = array_slice(array_keys($totals), 0, max(1, $top));
        if (count($totals) === count($keep) + 1) {
            $keep = array_keys($totals);
        }
        $emptyDays = array_fill_keys($range->days(), 0);
        $result = [];
        foreach ($keep as $company) {
            $result[$company] = array_intersect_key($byCompany[$company], $emptyDays) + $emptyDays;
            ksort($result[$company]);
        }
        $other = $emptyDays;
        $hasOther = false;
        foreach ($byCompany as $company => $days) {
            if (in_array($company, $keep, true)) {
                continue;
            }
            foreach ($days as $day => $hits) {
                if (isset($other[$day])) {
                    $other[$day] += $hits;
                    $hasOther = true;
                }
            }
        }
        if ($hasOther) {
            $result[self::OTHER_LABEL] = $other;
        }
        return $result;
    }

    /**
     * Confirmed AI visits per day (every day of the range)
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array<string, int> day => visits
     */
    public function visitsByDay(DateRange $range, int $storeId, bool $includeSim): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(['v' => $this->table(self::VISIT_DAILY_TABLE)], ['day' => 'v.day', 'visits' => new Expression('SUM(v.visits)')])
            ->group('v.day');
        $this->applyDailyFilters($select, 'v', $range, $storeId, $includeSim);
        return $this->fillDays($range, $connection->fetchPairs($select));
    }

    /**
     * Attributed orders per day of the order date (every day of the range)
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array<string, int> day => orders
     */
    public function ordersByDay(DateRange $range, int $storeId, bool $includeSim): array
    {
        $select = $this->orderSelect($range, $storeId, $includeSim, [
            'day' => new Expression('DATE(so.created_at)'),
            'orders' => new Expression('COUNT(*)'),
        ])->group(new Expression('DATE(so.created_at)'));
        return $this->fillDays($range, $this->connection()->fetchPairs($select));
    }

    /**
     * Most fetched paths
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @param string|null $category One bot category, or null for every known category
     * @param int $limit
     * @return array<int, array{path: string, entity_type: ?string, entity_id: ?int, hits: int}>
     */
    public function topPaths(
        DateRange $range,
        int $storeId,
        bool $includeSim,
        ?string $category = null,
        int $limit = 10
    ): array {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(
                ['p' => $this->table(self::PATH_TABLE)],
                [
                    'path' => new Expression('MAX(p.path)'),
                    'entity_type' => new Expression('MAX(p.entity_type)'),
                    'entity_id' => new Expression('MAX(p.entity_id)'),
                    'hits' => new Expression('SUM(p.hits)'),
                ]
            )
            ->group('p.path_hash')
            ->order(['hits DESC', 'path ASC'])
            ->limit(max(1, min(100, $limit)));
        if ($category !== null) {
            $select->where('p.category = ?', $category);
        } else {
            $select->where('p.category IN (?)', Category::BOT_CATEGORIES);
        }
        $this->applyDailyFilters($select, 'p', $range, $storeId, $includeSim);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'path' => (string)$row['path'],
                'entity_type' => isset($row['entity_type']) && $row['entity_type'] !== ''
                    ? (string)$row['entity_type'] : null,
                'entity_id' => isset($row['entity_id']) && (int)$row['entity_id'] > 0 ? (int)$row['entity_id'] : null,
                'hits' => (int)$row['hits'],
            ];
        }
        return $rows;
    }

    /**
     * Hits per bot with status counts and total response time
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array<int, array{bot_code: string, category: string, hits: int, s4xx: int, s5xx: int, total_ms: int}>
     */
    public function botStats(DateRange $range, int $storeId, bool $includeSim): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(
                ['d' => $this->table(self::CRAWL_TABLE)],
                [
                    'bot_code' => 'd.bot_code',
                    'category' => new Expression('MAX(d.category)'),
                    'hits' => new Expression('SUM(d.hits)'),
                    's4xx' => new Expression('SUM(d.s4xx)'),
                    's5xx' => new Expression('SUM(d.s5xx)'),
                    'total_ms' => new Expression('SUM(d.total_ms)'),
                ]
            )
            ->where('d.category IN (?)', Category::BOT_CATEGORIES)
            ->group('d.bot_code')
            ->order(['hits DESC', 'd.bot_code ASC'])
            ->limit(self::BOT_TABLE_LIMIT);
        $this->applyDailyFilters($select, 'd', $range, $storeId, $includeSim);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'bot_code' => (string)$row['bot_code'],
                'category' => (string)$row['category'],
                'hits' => (int)$row['hits'],
                's4xx' => (int)$row['s4xx'],
                's5xx' => (int)$row['s5xx'],
                'total_ms' => (int)$row['total_ms'],
            ];
        }
        return $rows;
    }

    /**
     * Orders and revenue per AI source (first or last touch, per the attribution model)
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @param string $model first | last
     * @return array<int, array{source: string, orders: int, revenue: float}>
     */
    public function revenueBySource(DateRange $range, int $storeId, bool $includeSim, string $model): array
    {
        $column = $model === AttributionModel::LAST ? 'o.last_source' : 'o.first_source';
        $select = $this->orderSelect($range, $storeId, $includeSim, [
            'source' => new Expression("COALESCE($column, '')"),
            'orders' => new Expression('COUNT(*)'),
            'revenue' => new Expression('COALESCE(SUM(so.base_grand_total), 0)'),
        ])->group($column)->order(['revenue DESC', 'orders DESC', 'source ASC']);

        $rows = [];
        foreach ($this->connection()->fetchAll($select) as $row) {
            $rows[] = [
                'source' => (string)$row['source'],
                'orders' => (int)$row['orders'],
                'revenue' => (float)$row['revenue'],
            ];
        }
        return $rows;
    }

    /**
     * llms.txt fetches per bot code
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array<string, int> bot_code => fetches, most first
     */
    public function llmsFetchesByBot(DateRange $range, int $storeId, bool $includeSim): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(['d' => $this->table(self::CRAWL_TABLE)], ['bot_code' => 'd.bot_code', 'hits' => new Expression('SUM(d.hits)')])
            ->where('d.page_type = ?', PageTypeResolverInterface::TYPE_LLMS)
            ->where('d.category IN (?)', Category::BOT_CATEGORIES)
            ->group('d.bot_code')
            ->order(['hits DESC', 'd.bot_code ASC']);
        $this->applyDailyFilters($select, 'd', $range, $storeId, $includeSim);
        $result = [];
        foreach ($connection->fetchPairs($select) as $code => $hits) {
            $result[(string)$code] = (int)$hits;
        }
        return $result;
    }

    /**
     * Whether simulator rows exist in the range (crawl, visit or order data)
     *
     * @param DateRange $range
     * @param int $storeId
     * @return bool
     */
    public function hasSimulatedData(DateRange $range, int $storeId): bool
    {
        $connection = $this->connection();
        foreach ([self::CRAWL_TABLE, self::VISIT_DAILY_TABLE] as $table) {
            $select = $connection->select()
                ->from(['t' => $this->table($table)], ['found' => new Expression('1')])
                ->where('t.is_simulated = ?', 1)
                ->where('t.day >= ?', $range->from())
                ->where('t.day <= ?', $range->to())
                ->limit(1);
            if ($storeId > 0) {
                $select->where('t.store_id = ?', $storeId);
            }
            if ($connection->fetchOne($select)) {
                return true;
            }
        }
        $select = $connection->select()
            ->from(['o' => $this->table(self::ORDER_TABLE)], ['found' => new Expression('1')])
            ->where('o.is_simulated = ?', 1)
            ->where('o.created_at >= ?', $range->startDateTime())
            ->where('o.created_at < ?', $range->endDateTimeExclusive())
            ->limit(1);
        if ($storeId > 0) {
            $select->join(['so' => $this->table(self::SALES_ORDER_TABLE)], 'so.entity_id = o.order_id', [])
                ->where('so.store_id = ?', $storeId);
        }
        return (bool)$connection->fetchOne($select);
    }

    /**
     * Whether any crawl, visit or order data was ever recorded (for the first-run empty state)
     *
     * @param bool $includeSim
     * @return bool
     */
    public function hasAnyData(bool $includeSim): bool
    {
        $connection = $this->connection();
        foreach ([self::CRAWL_TABLE, self::VISIT_DAILY_TABLE, self::ORDER_TABLE] as $table) {
            $select = $connection->select()
                ->from(['t' => $this->table($table)], ['found' => new Expression('1')])
                ->limit(1);
            if (!$includeSim) {
                $select->where('t.is_simulated = ?', 0);
            }
            if ($connection->fetchOne($select)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Number of discovery candidates waiting for review
     *
     * @param bool $includeSim
     * @return int
     */
    public function newCandidateCount(bool $includeSim): int
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(['c' => $this->table(self::CANDIDATE_TABLE)], ['total' => new Expression('COUNT(*)')])
            ->where('c.status = ?', 'new');
        if (!$includeSim) {
            $select->where('c.is_simulated = ?', 0);
        }
        return (int)$connection->fetchOne($select);
    }

    /**
     * Day range, store and simulation filters on a daily table
     *
     * @param Select $select
     * @param string $alias
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return void
     */
    private function applyDailyFilters(Select $select, string $alias, DateRange $range, int $storeId, bool $includeSim): void
    {
        $select->where($alias . '.day >= ?', $range->from())
            ->where($alias . '.day <= ?', $range->to());
        if ($storeId > 0) {
            $select->where($alias . '.store_id = ?', $storeId);
        }
        if (!$includeSim) {
            $select->where($alias . '.is_simulated = ?', 0);
        }
    }

    /**
     * Attributed, not canceled orders whose order date is inside the range
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @param array $columns
     * @return Select
     */
    private function orderSelect(DateRange $range, int $storeId, bool $includeSim, array $columns): Select
    {
        $select = $this->connection()->select()
            ->from(['o' => $this->table(self::ORDER_TABLE)], $columns)
            ->join(['so' => $this->table(self::SALES_ORDER_TABLE)], 'so.entity_id = o.order_id', [])
            ->where('so.created_at >= ?', $range->startDateTime())
            ->where('so.created_at < ?', $range->endDateTimeExclusive())
            ->where('(so.state IS NULL OR so.state <> ?)', self::STATE_CANCELED);
        if ($storeId > 0) {
            $select->where('so.store_id = ?', $storeId);
        }
        if (!$includeSim) {
            $select->where('o.is_simulated = ?', 0);
        }
        return $select;
    }

    /**
     * Every day of the range with the fetched counts (missing days = 0)
     *
     * @param DateRange $range
     * @param array $pairs day => count
     * @return array<string, int>
     */
    private function fillDays(DateRange $range, array $pairs): array
    {
        $result = array_fill_keys($range->days(), 0);
        foreach ($pairs as $day => $count) {
            $day = substr((string)$day, 0, 10);
            if (isset($result[$day])) {
                $result[$day] += (int)$count;
            }
        }
        return $result;
    }

    /**
     * Default connection
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
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
