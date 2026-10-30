<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Api\HitWriterInterface;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Source\Category;

/**
 * Rebuilds panth_aiv_crawl_daily, panth_aiv_path_daily and panth_aiv_visit_daily for one UTC day.
 *
 * Every number is computed with grouped queries over the raw tables; page types are resolved once
 * per distinct path (url_rewrite lookups are batched). The delete + insert of the day runs in a single
 * transaction, so readers never see a half-built day and running it twice gives the same rows.
 *
 * The work is done per is_simulated slice and per raw table. A slice without raw rows is left
 * untouched (its raw rows may have been removed by retention). A slice of a day before the raw floor
 * (raw rows of that day were partly or fully deleted) is only filled when it has no daily rows yet, so
 * real history is never replaced by a partial rebuild.
 */
class Rollup implements RollupInterface
{
    public const HIT_TABLE = 'panth_aiv_hit';
    public const OVERFLOW_TABLE = 'panth_aiv_overflow_daily';
    public const VISIT_TABLE = 'panth_aiv_visit';
    public const CRAWL_DAILY_TABLE = 'panth_aiv_crawl_daily';
    public const PATH_DAILY_TABLE = 'panth_aiv_path_daily';
    public const VISIT_DAILY_TABLE = 'panth_aiv_visit_daily';
    public const URL_REWRITE_TABLE = 'url_rewrite';
    public const COUNTER_TABLE = 'panth_aiv_counter';

    /**
     * panth_aiv_counter keys holding the raw floor per raw table: value = floor day as YYYYMMDD, day = the
     * day it was last written (retention rewrites it on every run before it prunes old counter rows)
     */
    public const FLOOR_KEY_PREFIX = 'raw_floor_';

    /**
     * Slice modes
     */
    private const MODE_REBUILD = 'rebuild';
    private const MODE_FILL = 'fill';

    /**
     * Raw code prefix of discovery candidates (not rolled up until confirmed)
     */
    public const CANDIDATE_PREFIX = HitWriterInterface::CANDIDATE_PREFIX;

    /**
     * Paths kept per (store, category, simulated) and day in panth_aiv_path_daily
     */
    public const PATHS_PER_GROUP = 500;

    /**
     * Values per url_rewrite IN list (well below max_allowed_packet and placeholder limits)
     */
    public const LOOKUP_CHUNK = 500;

    /**
     * Rows per multi-row INSERT
     */
    public const INSERT_CHUNK = 500;

    /**
     * Page types stored in the daily tables
     */
    public const TYPE_PRODUCT = 'product';
    public const TYPE_CATEGORY = 'category';
    public const TYPE_CMS = 'cms';

    /**
     * url_rewrite.entity_type => stored entity / page type
     */
    private const ENTITY_TYPES = [
        'product' => self::TYPE_PRODUCT,
        'category' => self::TYPE_CATEGORY,
        'cms-page' => self::TYPE_CMS,
    ];

    /**
     * Page types the resolver may return without a database lookup
     */
    private const PATH_TYPES = [
        PageTypeResolverInterface::TYPE_HOME,
        PageTypeResolverInterface::TYPE_LLMS,
        PageTypeResolverInterface::TYPE_ROBOTS,
        PageTypeResolverInterface::TYPE_SITEMAP,
        PageTypeResolverInterface::TYPE_SEARCH,
        PageTypeResolverInterface::TYPE_OTHER,
    ];

    /**
     * Longest request_path url_rewrite can hold
     */
    private const MAX_REQUEST_PATH = 255;

    /**
     * @param ResourceConnection $resource
     * @param PageTypeResolverInterface $pageTypeResolver
     * @param Config $config
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly PageTypeResolverInterface $pageTypeResolver,
        private readonly Config $config
    ) {
    }

    /**
     * First UTC day that is still inside a retention window of $days days
     *
     * Retention deletes raw rows created before this day (00:00:00 UTC), so whole days are
     * always removed together.
     *
     * @param int $days
     * @param int $now Unix time
     * @return string 'Y-m-d'
     */
    public static function cutoffDay(int $days, int $now): string
    {
        return gmdate('Y-m-d', $now - max(0, $days) * 86400);
    }

