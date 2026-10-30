<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Verification;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Model\Source\AttributionModel;
use Panth\AiVisibility\Model\Source\Category;

/**
 * Per-subject metric values for verification, read from the daily summary tables where they hold the
 * figure and from the raw tables only where they do not.
 *
 * Subject refs: 'site'; a path ('/...'); a product ID ('42' or 'product:42'); 'category:42'; 'bot:<code>';
 * 'source:<code>'. A plain code without a prefix is a bot code for crawler metrics and a source code for
 * visit / order metrics.
 *
 * Sources (see sources()):
 * - bot_hits (training, search, live, other), search_bot_hits (search + live answer bots), live_fetches,
 *   llms_fetches (page type llms): panth_aiv_crawl_daily for site / bot, panth_aiv_path_daily for paths,
 *   panth_aiv_entity_daily (uncapped) for products and categories.
 * - bot_404 (client errors: all 4xx responses, which are mostly 404s), bot_5xx, bot_avg_ms:
 *   panth_aiv_crawl_daily for site / bot (average = total ms / timed hits; days rolled up before 1.1 have
 *   no timed-hit count and are left out of the average); panth_aiv_path_daily s4xx / s5xx / total_ms /
 *   timed_hits for paths, products and categories. Beyond the daily top paths the rollup keeps paths with
 *   at least 3 error answers or 3 slow timed hits (at most Rollup::MAX_EXTRA_PATHS per store and day), so
 *   per-path error counts of long-tail paths with one or two errors a day are not complete.
 * - ai_visits: panth_aiv_visit_daily for site / source; panth_aiv_visit_path_daily (uncapped, by landing
 *   path hash, products / categories by the entity the rollup resolved) otherwise.
 * - ai_orders: panth_aiv_order joined to sales_order (not canceled, by order date); a source matches
 *   the first or last touch per general/attribution_model; paths match the landing path.
 * - ai_conversion: ai_orders / ai_visits.
 * - canonical_crawl_share: AI crawler hits without a query string / AI crawler hits with or without one:
 *   panth_aiv_crawl_daily canonical_hits / query_hits for site / bot (days rolled up before 1.1 have no
 *   counts and are left out); raw panth_aiv_hit for paths, products and categories.
 * - candidate_backlog (site only): discovery candidates with traffic that, at the end of the range, had
 *   been waiting more than 7 days without a decision. Confirmed candidates count as decided from their
 *   rule's created_at; "not AI" / "ignored" carry no decision time and are left out of both windows
 *   (this can only understate an improvement).
 * For raw-hit and order metrics products and categories map to paths through url_rewrite and
 * panth_aiv_path_daily.
 */
class MetricProvider implements MetricProviderInterface
{
    public const SOURCE_CRAWL_DAILY = 'crawl_daily';
    public const SOURCE_PATH_DAILY = 'path_daily';
    public const SOURCE_RAW_HIT = 'raw_hit';
    public const SOURCE_VISIT_DAILY = 'visit_daily';
    public const SOURCE_RAW_VISIT = 'raw_visit';
    public const SOURCE_ORDER = 'order';
    public const SOURCE_CANDIDATE = 'candidate';
    public const SOURCE_VISIT_PATH_DAILY = 'visit_path_daily';

    /**
     * Sources read from the daily summary tables (pruned by the daily retention in months)
     */
    public const DAILY_SOURCES = [
        self::SOURCE_CRAWL_DAILY,
        self::SOURCE_PATH_DAILY,
        self::SOURCE_VISIT_DAILY,
        self::SOURCE_VISIT_PATH_DAILY,
    ];

    public const CRAWL_TABLE = 'panth_aiv_crawl_daily';
    public const PATH_TABLE = 'panth_aiv_path_daily';
    public const ENTITY_TABLE = 'panth_aiv_entity_daily';
    public const HIT_TABLE = 'panth_aiv_hit';
    public const VISIT_DAILY_TABLE = 'panth_aiv_visit_daily';
    public const VISIT_TABLE = 'panth_aiv_visit';
    public const VISIT_PATH_TABLE = 'panth_aiv_visit_path_daily';
    public const ORDER_TABLE = 'panth_aiv_order';
    public const SALES_ORDER_TABLE = 'sales_order';
    public const CANDIDATE_TABLE = 'panth_aiv_candidate';
    public const RULE_TABLE = 'panth_aiv_rule';
    public const URL_REWRITE_TABLE = 'url_rewrite';

