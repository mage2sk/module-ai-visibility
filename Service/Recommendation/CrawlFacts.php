<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\StockStatusLookup;
use Panth\AiVisibility\Service\Report\ReportRepository;
use Panth\AiVisibility\Service\DataScope;
use Panth\AiVisibility\Service\Rollup;

/**
 * Read-only, set-based queries behind the crawler recommendation rules (R1-R4).
 *
 * Every query is a single grouped statement with bound parameters and a hard LIMIT, so the cost does
 * not grow with the number of rows returned. Simulator rows (is_simulated = 1) are always left out and
 * only known AI bot categories are counted. Store view 0 means "all store views".
 * Raw hit queries read panth_aiv_hit (kept privacy/hit_retention_days, 14 by default), so callers use
 * windows of at most 7 days there; longer windows use the daily summary tables.
 * Status-range queries (4xx, 5xx) read the window through the (status, created_at) index; grouped raw
 * queries aggregate per path and bot first so they scan the window once instead of a whole index.
 */
class CrawlFacts
{
    public const HIT_TABLE = 'panth_aiv_hit';
    public const MAX_LIMIT = 500;

    private const VISIBLE = [2, 3, 4];
    private const STATUS_ENABLED = 1;

    /**
     * @var array<string, int|null>
     */
    private array $attributeIds = [];

    /**
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param StockStatusLookup $stock
     * @param DataScope $scope
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly StockStatusLookup $stock,
        private readonly DataScope $scope
    ) {
    }

    /**
     * Bot hits with a status in [statusMin, statusMax] (0, 0 = any status) in [from, to)
     *
     * @param int $storeId
     * @param string $from 'Y-m-d H:i:s' (inclusive)
     * @param string $to 'Y-m-d H:i:s' (exclusive)
     * @param int $statusMin
     * @param int $statusMax
     * @param string[] $excludePaths Exact paths to leave out
     * @param string[] $excludePrefixes Path prefixes to leave out
     * @return array{hits: int, paths: int, bots: int}
     */
    public function hitSummary(
        int $storeId,
        string $from,
        string $to,
        int $statusMin = 0,
        int $statusMax = 0,
        array $excludePaths = [],
        array $excludePrefixes = []
    ): array {
        $bind = [];
        $where = $this->hitWhere($storeId, $from, $to, $statusMin, $statusMax, $excludePaths, $excludePrefixes, $bind);
        $sql = 'SELECT COUNT(*) AS hits, COUNT(DISTINCT h.path_hash) AS paths, COUNT(DISTINCT h.bot_code) AS bots'
            . ' FROM ' . $this->table(self::HIT_TABLE) . ' AS h WHERE ' . $where;
        $row = $this->connection()->fetchRow($sql, $bind) ?: [];
        return [
            'hits' => (int)($row['hits'] ?? 0),
            'paths' => (int)($row['paths'] ?? 0),
            'bots' => (int)($row['bots'] ?? 0),
        ];
    }