    /**
     * Validate a 'Y-m-d' day
     *
     * @param string $day
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function normalizeDay(string $day): string
    {
        $day = trim($day);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m)
            || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])
        ) {
            throw new \InvalidArgumentException(sprintf('Invalid day "%s", expected Y-m-d.', $day));
        }
        return $day;
    }

    /**
     * @inheritdoc
     */
    public function rollupDay(string $day, ?int $storeId = null): void
    {
        $day = self::normalizeDay($day);
        $from = $day . ' 00:00:00';
        $to = gmdate('Y-m-d', (int)strtotime($day . ' 00:00:00 UTC') + 86400) . ' 00:00:00';
        $connection = $this->resource->getConnection();

        $crawlModes = $this->sliceModes(
            $connection,
            [self::KIND_HITS, self::HIT_TABLE, self::CRAWL_DAILY_TABLE],
            $day,
            $from,
            $to,
            $storeId
        );
        $visitModes = $this->sliceModes(
            $connection,
            [self::KIND_VISITS, self::VISIT_TABLE, self::VISIT_DAILY_TABLE],
            $day,
            $from,
            $to,
            $storeId
        );
        $rollCrawl = $crawlModes !== [];
        $rollVisits = $visitModes !== [];
        if (!$rollCrawl && !$rollVisits) {
            return;
        }

        $types = $this->resolvePageTypes($connection, $from, $to, $storeId, $rollCrawl, $rollVisits);
        $crawlRows = $rollCrawl
            ? $this->forSlices($this->buildCrawlRows($connection, $day, $from, $to, $storeId, $types), $crawlModes)
            : [];
        $pathRows = $rollCrawl
            ? $this->forSlices($this->buildPathRows($connection, $day, $from, $to, $storeId, $types), $crawlModes)
            : [];
        $visitRows = $rollVisits
            ? $this->forSlices($this->buildVisitRows($connection, $day, $from, $to, $storeId, $types), $visitModes)
            : [];

        $connection->beginTransaction();
        try {
            if ($rollCrawl) {
                foreach ($this->deleteConditions($day, $storeId, $crawlModes) as $where) {
                    $connection->delete($this->table(self::CRAWL_DAILY_TABLE), $where);
                    $connection->delete($this->table(self::PATH_DAILY_TABLE), $where);
                }
                $this->insertRows($connection, $this->table(self::CRAWL_DAILY_TABLE), $crawlRows);
                $this->insertRows($connection, $this->table(self::PATH_DAILY_TABLE), $pathRows);
            }
            if ($rollVisits) {
                foreach ($this->deleteConditions($day, $storeId, $visitModes) as $where) {
                    $connection->delete($this->table(self::VISIT_DAILY_TABLE), $where);
                }
                $this->insertRows($connection, $this->table(self::VISIT_DAILY_TABLE), $visitRows);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    public function isRawComplete(string $day, string $kind): bool
    {
        $floor = $this->rawFloor($this->resource->getConnection(), $kind);
        return $floor === null || self::normalizeDay($day) >= $floor;
    }

    /**
     * @inheritdoc
     */
    public function raiseRawFloor(string $kind, string $day): void
    {
        $day = self::normalizeDay($day);
        $connection = $this->resource->getConnection();
        $current = $this->rawFloor($connection, $kind);
        if ($current !== null && $current > $day) {
            $day = $current;
        }
        $table = $this->table(self::COUNTER_TABLE);
        $key = self::FLOOR_KEY_PREFIX . $kind;
        $today = gmdate('Y-m-d');
        $connection->insertOnDuplicate(
            $table,
            ['counter_key' => $key, 'day' => $today, 'value' => (int)str_replace('-', '', $day)],
            ['value']
        );
        $connection->delete($table, ['counter_key = ?' => $key, 'day < ?' => $today]);
    }

    /**
     * Stored raw floor day of a raw table, null when retention never deleted raw rows
     *
     * @param AdapterInterface $connection
     * @param string $kind
     * @return string|null
     */
    private function rawFloor(AdapterInterface $connection, string $kind): ?string
    {
        $value = $connection->fetchOne(
            'SELECT MAX(value) FROM ' . $connection->quoteIdentifier($this->table(self::COUNTER_TABLE))
            . ' WHERE counter_key = ?',
            [self::FLOOR_KEY_PREFIX . $kind]
        );
        $value = is_scalar($value) ? (string)$value : '';
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    /**
     * What to do per is_simulated slice of one raw table: is_simulated => rebuild | fill
     *
     * Slices without raw rows are left out (kept as they are). Before the raw floor a slice is only
     * filled when it has no daily rows yet.
     *
     * @param AdapterInterface $connection
     * @param array{0: string, 1: string, 2: string} $tables Kind, raw table, daily table
     * @param string $day
     * @param string $from
     * @param string $to
     * @param int|null $storeId
     * @return array<int, string>
     */
    private function sliceModes(
        AdapterInterface $connection,
        array $tables,
        string $day,
        string $from,
        string $to,
        ?int $storeId
    ): array {
        [$kind, $rawTable, $dailyTable] = $tables;
        $modes = [];
        $complete = null;
        foreach ([0, 1] as $simulated) {
            $bind = [$from, $to, $simulated];
            $sql = 'SELECT 1 FROM ' . $connection->quoteIdentifier($this->table($rawTable))
                . ' WHERE created_at >= ? AND created_at < ? AND is_simulated = ?';
            if ($storeId !== null) {
                $sql .= ' AND store_id = ?';
                $bind[] = $storeId;
            }
            if ($connection->fetchOne($sql . ' LIMIT 1', $bind) === false) {
                continue;
            }
            if ($complete === null) {
                $floor = $this->rawFloor($connection, $kind);
                $complete = $floor === null || $day >= $floor;
            }
            if ($complete) {
                $modes[$simulated] = self::MODE_REBUILD;
                continue;
            }
            $bind = [$day, $simulated];
            $sql = 'SELECT 1 FROM ' . $connection->quoteIdentifier($this->table($dailyTable))
                . ' WHERE day = ? AND is_simulated = ?';
            if ($storeId !== null) {
                $sql .= ' AND store_id = ?';
                $bind[] = $storeId;
            }
            if ($connection->fetchOne($sql . ' LIMIT 1', $bind) === false) {
                $modes[$simulated] = self::MODE_FILL;
            }
        }
        return $modes;
    }

    /**
     * Keep only the rows of the slices that are written
     *
     * @param array $rows
     * @param array<int, string> $modes
     * @return array
     */
    private function forSlices(array $rows, array $modes): array
    {
        if (count($modes) === 2) {
            return $rows;
        }
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset($modes[(int)$row['is_simulated']])
        ));
    }

    /**
     * DELETE conditions for the slices that are rebuilt (one condition when both are)
     *
     * @param string $day
     * @param int|null $storeId
     * @param array<int, string> $modes
     * @return array<int, array<string, mixed>>
     */
    private function deleteConditions(string $day, ?int $storeId, array $modes): array
    {
        $where = ['day = ?' => $day];
        if ($storeId !== null) {
            $where['store_id = ?'] = $storeId;
        }
        $rebuilt = array_keys(array_filter($modes, static fn (string $mode): bool => $mode === self::MODE_REBUILD));
        if (count($rebuilt) === 2) {
            return [$where];
        }
        $conditions = [];
        foreach ($rebuilt as $simulated) {
            $conditions[] = $where + ['is_simulated = ?' => $simulated];
        }
        return $conditions;
    }

    /**
     * Page type and entity of every distinct path of the day: "store|path_hash" => [type, entity_type, entity_id]
     *
     * @param AdapterInterface $connection
     * @param string $from
     * @param string $to
     * @param int|null $storeId
     * @param bool $hits
     * @param bool $visits
     * @return array<string, array{0: string, 1: ?string, 2: ?int}>
     */
    private function resolvePageTypes(
        AdapterInterface $connection,
        string $from,
        string $to,
        ?int $storeId,
        bool $hits,
        bool $visits
    ): array {
        $parts = [];
        $bind = [];
        if ($hits) {
            $parts[] = 'SELECT store_id, path_hash, MIN(path) AS path FROM '
                . $connection->quoteIdentifier($this->table(self::HIT_TABLE))
                . ' WHERE created_at >= ? AND created_at < ? AND category <> ?'
                . ($storeId !== null ? ' AND store_id = ?' : '')
                . ' GROUP BY store_id, path_hash';
            array_push($bind, $from, $to, Category::UNKNOWN);
            if ($storeId !== null) {
                $bind[] = $storeId;
            }
        }
        if ($visits) {
            $parts[] = 'SELECT store_id, path_hash, MIN(landing_path) AS path FROM '
                . $connection->quoteIdentifier($this->table(self::VISIT_TABLE))
                . ' WHERE created_at >= ? AND created_at < ? AND source_code NOT LIKE ?'
                . ($storeId !== null ? ' AND store_id = ?' : '')
                . ' GROUP BY store_id, path_hash';
            array_push($bind, $from, $to, self::CANDIDATE_PREFIX . '%');
            if ($storeId !== null) {
                $bind[] = $storeId;
            }
        }

        $types = [];
        $pending = [];
        $storeCodes = [];
        $statement = $connection->query(implode(' UNION ', $parts), $bind);
        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $store = (int)$row['store_id'];
            $key = $store . '|' . $row['path_hash'];
            if (isset($types[$key])) {
                continue;
            }
            $storeCodes[$store] ??= $this->config->urlStoreCode($store);
            $path = self::stripStoreCode(self::stripIndexPhp((string)$row['path']), $storeCodes[$store]);
            $type = $this->pageTypeResolver->resolve($path);
            if ($type !== PageTypeResolverInterface::TYPE_PAGE) {
                $known = in_array($type, self::PATH_TYPES, true);
                $types[$key] = [$known ? $type : PageTypeResolverInterface::TYPE_OTHER, null, null];
                continue;
            }
            $types[$key] = [PageTypeResolverInterface::TYPE_OTHER, null, null];
            foreach ($this->requestPaths($path) as $requestPath) {
                $pending[$store][mb_strtolower($requestPath)][] = $key;
            }
        }

        foreach ($pending as $store => $paths) {
            $found = $this->lookupRewrites($connection, (int)$store, array_keys($paths));
            foreach ($paths as $requestPath => $keys) {
                $match = $found[(string)$requestPath] ?? null;
                if ($match === null) {
                    continue;
                }
                foreach ($keys as $key) {
                    if ($types[$key][1] === null) {
                        $types[$key] = [$match[0], $match[0], $match[1]];
                    }
                }
            }
        }
        return $types;
    }

    /**
     * Remove a leading "/index.php" front controller ("/index.php/gear.html" -> "/gear.html")
     *
     * @param string $path
     * @return string
     */
    public static function stripIndexPhp(string $path): string
    {
        if (strncasecmp($path, '/index.php', 10) !== 0) {
            return $path;
        }
        $rest = substr($path, 10);
        if ($rest === '' || $rest === false) {
            return '/';
        }
        return $rest[0] === '/' ? $rest : $path;
    }

    /**
     * Remove the leading store code from a URL path ("/en/gear.html" -> "/gear.html", "/en" -> "/")
     *
     * Used when "Add Store Code to Urls" is on, so url_rewrite lookups and page types see the
     * path Magento resolves. Paths that do not start with the code are returned unchanged.
     *
     * @param string $path
     * @param string $storeCode Lower-case store code, '' when URLs carry no store code
     * @return string
     */
    public static function stripStoreCode(string $path, string $storeCode): string
    {
        if ($storeCode === '') {
            return $path;
        }
        $prefix = '/' . $storeCode;
        $length = strlen($prefix);
        if (strncasecmp($path, $prefix, $length) !== 0) {
            return $path;
        }
        $rest = substr($path, $length);
        if ($rest === '' || $rest === false) {
            return '/';
        }
        return $rest[0] === '/' ? $rest : $path;
    }

    /**
     * url_rewrite request_path candidates for a URL path (as stored, and URL-decoded)
     *
     * @param string $path
     * @return string[]
     */
    private function requestPaths(string $path): array
    {
        $result = [];
        $plain = ltrim($path, '/');
        foreach ([$plain, rawurldecode($plain)] as $candidate) {
            if ($candidate !== ''
                && strlen($candidate) <= self::MAX_REQUEST_PATH
                && !in_array($candidate, $result, true)
            ) {
                $result[] = $candidate;
            }
        }
        return $result;
    }

    /**
     * Batched url_rewrite lookup: lower-cased request_path => [entity type, entity id]
     *
     * @param AdapterInterface $connection
     * @param int $storeId
     * @param array $requestPaths
     * @return array<string, array{0: string, 1: int}>
     */
    private function lookupRewrites(AdapterInterface $connection, int $storeId, array $requestPaths): array
    {
        $found = [];
        $redirects = [];
        $table = $connection->quoteIdentifier($this->table(self::URL_REWRITE_TABLE));
        $entityTypes = array_keys(self::ENTITY_TYPES);
        foreach (array_chunk(array_map('strval', $requestPaths), self::LOOKUP_CHUNK) as $chunk) {
            $sql = 'SELECT request_path, entity_type, entity_id, redirect_type FROM ' . $table
                . ' WHERE store_id = ? AND entity_type IN (' . implode(', ', array_fill(0, count($entityTypes), '?'))
                . ') AND request_path IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')';
            $rows = $connection->fetchAll($sql, array_merge([$storeId], $entityTypes, $chunk));
            foreach ($rows as $row) {
                $key = mb_strtolower((string)$row['request_path']);
                $isRedirect = (int)$row['redirect_type'] !== 0;
                if (isset($found[$key]) && ($isRedirect || !$redirects[$key])) {
                    continue;
                }
                $found[$key] = [self::ENTITY_TYPES[(string)$row['entity_type']], (int)$row['entity_id']];
                $redirects[$key] = $isRedirect;
            }
        }
        return $found;
    }