    /**
     * Single source of per-product / per-category crawl counts (pathDailyValues() and SubjectPool read it).
     * panth_aiv_entity_daily is uncapped, unlike panth_aiv_path_daily (top 500 paths per day, store and
     * category), so long-tail products are counted exactly. Path subjects still read panth_aiv_path_daily.
     */
    public const SUBJECT_CRAWL_TABLE = self::ENTITY_TABLE;

    /**
     * Days a candidate must wait before it counts as backlog
     */
    public const BACKLOG_DAYS = 7;

    private const STATE_CANCELED = 'canceled';
    private const SEARCH_CATEGORIES = [Category::SEARCH, Category::LIVE];

    /**
     * @param ResourceConnection $resource
     * @param Config $config
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config
    ) {
    }

    /**
     * @inheritdoc
     */
    public function supports(string $metric): bool
    {
        return in_array($metric, self::METRICS, true);
    }

    /**
     * Data sources a metric reads for a subject type; empty when the pair is not supported
     *
     * @param string $metric
     * @param string $subjectType Finding::SUBJECT_*
     * @return string[]
     */
    public static function sources(string $metric, string $subjectType): array
    {
        $entity = in_array($subjectType, [Finding::SUBJECT_PATH, Finding::SUBJECT_PRODUCT, Finding::SUBJECT_CATEGORY], true);
        $siteOrBot = in_array($subjectType, [Finding::SUBJECT_SITE, Finding::SUBJECT_BOT], true);
        $siteOrSource = in_array($subjectType, [Finding::SUBJECT_SITE, Finding::SUBJECT_SOURCE], true);
        switch ($metric) {
            case self::METRIC_BOT_HITS:
            case self::METRIC_SEARCH_BOT_HITS:
            case self::METRIC_LIVE_FETCHES:
                return $siteOrBot ? [self::SOURCE_CRAWL_DAILY] : ($entity ? [self::SOURCE_PATH_DAILY] : []);
            case self::METRIC_LLMS_FETCHES:
                return $siteOrBot ? [self::SOURCE_CRAWL_DAILY]
                    : ($subjectType === Finding::SUBJECT_PATH ? [self::SOURCE_PATH_DAILY] : []);
            case self::METRIC_BOT_404:
            case self::METRIC_BOT_5XX:
            case self::METRIC_BOT_AVG_MS:
                return $siteOrBot ? [self::SOURCE_CRAWL_DAILY] : ($entity ? [self::SOURCE_PATH_DAILY] : []);
            case self::METRIC_AI_VISITS:
                return $siteOrSource ? [self::SOURCE_VISIT_DAILY] : ($entity ? [self::SOURCE_VISIT_PATH_DAILY] : []);
            case self::METRIC_AI_ORDERS:
                return ($siteOrSource || $entity) ? [self::SOURCE_ORDER] : [];
            case self::METRIC_AI_CONVERSION:
                return $siteOrSource ? [self::SOURCE_ORDER, self::SOURCE_VISIT_DAILY]
                    : ($entity ? [self::SOURCE_ORDER, self::SOURCE_VISIT_PATH_DAILY] : []);
            case self::METRIC_CANONICAL_CRAWL_SHARE:
                return $siteOrBot ? [self::SOURCE_CRAWL_DAILY] : ($entity ? [self::SOURCE_RAW_HIT] : []);
            case self::METRIC_CANDIDATE_BACKLOG:
                return $subjectType === Finding::SUBJECT_SITE ? [self::SOURCE_CANDIDATE] : [];
            default:
                return [];
        }
    }

    /**
     * Whether a metric is a ratio (num / den) rather than a count
     *
     * @param string $metric
     * @return bool
     */
    public static function isRatio(string $metric): bool
    {
        return in_array($metric, self::RATIO_METRICS, true);
    }

    /**
     * Subject ref with an explicit type prefix (paths and 'site' stay as they are)
     *
     * @param string $subjectType
     * @param string $ref
     * @return string
     */
    public static function typedRef(string $subjectType, string $ref): string
    {
        if (in_array($subjectType, [Finding::SUBJECT_SITE, Finding::SUBJECT_PATH], true)) {
            return $ref;
        }
        $prefix = $subjectType . ':';
        return strncmp($ref, $prefix, strlen($prefix)) === 0 ? $ref : $prefix . $ref;
    }