    /**
     * Number of bot hits in [from, to) of any status (hitSummary()['hits'] without the distinct counts)
     *
     * @param int $storeId
     * @param string $from 'Y-m-d H:i:s' (inclusive)
     * @param string $to 'Y-m-d H:i:s' (exclusive)
     * @return int
     */
    public function hitCount(int $storeId, string $from, string $to): int
    {
        $bind = [];
        $where = $this->hitWhere($storeId, $from, $to, 0, 0, [], [], $bind);
        return (int)$this->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->table(self::HIT_TABLE) . ' AS h WHERE ' . $where,
            $bind
        );
    }

    /**
     * Paths with the most bot hits of the status range, most hits first
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @param int $statusMin
     * @param int $statusMax
     * @param int $limit
     * @param string[] $excludePaths
     * @param string[] $excludePrefixes
     * @return array<int, array{path: string, hits: int, bots: int, avg_ms: int, max_status: int, last_seen: string}>
     */
    public function topHitPaths(
        int $storeId,
        string $from,
        string $to,
        int $statusMin,
        int $statusMax,
        int $limit,
        array $excludePaths = [],
        array $excludePrefixes = []
    ): array {
        $bind = [];
        $where = $this->hitWhere($storeId, $from, $to, $statusMin, $statusMax, $excludePaths, $excludePrefixes, $bind);
        // Per path and bot first, then per path (see pathBotGroups()); the path text is read once per path
        // through the primary key. Equal hits read /p-1, /p-2, ... /p-10 (shorter path first, then A-Z).
        $sql = 'SELECT p.path AS path, x.hits AS hits, x.bots AS bots, x.avg_ms AS avg_ms,'
            . ' x.max_status AS max_status, x.last_seen AS last_seen FROM (SELECT SUM(g.hits) AS hits,'
            . ' COUNT(*) AS bots, ROUND(SUM(g.total_ms) / SUM(g.hits)) AS avg_ms, MAX(g.max_status) AS max_status,'
            . ' MAX(g.last_seen) AS last_seen, MAX(g.hit_id) AS hit_id FROM ' . $this->pathBotGroups($where)
            . ' GROUP BY g.path_hash) AS x INNER JOIN ' . $this->table(self::HIT_TABLE) . ' AS p ON p.hit_id = x.hit_id'
            . ' ORDER BY x.hits DESC, CHAR_LENGTH(p.path) ASC, p.path ASC LIMIT ' . $this->limit($limit);
        $rows = [];
        foreach ($this->connection()->fetchAll($sql, $bind) as $row) {
            $rows[] = [
                'path' => (string)$row['path'],
                'hits' => (int)$row['hits'],
                'bots' => (int)$row['bots'],
                'avg_ms' => (int)$row['avg_ms'],
                'max_status' => (int)$row['max_status'],
                'last_seen' => (string)$row['last_seen'],
            ];
        }
        return $rows;
    }

    /**
     * Bots with the most hits of the status range: bot code => hits, most first
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @param int $statusMin
     * @param int $statusMax
     * @param int $limit
     * @param string[] $excludePaths
     * @param string[] $excludePrefixes
     * @return array<string, int>
     */
    public function topHitBots(
        int $storeId,
        string $from,
        string $to,
        int $statusMin,
        int $statusMax,
        int $limit,
        array $excludePaths = [],
        array $excludePrefixes = []
    ): array {
        $bind = [];
        $where = $this->hitWhere($storeId, $from, $to, $statusMin, $statusMax, $excludePaths, $excludePrefixes, $bind);
        // Per bot and path first (see pathBotGroups()), so the bot_code index is never walked for the grouping
        $sql = 'SELECT g.bot_code AS bot_code, SUM(g.hits) AS hits FROM (SELECT h.bot_code AS bot_code,'
            . ' h.path_hash AS path_hash, COUNT(*) AS hits FROM ' . $this->table(self::HIT_TABLE) . ' AS h WHERE '
            . $where . ' GROUP BY h.bot_code, h.path_hash) AS g'
            . ' GROUP BY g.bot_code ORDER BY hits DESC, bot_code ASC LIMIT ' . $this->limit($limit);
        $result = [];
        foreach ($this->connection()->fetchAll($sql, $bind) as $row) {
            $result[(string)$row['bot_code']] = (int)$row['hits'];
        }
        return $result;
    }

    /**
     * Paths whose average bot response time is above a threshold (timed hits only)
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @param int $thresholdMs Average must be strictly above this
     * @param int $minHits Minimum timed hits per path
     * @param int $limit Max rows in "rows"
     * @return array{paths: int, hits: int, rows: array<int, array{path: string, hits: int, bots: int, avg_ms: int, max_ms: int}>}
     */
    public function slowPaths(int $storeId, string $from, string $to, int $thresholdMs, int $minHits, int $limit): array
    {
        $bind = [];
        $where = $this->hitWhere($storeId, $from, $to, 0, 0, [], [], $bind) . ' AND h.duration_ms > 0';
        $bind['min_hits'] = max(1, $minHits);
        $bind['threshold'] = max(0, $thresholdMs);
        // SUM(total_ms) / SUM(hits) is AVG(h.duration_ms) with the same DECIMAL division. One pass: the
        // totals over every qualifying path come from window functions, evaluated before the LIMIT.
        $sql = 'SELECT p.path AS path, y.hits AS hits, y.bots AS bots, y.avg_ms AS avg_ms, y.max_ms AS max_ms,'
            . ' y.paths_total AS paths_total, y.hits_total AS hits_total FROM (SELECT x.hits, x.bots, x.avg_ms,'
            . ' x.max_ms, x.hit_id, COUNT(*) OVER () AS paths_total, SUM(x.hits) OVER () AS hits_total FROM'
            . ' (SELECT SUM(g.hits) AS hits, COUNT(*) AS bots, ROUND(SUM(g.total_ms) / SUM(g.hits)) AS avg_ms,'
            . ' MAX(g.max_ms) AS max_ms, MAX(g.hit_id) AS hit_id FROM ' . $this->pathBotGroups($where)
            . ' GROUP BY g.path_hash HAVING SUM(g.hits) >= :min_hits AND SUM(g.total_ms) / SUM(g.hits) > :threshold)'
            . ' AS x) AS y INNER JOIN ' . $this->table(self::HIT_TABLE) . ' AS p ON p.hit_id = y.hit_id'
            . ' ORDER BY y.hits DESC, y.avg_ms DESC, CHAR_LENGTH(p.path) ASC, p.path ASC LIMIT ' . $this->limit($limit);
        $summary = ['paths' => 0, 'hits' => 0];
        $rows = [];
        foreach ($this->connection()->fetchAll($sql, $bind) as $row) {
            $summary = ['paths' => $row['paths_total'], 'hits' => $row['hits_total']];
            $rows[] = [
                'path' => (string)$row['path'],
                'hits' => (int)$row['hits'],
                'bots' => (int)$row['bots'],
                'avg_ms' => (int)$row['avg_ms'],
                'max_ms' => (int)$row['max_ms'],
            ];
        }
        return ['paths' => (int)($summary['paths'] ?? 0), 'hits' => (int)($summary['hits'] ?? 0), 'rows' => $rows];
    }

    /**
     * First UTC day with per-product AI crawler data (panth_aiv_entity_daily), null when there is none
     *
     * This is the history guard of productCoverage(), so it reads the same table, rows and store: after an
     * upgrade from 1.0 panth_aiv_crawl_daily goes back much further than panth_aiv_entity_daily (new in 1.1),
     * and products would otherwise count as "never fetched" only because their older fetches have no
     * per-product rows.
     *
     * @param int $storeId 0 = all store views
     * @return string|null 'Y-m-d'
     */
    public function crawlDataStart(int $storeId): ?string
    {
        $bind = [];
        // ORDER BY day LIMIT 1 (not MIN) lets MySQL walk the primary key (day first) and stop at the first row
        $sql = 'SELECT d.day FROM ' . $this->table(Rollup::ENTITY_DAILY_TABLE) . ' AS d'
            . " WHERE d.entity_type = 'product' AND " . $this->scope->condition('d.is_simulated')
            . ' AND d.hits > 0 AND d.category IN (' . $this->categoryList($bind, 'dc') . ')';
        if ($storeId > 0) {
            $sql .= ' AND d.store_id = :store';
            $bind['store'] = $storeId;
        }
        $sql .= ' ORDER BY d.day ASC LIMIT 1';
        $day = $this->connection()->fetchOne($sql, $bind);
        return $day ? substr((string)$day, 0, 10) : null;
    }

    /**
     * Daily-table hits of the given bot codes in [fromDay, toDay]: code => hits (every code present)
     *
     * @param int $storeId
     * @param string[] $codes
     * @param string $fromDay 'Y-m-d'
     * @param string $toDay 'Y-m-d' (inclusive)
     * @return array<string, int>
     */
    public function botHitsByCode(int $storeId, array $codes, string $fromDay, string $toDay): array
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
        $result = array_fill_keys($codes, 0);
        if ($codes === []) {
            return $result;
        }
        $bind = ['from' => $fromDay, 'to' => $toDay];
        $in = [];
        foreach ($codes as $i => $code) {
            $in[] = ':b' . $i;
            $bind['b' . $i] = $code;
        }
        $sql = 'SELECT d.bot_code AS bot_code, SUM(d.hits) AS hits FROM ' . $this->table(ReportRepository::CRAWL_TABLE)
            . ' AS d WHERE ' . $this->scope->condition('d.is_simulated') . ' AND d.day >= :from AND d.day <= :to AND d.bot_code IN (' . implode(', ', $in)
            . ')';
        if ($storeId > 0) {
            $sql .= ' AND d.store_id = :store';
            $bind['store'] = $storeId;
        }
        $sql .= ' GROUP BY d.bot_code';
        foreach ($this->connection()->fetchAll($sql, $bind) as $row) {
            $result[(string)$row['bot_code']] = (int)$row['hits'];
        }
        return $result;
    }

    /**
     * Enabled, visible, in-stock products of the store's website created before $createdBefore, how many
     * of them no AI bot fetched as a product page since $sinceDay, and the first $limit of those IDs
     *
     * @param int $storeId 0 = all websites, default attribute values
     * @param string $sinceDay 'Y-m-d'
     * @param string $createdBefore 'Y-m-d H:i:s'
     * @param int $limit
     * @return array{eligible: int, never: int, ids: int[]}
     */
    public function productCoverage(int $storeId, string $sinceDay, string $createdBefore, int $limit): array
    {
        $empty = ['eligible' => 0, 'never' => 0, 'ids' => []];
        $statusId = $this->attributeId('status');
        $visibilityId = $this->attributeId('visibility');
        if ($statusId === null || $visibilityId === null) {
            return $empty;
        }
        $connection = $this->connection();
        $entity = $this->table('catalog_product_entity');
        $int = $this->table('catalog_product_entity_int');
        $link = $connection->tableColumnExists($entity, 'row_id') ? 'row_id' : 'entity_id';
        $bind = ['status_attr' => $statusId, 'vis_attr' => $visibilityId, 'created_before' => $createdBefore];
        $from = ' FROM ' . $entity . ' AS e'
            . " INNER JOIN $int AS sd ON sd.$link = e.$link AND sd.store_id = 0 AND sd.attribute_id = :status_attr"
            . " INNER JOIN $int AS vd ON vd.$link = e.$link AND vd.store_id = 0 AND vd.attribute_id = :vis_attr";
        $status = 'sd.value';
        $visibility = 'vd.value';
        if ($storeId > 0) {
            $bind['status_attr_s'] = $statusId;
            $bind['vis_attr_s'] = $visibilityId;
            $bind['store_s'] = $storeId;
            $bind['store_v'] = $storeId;
            $bind['website'] = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
            $from .= " LEFT JOIN $int AS ss ON ss.$link = e.$link AND ss.attribute_id = :status_attr_s"
                . ' AND ss.store_id = :store_s'
                . " LEFT JOIN $int AS vs ON vs.$link = e.$link AND vs.attribute_id = :vis_attr_s AND vs.store_id = :store_v"
                . ' INNER JOIN ' . $this->table('catalog_product_website')
                . ' AS w ON w.product_id = e.entity_id AND w.website_id = :website';
            $status = 'COALESCE(ss.value, sd.value)';
            $visibility = 'COALESCE(vs.value, vd.value)';
        }
        $where = " WHERE $status = " . self::STATUS_ENABLED
            . " AND $visibility IN (" . implode(', ', self::VISIBLE) . ')'
            . ' AND e.created_at < :created_before'
            // Same stock source as the out-of-stock rule: MSI stock index or cataloginventory_stock_status
            . ' AND ' . $this->stock->inStockCondition($storeId, 'e');

        $eligible = (int)$connection->fetchOne('SELECT COUNT(DISTINCT e.entity_id)' . $from . $where, $bind);
        if ($eligible === 0) {
            return $empty;
        }
        $bind['since'] = $sinceDay;
        // Uncapped per-entity counts: a long-tail product outside the daily top paths still counts as fetched.
        $seen = 'SELECT 1 FROM ' . $this->table(Rollup::ENTITY_DAILY_TABLE) . ' AS p'
            . " WHERE p.entity_type = 'product' AND p.entity_id = e.entity_id AND p.day >= :since"
            . ' AND ' . $this->scope->condition('p.is_simulated') . ' AND p.category IN (' . $this->categoryList($bind, 'pc') . ')';
        if ($storeId > 0) {
            $seen .= ' AND p.store_id = :p_store';
            $bind['p_store'] = $storeId;
        }
        $neverWhere = $where . ' AND NOT EXISTS (' . $seen . ')';
        $never = (int)$connection->fetchOne('SELECT COUNT(DISTINCT e.entity_id)' . $from . $neverWhere, $bind);
        $ids = [];
        if ($never > 0 && $limit > 0) {
            $ids = array_map(
                'intval',
                $connection->fetchCol(
                    'SELECT DISTINCT e.entity_id' . $from . $neverWhere . ' ORDER BY e.entity_id ASC LIMIT '
                    . $this->limit($limit),
                    $bind
                )
            );
        }
        return ['eligible' => $eligible, 'never' => min($never, $eligible), 'ids' => $ids];
    }

    /**
     * Derived table "g" of the raw hits matching $where, one row per path and bot
     *
     * Columns: path_hash, bot_code, hits, total_ms, max_ms, max_status, last_seen and hit_id (the newest hit,
     * to read the path text by primary key: path_hash is the SHA-1 of path, so every hit of a group has the
     * same path, and leaving the 512-character path out keeps the temporary table small). Grouping by path and
     * bot first means bots per path is COUNT(*) of the outer group, and, because (path_hash, bot_code) is not
     * a prefix of any index, MySQL never walks the whole path_hash index (every day kept, a primary key lookup
     * per row) to group in index order: it reads only the window (or the status range of
     * PANTH_AIV_HIT_STATUS_CREATED_AT) once.
     *
     * @param string $where
     * @return string
     */
    private function pathBotGroups(string $where): string
    {
        return '(SELECT h.path_hash AS path_hash, h.bot_code AS bot_code, COUNT(*) AS hits,'
            . ' SUM(h.duration_ms) AS total_ms, MAX(h.duration_ms) AS max_ms, MAX(h.status) AS max_status,'
            . ' MAX(h.created_at) AS last_seen, MAX(h.hit_id) AS hit_id FROM ' . $this->table(self::HIT_TABLE) . ' AS h WHERE ' . $where
            . ' GROUP BY h.path_hash, h.bot_code) AS g';
    }

    /**
     * WHERE clause for raw bot hits; fills $bind
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @param int $statusMin
     * @param int $statusMax
     * @param string[] $excludePaths
     * @param string[] $excludePrefixes
     * @param array $bind
     * @return string
     */
    private function hitWhere(
        int $storeId,
        string $from,
        string $to,
        int $statusMin,
        int $statusMax,
        array $excludePaths,
        array $excludePrefixes,
        array &$bind
    ): string {
        $bind['from'] = $from;
        $bind['to'] = $to;
        $where = 'h.created_at >= :from AND h.created_at < :to AND ' . $this->scope->condition('h.is_simulated')
            . ' AND h.category IN (' . $this->categoryList($bind, 'hc') . ')';
        if ($storeId > 0) {
            $where .= ' AND h.store_id = :store';
            $bind['store'] = $storeId;
        }
        if ($statusMin > 0 || $statusMax > 0) {
            $where .= ' AND h.status >= :status_min AND h.status <= :status_max';
            $bind['status_min'] = $statusMin;
            $bind['status_max'] = max($statusMin, $statusMax);
        }
        $in = [];
        foreach (array_values($excludePaths) as $i => $path) {
            $in[] = ':xp' . $i;
            $bind['xp' . $i] = (string)$path;
        }
        if ($in !== []) {
            $where .= ' AND h.path NOT IN (' . implode(', ', $in) . ')';
        }
        foreach (array_values($excludePrefixes) as $i => $prefix) {
            $where .= ' AND h.path NOT LIKE :xl' . $i;
            $bind['xl' . $i] = addcslashes((string)$prefix, '%_\\') . '%';
        }
        return $where;
    }

    /**
     * Bound placeholder list of the AI bot categories
     *
     * @param array $bind
     * @param string $prefix
     * @return string
     */
    private function categoryList(array &$bind, string $prefix): string
    {
        $in = [];
        foreach (Category::BOT_CATEGORIES as $i => $category) {
            $in[] = ':' . $prefix . $i;
            $bind[$prefix . $i] = $category;
        }
        return implode(', ', $in);
    }

    /**
     * Product EAV attribute id, cached per instance
     *
     * @param string $code
     * @return int|null
     */
    private function attributeId(string $code): ?int
    {
        if (!array_key_exists($code, $this->attributeIds)) {
            $id = (int)$this->connection()->fetchOne(
                'SELECT a.attribute_id FROM ' . $this->table('eav_attribute') . ' AS a INNER JOIN '
                . $this->table('eav_entity_type') . ' AS t ON t.entity_type_id = a.entity_type_id'
                . ' WHERE t.entity_type_code = :type AND a.attribute_code = :code',
                ['type' => 'catalog_product', 'code' => $code]
            );
            $this->attributeIds[$code] = $id > 0 ? $id : null;
        }
        return $this->attributeIds[$code];
    }

    /**
     * Clamp a LIMIT value
     *
     * @param int $limit
     * @return int
     */
    private function limit(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
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

    /**
     * Default connection
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