    /**
     * Crawl rows of the day: raw hits grouped by path, folded by page type, plus the overflow counters
     *
     * @param AdapterInterface $connection
     * @param string $day
     * @param string $from
     * @param string $to
     * @param int|null $storeId
     * @param array $types
     * @return array<int, array<string, mixed>>
     */
    private function buildCrawlRows(
        AdapterInterface $connection,
        string $day,
        string $from,
        string $to,
        ?int $storeId,
        array $types
    ): array {
        $rows = [];
        $sql = 'SELECT store_id, is_simulated, bot_code, MAX(category) AS category, path_hash,'
            . ' COUNT(*) AS hits,'
            . ' SUM(CASE WHEN status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS s2xx,'
            . ' SUM(CASE WHEN status BETWEEN 300 AND 399 THEN 1 ELSE 0 END) AS s3xx,'
            . ' SUM(CASE WHEN status BETWEEN 400 AND 499 THEN 1 ELSE 0 END) AS s4xx,'
            . ' SUM(CASE WHEN status BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS s5xx,'
            . ' SUM(duration_ms) AS total_ms, MAX(duration_ms) AS max_ms'
            . ' FROM ' . $connection->quoteIdentifier($this->table(self::HIT_TABLE))
            . ' WHERE created_at >= ? AND created_at < ? AND category <> ?';
        $bind = [$from, $to, Category::UNKNOWN];
        if ($storeId !== null) {
            $sql .= ' AND store_id = ?';
            $bind[] = $storeId;
        }
        $sql .= ' GROUP BY store_id, is_simulated, bot_code, path_hash';

        $statement = $connection->query($sql, $bind);
        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $store = (int)$row['store_id'];
            $pageType = $types[$store . '|' . $row['path_hash']][0] ?? PageTypeResolverInterface::TYPE_OTHER;
            $this->addCrawl(
                $rows,
                $day,
                $store,
                (int)$row['is_simulated'],
                (string)$row['bot_code'],
                (string)$row['category'],
                $pageType,
                [
                    'hits' => (int)$row['hits'],
                    's2xx' => (int)$row['s2xx'],
                    's3xx' => (int)$row['s3xx'],
                    's4xx' => (int)$row['s4xx'],
                    's5xx' => (int)$row['s5xx'],
                    'total_ms' => (int)$row['total_ms'],
                    'max_ms' => (int)$row['max_ms'],
                ]
            );
        }