    /**
     * Subject type and key of a ref
     *
     * @param string $ref
     * @param string $metric Decides what a plain code is (bot for crawler metrics, source otherwise)
     * @return string[] [type, key]
     */
    public static function parseRef(string $ref, string $metric): array
    {
        if ($ref === Finding::SUBJECT_SITE) {
            return [Finding::SUBJECT_SITE, ''];
        }
        if ($ref !== '' && $ref[0] === '/') {
            return [Finding::SUBJECT_PATH, $ref];
        }
        if (ctype_digit($ref)) {
            return [Finding::SUBJECT_PRODUCT, $ref];
        }
        $pos = strpos($ref, ':');
        if ($pos !== false) {
            $type = substr($ref, 0, $pos);
            if (in_array($type, Finding::SUBJECT_TYPES, true) && $type !== Finding::SUBJECT_SITE) {
                return [$type, substr($ref, $pos + 1)];
            }
        }
        $crawler = !in_array(
            $metric,
            [self::METRIC_AI_VISITS, self::METRIC_AI_ORDERS, self::METRIC_AI_CONVERSION],
            true
        );
        return [$crawler ? Finding::SUBJECT_BOT : Finding::SUBJECT_SOURCE, $ref];
    }

    /**
     * @inheritdoc
     */
    public function value(
        string $metric,
        int $storeId,
        array $subjects,
        string $fromDay,
        string $toDay,
        bool $simulated = false
    ): array {
        if (!$this->supports($metric)) {
            throw new \InvalidArgumentException(sprintf('Unsupported metric "%s".', $metric));
        }
        self::assertDay($fromDay);
        self::assertDay($toDay);
        if ($fromDay > $toDay) {
            throw new \InvalidArgumentException('The range starts after it ends.');
        }
        $ratio = self::isRatio($metric);
        $result = [];
        $groups = [];
        foreach ($subjects as $subject) {
            $ref = (string)$subject;
            if (isset($result[$ref])) {
                continue;
            }
            $result[$ref] = ['num' => 0.0, 'den' => $ratio ? 0.0 : 1.0];
            [$type, $key] = self::parseRef($ref, $metric);
            if ($key === '' && $type !== Finding::SUBJECT_SITE) {
                continue;
            }
            $groups[$type][$ref] = $key;
        }
        $range = [$fromDay, $toDay, $storeId, $simulated];
        foreach ($groups as $type => $keys) {
            if (self::sources($metric, $type) === []) {
                continue;
            }
            foreach ($this->groupValues($metric, $type, $keys, $range) as $ref => $pair) {
                $result[$ref] = $ratio
                    ? ['num' => (float)$pair[0], 'den' => (float)$pair[1]]
                    : ['num' => (float)$pair[0], 'den' => 1.0];
            }
        }
        return $result;
    }

    /**
     * Values of one subject type
     *
     * @param string $metric
     * @param string $type
     * @param array<string, string> $keys ref => key
     * @param array $range [from, to, storeId, simulated]
     * @return array<string, float[]> ref => [num, den]
     */
    private function groupValues(string $metric, string $type, array $keys, array $range): array
    {
        switch ($metric) {
            case self::METRIC_BOT_HITS:
            case self::METRIC_SEARCH_BOT_HITS:
            case self::METRIC_LIVE_FETCHES:
            case self::METRIC_LLMS_FETCHES:
                return in_array($type, [Finding::SUBJECT_SITE, Finding::SUBJECT_BOT], true)
                    ? $this->crawlValues($metric, $type, $keys, $range)
                    : $this->pathDailyValues($metric, $type, $keys, $range);
            case self::METRIC_BOT_404:
            case self::METRIC_BOT_5XX:
            case self::METRIC_BOT_AVG_MS:
                return in_array($type, [Finding::SUBJECT_SITE, Finding::SUBJECT_BOT], true)
                    ? $this->crawlValues($metric, $type, $keys, $range)
                    : $this->pathStatusValues($metric, $type, $keys, $range);
            case self::METRIC_CANONICAL_CRAWL_SHARE:
                return in_array($type, [Finding::SUBJECT_SITE, Finding::SUBJECT_BOT], true)
                    ? $this->crawlValues($metric, $type, $keys, $range)
                    : $this->rawHitValues($metric, $type, $keys, $range);
            case self::METRIC_AI_VISITS:
                return $this->visitValues($type, $keys, $range);
            case self::METRIC_AI_ORDERS:
                return $this->orderValues($type, $keys, $range);
            case self::METRIC_AI_CONVERSION:
                $orders = $this->orderValues($type, $keys, $range);
                $visits = $this->visitValues($type, $keys, $range);
                $out = [];
                foreach ($keys as $ref => $key) {
                    $out[$ref] = [$orders[$ref][0] ?? 0.0, $visits[$ref][0] ?? 0.0];
                }
                return $out;
            case self::METRIC_CANDIDATE_BACKLOG:
                return $this->backlogValues($keys, $range);
            default:
                return [];
        }
    }

    /**
     * Site / bot values from panth_aiv_crawl_daily
     *
     * @param string $metric
     * @param string $type
     * @param array<string, string> $keys
     * @param array $range
     * @return array<string, float[]>
     */
    private function crawlValues(string $metric, string $type, array $keys, array $range): array
    {
        // Rows rolled up before 1.1 have NULL timed / query counts: they are left out of the average
        // response time and the canonical share instead of being read as untimed or canonical.
        [$numExpr, $denExpr] = match ($metric) {
            self::METRIC_BOT_404 => ['d.s4xx', 'd.hits'],
            self::METRIC_BOT_5XX => ['d.s5xx', 'd.hits'],
            self::METRIC_BOT_AVG_MS => ['CASE WHEN d.timed_hits IS NULL THEN 0 ELSE d.total_ms END', 'd.timed_hits'],
            self::METRIC_CANONICAL_CRAWL_SHARE => [
                'CASE WHEN d.query_hits IS NULL THEN 0 ELSE d.canonical_hits END',
                'CASE WHEN d.canonical_hits IS NULL OR d.query_hits IS NULL THEN 0'
                    . ' ELSE d.canonical_hits + d.query_hits END',
            ],
            default => ['d.hits', 'd.hits'],
        };
        $connection = $this->connection();
        $select = $connection->select()->from(
            ['d' => $this->table(self::CRAWL_TABLE)],
            [
                'k' => $type === Finding::SUBJECT_BOT ? 'd.bot_code' : new Expression("''"),
                'n' => new Expression('COALESCE(SUM(' . $numExpr . '), 0)'),
                'h' => new Expression('COALESCE(SUM(' . $denExpr . '), 0)'),
            ]
        );
        $select->where('d.category IN (?)', $this->categories($metric));
        if ($metric === self::METRIC_LLMS_FETCHES) {
            $select->where('d.page_type = ?', PageTypeResolverInterface::TYPE_LLMS);
        }
        $this->dailyFilters($select, 'd', $range);
        if ($type === Finding::SUBJECT_BOT) {
            $select->where('d.bot_code IN (?)', array_values(array_unique($keys)))->group('d.bot_code');
        }
        $rows = $this->rowsByKey($connection->fetchAll($select));
        $out = [];
        foreach ($keys as $ref => $key) {
            $row = $rows[$type === Finding::SUBJECT_BOT ? $key : ''] ?? null;
            if ($row !== null) {
                $out[$ref] = [(float)$row['n'], (float)$row['h']];
            }
        }
        return $out;
    }

    /**
     * Path crawl counts from panth_aiv_path_daily, product / category counts from panth_aiv_entity_daily
     *
     * @param string $metric
     * @param string $type
     * @param array<string, string> $keys
     * @param array $range
     * @return array<string, float[]>
     */
    private function pathDailyValues(string $metric, string $type, array $keys, array $range): array
    {
        $connection = $this->connection();
        $isPath = $type === Finding::SUBJECT_PATH;
        $select = $connection->select()->from(
            ['p' => $this->table($isPath ? self::PATH_TABLE : self::SUBJECT_CRAWL_TABLE)],
            [
                'k' => $isPath ? 'p.path_hash' : 'p.entity_id',
                'n' => new Expression('COALESCE(SUM(p.hits), 0)'),
            ]
        );
        $select->where('p.category IN (?)', $this->categories($metric));
        $this->dailyFilters($select, 'p', $range);
        if ($isPath) {
            $hashes = [];
            foreach ($keys as $ref => $path) {
                $hashes[$ref] = sha1($path);
            }
            $select->where('p.path_hash IN (?)', array_values(array_unique($hashes)))->group('p.path_hash');
        } else {
            $hashes = $keys;
            $select->where('p.entity_type = ?', $type)
                ->where('p.entity_id IN (?)', array_map('intval', array_values(array_unique($keys))))
                ->group('p.entity_id');
        }
        $rows = $this->rowsByKey($connection->fetchAll($select));
        $out = [];
        foreach ($hashes as $ref => $key) {
            if (isset($rows[(string)$key])) {
                $out[$ref] = [(float)$rows[(string)$key]['n'], 1.0];
            }
        }
        return $out;
    }