        $sql = 'SELECT store_id, is_simulated, bot_code, MAX(category) AS category, status_class, SUM(hits) AS hits'
            . ' FROM ' . $connection->quoteIdentifier($this->table(self::OVERFLOW_TABLE))
            . ' WHERE day = ? AND category <> ?';
        $bind = [$day, Category::UNKNOWN];
        if ($storeId !== null) {
            $sql .= ' AND store_id = ?';
            $bind[] = $storeId;
        }
        $sql .= ' GROUP BY store_id, is_simulated, bot_code, status_class';
        foreach ($connection->fetchAll($sql, $bind) as $row) {
            $hits = (int)$row['hits'];
            $class = (int)$row['status_class'];
            $values = ['hits' => $hits, 's2xx' => 0, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0];
            $values += ['total_ms' => 0, 'max_ms' => 0];
            if ($class >= 2 && $class <= 5) {
                $values['s' . $class . 'xx'] = $hits;
            }
            $this->addCrawl(
                $rows,
                $day,
                (int)$row['store_id'],
                (int)$row['is_simulated'],
                (string)$row['bot_code'],
                (string)$row['category'],
                PageTypeResolverInterface::TYPE_OTHER,
                $values
            );
        }
        return array_values($rows);
    }

    /**
     * Add counts to a crawl row keyed by (store, simulated, bot, page type)
     *
     * @param array $rows
     * @param string $day
     * @param int $store
     * @param int $simulated
     * @param string $botCode
     * @param string $category
     * @param string $pageType
     * @param array $values
     * @return void
     */
    private function addCrawl(
        array &$rows,
        string $day,
        int $store,
        int $simulated,
        string $botCode,
        string $category,
        string $pageType,
        array $values
    ): void {
        $key = $store . '|' . $simulated . '|' . $botCode . '|' . $pageType;
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'day' => $day,
                'store_id' => $store,
                'bot_code' => $botCode,
                'category' => $category,
                'page_type' => $pageType,
                'hits' => 0,
                's2xx' => 0,
                's3xx' => 0,
                's4xx' => 0,
                's5xx' => 0,
                'total_ms' => 0,
                'max_ms' => 0,
                'is_simulated' => $simulated,
            ];
        } elseif (strcmp($category, $rows[$key]['category']) > 0) {
            // Same rule as MAX(category) in SQL when a bot changed category during the day.
            $rows[$key]['category'] = $category;
        }
        foreach (['hits', 's2xx', 's3xx', 's4xx', 's5xx', 'total_ms'] as $field) {
            $rows[$key][$field] += $values[$field];
        }
        $rows[$key]['max_ms'] = max($rows[$key]['max_ms'], $values['max_ms']);
    }

    /**
     * Top paths of the day per (store, category, simulated), most hits first, ties by path hash
     *
     * @param AdapterInterface $connection
     * @param string $day
     * @param string $from
     * @param string $to
     * @param int|null $storeId
     * @param array $types
     * @return array<int, array<string, mixed>>
     */
    private function buildPathRows(
        AdapterInterface $connection,
        string $day,
        string $from,
        string $to,
        ?int $storeId,
        array $types
    ): array {
        $sql = 'SELECT store_id, is_simulated, category, path_hash, MIN(path) AS path, COUNT(*) AS hits'
            . ' FROM ' . $connection->quoteIdentifier($this->table(self::HIT_TABLE))
            . ' WHERE created_at >= ? AND created_at < ? AND category <> ?';
        $bind = [$from, $to, Category::UNKNOWN];
        if ($storeId !== null) {
            $sql .= ' AND store_id = ?';
            $bind[] = $storeId;
        }
        $sql .= ' GROUP BY store_id, is_simulated, category, path_hash'
            . ' ORDER BY store_id, is_simulated, category, hits DESC, path_hash';

        $rows = [];
        $counts = [];
        $statement = $connection->query($sql, $bind);
        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $store = (int)$row['store_id'];
            $group = $store . '|' . (int)$row['is_simulated'] . '|' . $row['category'];
            $counts[$group] = ($counts[$group] ?? 0) + 1;
            if ($counts[$group] > self::PATHS_PER_GROUP) {
                continue;
            }
            $type = $types[$store . '|' . $row['path_hash']] ?? null;
            $rows[] = [
                'day' => $day,
                'store_id' => $store,
                'category' => (string)$row['category'],
                'path_hash' => (string)$row['path_hash'],
                'path' => mb_substr((string)$row['path'], 0, 512),
                'entity_type' => $type[1] ?? null,
                'entity_id' => $type[2] ?? null,
                'hits' => (int)$row['hits'],
                'is_simulated' => (int)$row['is_simulated'],
            ];
        }
        return $rows;
    }

    /**
     * Visit rows of the day: confirmed sources only, folded by landing page type
     *
     * @param AdapterInterface $connection
     * @param string $day
     * @param string $from
     * @param string $to
     * @param int|null $storeId
     * @param array $types
     * @return array<int, array<string, mixed>>
     */
    private function buildVisitRows(
        AdapterInterface $connection,
        string $day,
        string $from,
        string $to,
        ?int $storeId,
        array $types
    ): array {
        $sql = 'SELECT store_id, is_simulated, source_code, path_hash, COUNT(*) AS visits'
            . ' FROM ' . $connection->quoteIdentifier($this->table(self::VISIT_TABLE))
            . ' WHERE created_at >= ? AND created_at < ? AND source_code NOT LIKE ?';
        $bind = [$from, $to, self::CANDIDATE_PREFIX . '%'];
        if ($storeId !== null) {
            $sql .= ' AND store_id = ?';
            $bind[] = $storeId;
        }
        $sql .= ' GROUP BY store_id, is_simulated, source_code, path_hash';

        $rows = [];
        $statement = $connection->query($sql, $bind);
        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $store = (int)$row['store_id'];
            $simulated = (int)$row['is_simulated'];
            $source = (string)$row['source_code'];
            $pageType = $types[$store . '|' . $row['path_hash']][0] ?? PageTypeResolverInterface::TYPE_OTHER;
            $key = $store . '|' . $simulated . '|' . $source . '|' . $pageType;
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'day' => $day,
                    'store_id' => $store,
                    'source_code' => $source,
                    'page_type' => $pageType,
                    'visits' => 0,
                    'is_simulated' => $simulated,
                ];
            }
            $rows[$key]['visits'] += (int)$row['visits'];
        }
        return array_values($rows);
    }

    /**
     * Multi-row insert in chunks
     *
     * @param AdapterInterface $connection
     * @param string $table
     * @param array $rows
     * @return void
     */
    private function insertRows(AdapterInterface $connection, string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $connection->insertMultiple($table, $chunk);
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