    /**
     * Client errors, server errors and response time per path / product / category from panth_aiv_path_daily
     *
     * @param string $metric
     * @param string $type
     * @param array<string, string> $keys
     * @param array $range
     * @return array<string, float[]>
     */
    private function pathStatusValues(string $metric, string $type, array $keys, array $range): array
    {
        $connection = $this->connection();
        $isPath = $type === Finding::SUBJECT_PATH;
        $select = $connection->select()->from(
            ['p' => $this->table(self::PATH_TABLE)],
            [
                'k' => $isPath ? 'p.path_hash' : 'p.entity_id',
                'c4' => new Expression('COALESCE(SUM(p.s4xx), 0)'),
                'c5' => new Expression('COALESCE(SUM(p.s5xx), 0)'),
                'ms' => new Expression('COALESCE(SUM(p.total_ms), 0)'),
                'timed' => new Expression('COALESCE(SUM(p.timed_hits), 0)'),
            ]
        );
        $select->where('p.category IN (?)', Category::BOT_CATEGORIES);
        $this->dailyFilters($select, 'p', $range);
        if ($isPath) {
            $refKeys = [];
            foreach ($keys as $ref => $path) {
                $refKeys[$ref] = sha1($path);
            }
            $select->where('p.path_hash IN (?)', array_values(array_unique($refKeys)))->group('p.path_hash');
        } else {
            $refKeys = $keys;
            $select->where('p.entity_type = ?', $type)
                ->where('p.entity_id IN (?)', array_map('intval', array_values(array_unique($keys))))
                ->group('p.entity_id');
        }
        $rows = $this->rowsByKey($connection->fetchAll($select));
        $out = [];
        foreach ($refKeys as $ref => $key) {
            $row = $rows[(string)$key] ?? null;
            if ($row === null) {
                continue;
            }
            $out[$ref] = match ($metric) {
                self::METRIC_BOT_404 => [(float)$row['c4'], 1.0],
                self::METRIC_BOT_5XX => [(float)$row['c5'], 1.0],
                default => [(float)$row['ms'], (float)$row['timed']],
            };
        }
        return $out;
    }

    /**
     * Canonical-share values (and raw status / timing values) from raw panth_aiv_hit
     *
     * @param string $metric
     * @param string $type
     * @param array<string, string> $keys
     * @param array $range
     * @return array<string, float[]>
     */
    private function rawHitValues(string $metric, string $type, array $keys, array $range): array
    {
        [$from, $to, $storeId, $simulated] = $range;
        $connection = $this->connection();
        $refHashes = [];
        $keyColumn = new Expression("''");
        if ($type === Finding::SUBJECT_BOT) {
            $keyColumn = 'h.bot_code';
            foreach ($keys as $ref => $key) {
                $refHashes[$ref] = [$key];
            }
        } elseif ($type !== Finding::SUBJECT_SITE) {
            $keyColumn = 'h.path_hash';
            $refHashes = $this->refPathHashes($type, $keys, $storeId);
        }
        $select = $connection->select()->from(
            ['h' => $this->table(self::HIT_TABLE)],
            [
                'k' => $keyColumn,
                'total' => new Expression('COUNT(*)'),
                'c4' => new Expression('COALESCE(SUM(CASE WHEN h.status BETWEEN 400 AND 499 THEN 1 ELSE 0 END), 0)'),
                'c5' => new Expression('COALESCE(SUM(CASE WHEN h.status >= 500 THEN 1 ELSE 0 END), 0)'),
                'ms' => new Expression('COALESCE(SUM(h.duration_ms), 0)'),
                'canon' => new Expression('COALESCE(SUM(CASE WHEN h.has_query = 0 THEN 1 ELSE 0 END), 0)'),
            ]
        )
            ->where('h.created_at >= ?', $from . ' 00:00:00')
            ->where('h.created_at < ?', self::nextDay($to) . ' 00:00:00')
            ->where('h.category IN (?)', Category::BOT_CATEGORIES)
            ->where('h.is_simulated = ?', $simulated ? 1 : 0);
        if ($storeId > 0) {
            $select->where('h.store_id = ?', $storeId);
        }
        if ($type !== Finding::SUBJECT_SITE) {
            $all = array_values(array_unique(array_merge([], ...array_values($refHashes))));
            if ($all === []) {
                return [];
            }
            $column = $type === Finding::SUBJECT_BOT ? 'h.bot_code' : 'h.path_hash';
            $select->where($column . ' IN (?)', $all)->group($column);
        } else {
            $refHashes = array_fill_keys(array_keys($keys), ['']);
        }
        $rows = $this->rowsByKey($connection->fetchAll($select));
        $out = [];
        foreach ($refHashes as $ref => $hashes) {
            $sum = ['total' => 0.0, 'c4' => 0.0, 'c5' => 0.0, 'ms' => 0.0, 'canon' => 0.0];
            $found = false;
            foreach ($hashes as $hash) {
                if (!isset($rows[$hash])) {
                    continue;
                }
                $found = true;
                foreach ($sum as $field => $current) {
                    $sum[$field] = $current + (float)$rows[$hash][$field];
                }
            }
            if (!$found) {
                continue;
            }
            $out[$ref] = match ($metric) {
                self::METRIC_BOT_404 => [$sum['c4'], 1.0],
                self::METRIC_BOT_5XX => [$sum['c5'], 1.0],
                self::METRIC_BOT_AVG_MS => [$sum['ms'], $sum['total']],
                default => [$sum['canon'], $sum['total']],
            };
        }
        return $out;
    }

    /**
     * AI visits (daily table for site / source, raw visits by landing path otherwise)
     *
     * @param string $type
     * @param array<string, string> $keys
     * @param array $range
     * @return array<string, float[]>
     */
    private function visitValues(string $type, array $keys, array $range): array
    {
        $connection = $this->connection();
        if (in_array($type, [Finding::SUBJECT_SITE, Finding::SUBJECT_SOURCE], true)) {
            $isSource = $type === Finding::SUBJECT_SOURCE;
            $select = $connection->select()->from(
                ['v' => $this->table(self::VISIT_DAILY_TABLE)],
                [
                    'k' => $isSource ? 'v.source_code' : new Expression("''"),
                    'n' => new Expression('COALESCE(SUM(v.visits), 0)'),
                ]
            );
            $this->dailyFilters($select, 'v', $range);
            if ($isSource) {
                $select->where('v.source_code IN (?)', array_values(array_unique($keys)))->group('v.source_code');
            }
            $rows = $this->rowsByKey($connection->fetchAll($select));
            $out = [];
            foreach ($keys as $ref => $key) {
                $row = $rows[$isSource ? $key : ''] ?? null;
                if ($row !== null) {
                    $out[$ref] = [(float)$row['n'], 1.0];
                }
            }
            return $out;
        }
        if ($type === Finding::SUBJECT_BOT) {
            return [];
        }
        // Paths by hash, products / categories by the entity the rollup resolved (uncapped daily table).
        $isPath = $type === Finding::SUBJECT_PATH;
        $select = $connection->select()->from(
            ['v' => $this->table(self::VISIT_PATH_TABLE)],
            [
                'k' => $isPath ? 'v.path_hash' : 'v.entity_id',
                'n' => new Expression('COALESCE(SUM(v.visits), 0)'),
            ]
        );
        $this->dailyFilters($select, 'v', $range);
        if ($isPath) {
            $refKeys = [];
            foreach ($keys as $ref => $path) {
                $refKeys[$ref] = sha1($path);
            }
            $select->where('v.path_hash IN (?)', array_values(array_unique($refKeys)))->group('v.path_hash');
        } else {
            $refKeys = [];
            foreach ($keys as $ref => $id) {
                $refKeys[$ref] = (string)(int)$id;
            }
            $select->where('v.entity_type = ?', $type)
                ->where('v.entity_id IN (?)', array_map('intval', array_values(array_unique($refKeys))))
                ->group('v.entity_id');
        }
        $rows = $this->rowsByKey($connection->fetchAll($select));
        $out = [];
        foreach ($refKeys as $ref => $key) {
            if (isset($rows[$key])) {
                $out[$ref] = [(float)$rows[$key]['n'], 1.0];
            }
        }
        return $out;
    }

    /**
     * Attributed, not canceled AI orders by order date
     *
     * @param string $type
     * @param array<string, string> $keys
     * @param array $range
     * @return array<string, float[]>
     */
    private function orderValues(string $type, array $keys, array $range): array
    {
        [$from, $to, $storeId, $simulated] = $range;
        if ($type === Finding::SUBJECT_BOT) {
            return [];
        }
        $connection = $this->connection();
        $refKeys = [];
        $column = null;
        if ($type === Finding::SUBJECT_SOURCE) {
            $column = $this->config->attributionModel() === AttributionModel::LAST ? 'o.last_source' : 'o.first_source';
            foreach ($keys as $ref => $key) {
                $refKeys[$ref] = [$key];
            }
        } elseif ($type !== Finding::SUBJECT_SITE) {
            $column = 'o.landing_path';
            $refKeys = $type === Finding::SUBJECT_PATH
                ? array_map(static fn (string $path): array => [$path], $keys)
                : $this->entityPaths($type, $keys, $storeId);
        }
        $select = $connection->select()
            ->from(
                ['o' => $this->table(self::ORDER_TABLE)],
                ['k' => $column ?? new Expression("''"), 'n' => new Expression('COUNT(*)')]
            )
            ->join(['so' => $this->table(self::SALES_ORDER_TABLE)], 'so.entity_id = o.order_id', [])
            ->where('so.created_at >= ?', $from . ' 00:00:00')
            ->where('so.created_at < ?', self::nextDay($to) . ' 00:00:00')
            ->where('(so.state IS NULL OR so.state <> ?)', self::STATE_CANCELED)
            ->where('o.is_simulated = ?', $simulated ? 1 : 0);
        if ($storeId > 0) {
            $select->where('so.store_id = ?', $storeId);
        }
        if ($column !== null) {
            $all = array_values(array_unique(array_merge([], ...array_values($refKeys))));
            if ($all === []) {
                return [];
            }
            $select->where($column . ' IN (?)', $all)->group($column);
        } else {
            $refKeys = array_fill_keys(array_keys($keys), ['']);
        }
        return $this->sumByRef($refKeys, $this->rowsByKey($connection->fetchAll($select)));
    }

    /**
     * Discovery backlog at the end of the range (site only)
     *
     * @param array<string, string> $keys
     * @param array $range
     * @return array<string, float[]>
     */
    private function backlogValues(array $keys, array $range): array
    {
        [, $to, , $simulated] = $range;
        $end = self::nextDay($to) . ' 00:00:00';
        $waitingSince = gmdate('Y-m-d H:i:s', (int)strtotime($end . ' UTC') - self::BACKLOG_DAYS * 86400);
        $connection = $this->connection();
        $decidedLater = $connection->select()
            ->from(['r' => $this->table(self::RULE_TABLE)], [new Expression('1')])
            ->where('r.candidate_id = c.candidate_id')
            ->where('r.created_at >= ?', $end);
        $select = $connection->select()
            ->from(['c' => $this->table(self::CANDIDATE_TABLE)], ['n' => new Expression('COUNT(*)')])
            ->where('c.is_simulated = ?', $simulated ? 1 : 0)
            ->where('c.hits > 0')
            ->where('c.first_seen < ?', $waitingSince)
            ->where(
                "c.status = 'new' OR (c.status = 'confirmed' AND EXISTS (" . $decidedLater->assemble() . '))'
            );
        $count = (float)$connection->fetchOne($select);
        $out = [];
        foreach (array_keys($keys) as $ref) {
            $out[$ref] = [$count, 1.0];
        }
        return $out;
    }

    /**
     * Path hashes per ref (paths hash directly, products / categories through their URLs)
     *
     * @param string $type
     * @param array<string, string> $keys
     * @param int $storeId
     * @return array<string, string[]>
     */
    private function refPathHashes(string $type, array $keys, int $storeId): array
    {
        $paths = $type === Finding::SUBJECT_PATH
            ? array_map(static fn (string $path): array => [$path], $keys)
            : $this->entityPaths($type, $keys, $storeId);
        $out = [];
        foreach ($paths as $ref => $list) {
            $out[$ref] = array_values(array_unique(array_map('sha1', $list)));
        }
        return $out;
    }

    /**
     * URL paths of products / categories, from url_rewrite and from rolled-up crawler paths
     *
     * @param string $type product | category
     * @param array<string, string> $keys ref => entity ID
     * @param int $storeId
     * @return array<string, string[]> ref => paths
     */
    private function entityPaths(string $type, array $keys, int $storeId): array
    {
        $ids = array_values(array_unique(array_map('intval', $keys)));
        if ($ids === []) {
            return [];
        }
        $connection = $this->connection();
        $byId = [];
        $rewrite = $connection->select()
            ->from(['u' => $this->table(self::URL_REWRITE_TABLE)], ['id' => 'u.entity_id', 'path' => 'u.request_path'])
            ->where('u.entity_type = ?', $type)
            ->where('u.entity_id IN (?)', $ids);
        if ($storeId > 0) {
            $rewrite->where('u.store_id = ?', $storeId);
        }
        foreach ($connection->fetchAll($rewrite) as $row) {
            $byId[(int)$row['id']]['/' . ltrim((string)$row['path'], '/')] = true;
        }
        $daily = $connection->select()
            ->distinct(true)
            ->from(['p' => $this->table(self::PATH_TABLE)], ['id' => 'p.entity_id', 'path' => 'p.path'])
            ->where('p.entity_type = ?', $type)
            ->where('p.entity_id IN (?)', $ids);
        if ($storeId > 0) {
            $daily->where('p.store_id = ?', $storeId);
        }
        foreach ($connection->fetchAll($daily) as $row) {
            $byId[(int)$row['id']][(string)$row['path']] = true;
        }
        $out = [];
        foreach ($keys as $ref => $id) {
            $out[$ref] = array_map('strval', array_keys($byId[(int)$id] ?? []));
        }
        return $out;
    }

    /**
     * Sum fetched counts per ref over its keys
     *
     * @param array<string, string[]> $refKeys
     * @param array<string, array> $rows key => row with 'n'
     * @return array<string, float[]>
     */
    private function sumByRef(array $refKeys, array $rows): array
    {
        $out = [];
        foreach ($refKeys as $ref => $list) {
            $sum = 0.0;
            $found = false;
            foreach ($list as $key) {
                if (isset($rows[(string)$key])) {
                    $found = true;
                    $sum += (float)$rows[(string)$key]['n'];
                }
            }
            if ($found) {
                $out[$ref] = [$sum, 1.0];
            }
        }
        return $out;
    }

    /**
     * Bot categories a crawler metric counts
     *
     * @param string $metric
     * @return string[]
     */
    private function categories(string $metric): array
    {
        return self::crawlCategories($metric);
    }

    /**
     * Bot categories a crawler metric counts (search_bot_hits = search + live answer bots)
     *
     * @param string $metric
     * @return string[]
     */
    public static function crawlCategories(string $metric): array
    {
        return match ($metric) {
            self::METRIC_SEARCH_BOT_HITS => self::SEARCH_CATEGORIES,
            self::METRIC_LIVE_FETCHES => [Category::LIVE],
            default => Category::BOT_CATEGORIES,
        };
    }

    /**
     * Day range, store and simulation filters on a daily table
     *
     * @param Select $select
     * @param string $alias
     * @param array $range
     * @return void
     */
    private function dailyFilters(Select $select, string $alias, array $range): void
    {
        [$from, $to, $storeId, $simulated] = $range;
        $select->where($alias . '.day >= ?', $from)
            ->where($alias . '.day <= ?', $to)
            ->where($alias . '.is_simulated = ?', $simulated ? 1 : 0);
        if ($storeId > 0) {
            $select->where($alias . '.store_id = ?', $storeId);
        }
    }

    /**
     * Index fetched rows by their "k" column
     *
     * @param array $rows
     * @return array<string, array>
     */
    private function rowsByKey(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string)($row['k'] ?? '')] = $row;
        }
        return $out;
    }

    /**
     * Validate a 'Y-m-d' day
     *
     * @param string $day
     * @return void
     * @throws \InvalidArgumentException
     */
    public static function assertDay(string $day): void
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            throw new \InvalidArgumentException(sprintf('Invalid day "%s", expected Y-m-d.', $day));
        }
    }

    /**
     * The UTC day after a 'Y-m-d' day
     *
     * @param string $day
     * @return string
     */
    public static function nextDay(string $day): string
    {
        return gmdate('Y-m-d', (int)strtotime($day . ' 00:00:00 UTC') + 86400);
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
