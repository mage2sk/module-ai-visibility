<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Export;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\Api\HitWriterInterface;
use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;

/**
 * Rows of the dashboard CSV exports, with the dashboard's filters (UTC day range, store view,
 * simulated rows only when asked for).
 *
 * Headers are stable snake_case column names (not translated) so scripts can rely on them.
 * Numbers are returned as PHP int / float, text as strings (CsvWriter protects text cells).
 */
class ReportExporter
{
    public const REPORT_DAILY = 'daily';
    public const REPORT_PAGES = 'pages';
    public const REPORT_BOTS = 'bots';
    public const REPORT_SOURCES = 'sources';
    public const REPORT_RECOMMENDATIONS = 'recommendations';
    public const REPORT_VERDICTS = 'verdicts';

    public const REPORTS = [
        self::REPORT_DAILY,
        self::REPORT_PAGES,
        self::REPORT_BOTS,
        self::REPORT_SOURCES,
        self::REPORT_RECOMMENDATIONS,
        self::REPORT_VERDICTS,
    ];

    /**
     * Most pages ReportRepository::topPaths() returns
     */
    public const PAGE_LIMIT = 100;

    /**
     * Row cap of the recommendation and verdict exports
     */
    public const MAX_ROWS = 5000;

    private const STATE_CANCELED = 'canceled';

    /**
     * @param ResourceConnection $resource
     * @param ReportRepository $reportRepository
     * @param CatalogLookup $catalogLookup
     * @param BotDirectory $botDirectory
     * @param SourceClassifierInterface $sourceClassifier
     * @param Config $config
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ReportRepository $reportRepository,
        private readonly CatalogLookup $catalogLookup,
        private readonly BotDirectory $botDirectory,
        private readonly SourceClassifierInterface $sourceClassifier,
        private readonly Config $config
    ) {
    }

    /**
     * Headers and rows of a report
     *
     * @param string $report One of REPORTS
     * @param DateRange $range
     * @param int $storeId 0 = all store views
     * @param bool $includeSim
     * @return array{headers: string[], rows: array<int, array>}
     * @throws \InvalidArgumentException unknown report
     */
    public function export(string $report, DateRange $range, int $storeId, bool $includeSim): array
    {
        switch ($report) {
            case self::REPORT_DAILY:
                return $this->daily($range, $storeId, $includeSim);
            case self::REPORT_PAGES:
                return $this->pages($range, $storeId, $includeSim);
            case self::REPORT_BOTS:
                return $this->bots($range, $storeId, $includeSim);
            case self::REPORT_SOURCES:
                return $this->sources($range, $storeId, $includeSim);
            case self::REPORT_RECOMMENDATIONS:
                return $this->recommendations($range, $storeId, $includeSim);
            case self::REPORT_VERDICTS:
                return $this->verdicts($range, $storeId, $includeSim);
            default:
                throw new \InvalidArgumentException(sprintf('Unknown report "%s".', $report));
        }
    }

    /**
     * One row per day: crawler hits, live fetches, AI visits, AI orders and revenue
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array{headers: string[], rows: array<int, array>}
     */
    private function daily(DateRange $range, int $storeId, bool $includeSim): array
    {
        $connection = $this->connection();
        $crawl = $connection->select()
            ->from(
                ['d' => $this->table(ReportRepository::CRAWL_TABLE)],
                [
                    'day' => 'd.day',
                    'hits' => new Expression('COALESCE(SUM(d.hits), 0)'),
                    'live' => new Expression("COALESCE(SUM(CASE WHEN d.category = 'live' THEN d.hits ELSE 0 END), 0)"),
                ]
            )
            ->where('d.category IN (?)', Category::BOT_CATEGORIES)
            ->group('d.day');
        $this->dailyFilters($crawl, 'd', $range, $storeId, $includeSim);
        $crawlByDay = [];
        foreach ($connection->fetchAll($crawl) as $row) {
            $crawlByDay[substr((string)$row['day'], 0, 10)] = $row;
        }

        $visits = $this->reportRepository->visitsByDay($range, $storeId, $includeSim);

        $orders = $this->orderSelect($range, $storeId, $includeSim, [
            'day' => new Expression('DATE(so.created_at)'),
            'orders' => new Expression('COUNT(*)'),
            'revenue' => new Expression('COALESCE(SUM(so.base_grand_total), 0)'),
        ])->group(new Expression('DATE(so.created_at)'));
        $ordersByDay = [];
        foreach ($connection->fetchAll($orders) as $row) {
            $ordersByDay[substr((string)$row['day'], 0, 10)] = $row;
        }

        $rows = [];
        foreach ($range->days() as $day) {
            $rows[] = [
                $day,
                (int)($crawlByDay[$day]['hits'] ?? 0),
                (int)($crawlByDay[$day]['live'] ?? 0),
                (int)($visits[$day] ?? 0),
                (int)($ordersByDay[$day]['orders'] ?? 0),
                round((float)($ordersByDay[$day]['revenue'] ?? 0), 2),
            ];
        }
        return [
            'headers' => ['day', 'ai_crawler_hits', 'live_answer_fetches', 'ai_visits', 'ai_orders', 'ai_revenue'],
            'rows' => $rows,
        ];
    }

    /**
     * Most fetched pages (all bot categories) with the resolved entity name
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array{headers: string[], rows: array<int, array>}
     */
    private function pages(DateRange $range, int $storeId, bool $includeSim): array
    {
        $paths = $this->reportRepository->topPaths($range, $storeId, $includeSim, null, self::PAGE_LIMIT);
        $names = $paths ? $this->catalogLookup->entityNames($paths, $storeId) : [];
        $rows = [];
        $rank = 0;
        foreach ($paths as $row) {
            $key = $row['entity_type'] !== null ? $row['entity_type'] . ':' . $row['entity_id'] : '';
            $rows[] = [
                ++$rank,
                (string)($names[$key] ?? ''),
                (string)($row['entity_type'] ?? 'page'),
                $row['entity_id'],
                (string)$row['path'],
                (int)$row['hits'],
            ];
        }
        return [
            'headers' => ['rank', 'name', 'type', 'entity_id', 'path', 'ai_crawler_hits'],
            'rows' => $rows,
        ];
    }

    /**
     * Bots by hits with status counts, shares and average response time
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array{headers: string[], rows: array<int, array>}
     */
    private function bots(DateRange $range, int $storeId, bool $includeSim): array
    {
        $rows = [];
        foreach ($this->reportRepository->botStats($range, $storeId, $includeSim) as $row) {
            $bot = $this->botDirectory->describe($row['bot_code'], $row['category']);
            $hits = (int)$row['hits'];
            $divisor = max(1, $hits);
            $rows[] = [
                (string)$row['bot_code'],
                (string)$bot['name'],
                (string)$bot['company'],
                (string)$row['category'],
                $hits,
                (int)$row['s4xx'],
                (int)$row['s5xx'],
                round($row['s4xx'] / $divisor * 100, 2),
                round($row['s5xx'] / $divisor * 100, 2),
                (int)round($row['total_ms'] / $divisor),
            ];
        }
        return [
            'headers' => [
                'bot_code', 'bot', 'company', 'category', 'hits', 'status_4xx', 'status_5xx',
                'share_4xx_pct', 'share_5xx_pct', 'avg_ms',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * AI sources: visits, attributed orders and revenue (attribution model from the configuration)
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array{headers: string[], rows: array<int, array>}
     */
    private function sources(DateRange $range, int $storeId, bool $includeSim): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(
                ['v' => $this->table(ReportRepository::VISIT_DAILY_TABLE)],
                ['source' => 'v.source_code', 'visits' => new Expression('SUM(v.visits)')]
            )
            ->group('v.source_code');
        $this->dailyFilters($select, 'v', $range, $storeId, $includeSim);
        $data = [];
        foreach ($connection->fetchPairs($select) as $code => $visits) {
            $data[(string)$code] = ['visits' => (int)$visits, 'orders' => 0, 'revenue' => 0.0];
        }
        $model = $this->config->attributionModel();
        foreach ($this->reportRepository->revenueBySource($range, $storeId, $includeSim, $model) as $row) {
            $code = (string)$row['source'];
            $data[$code] = ($data[$code] ?? ['visits' => 0, 'orders' => 0, 'revenue' => 0.0]);
            $data[$code]['orders'] += (int)$row['orders'];
            $data[$code]['revenue'] += (float)$row['revenue'];
        }
        uksort($data, static function (string $a, string $b) use ($data): int {
            return [$data[$b]['visits'], $data[$b]['orders'], $a] <=> [$data[$a]['visits'], $data[$a]['orders'], $b];
        });
        $rows = [];
        foreach ($data as $code => $values) {
            $code = (string)$code;
            $rows[] = [
                $code,
                $this->sourceName($code),
                $values['visits'],
                $values['orders'],
                round($values['revenue'], 2),
            ];
        }
        return [
            'headers' => ['source_code', 'source', 'ai_visits', 'ai_orders', 'ai_revenue'],
            'rows' => $rows,
        ];
    }

    /**
     * Recommendations seen during the range (last seen on or after its first day, first seen before its end)
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array{headers: string[], rows: array<int, array>}
     */
    private function recommendations(DateRange $range, int $storeId, bool $includeSim): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(['r' => $this->table(RecommendationRepositoryInterface::TABLE)], [
                'rec_id', 'status', 'rule_code', 'store_id', 'title', 'subject_type', 'subject_ref',
                'affected_count', 'impact_score', 'metric', 'feedback', 'first_seen_at', 'last_seen_at',
                'status_changed_at',
            ])
            ->where('r.last_seen_at >= ?', $range->startDateTime())
            ->where('r.first_seen_at < ?', $range->endDateTimeExclusive())
            ->order(['r.impact_score DESC', 'r.rec_id ASC'])
            ->limit(self::MAX_ROWS);
        $this->rowFilters($select, 'r', $storeId, $includeSim);
        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                (int)$row['rec_id'],
                (string)$row['status'],
                (string)$row['rule_code'],
                (int)$row['store_id'],
                (string)$row['title'],
                (string)$row['subject_type'],
                (string)$row['subject_ref'],
                (int)$row['affected_count'],
                (int)$row['impact_score'],
                (string)$row['metric'],
                $row['feedback'] === null ? null : (int)$row['feedback'],
                (string)$row['first_seen_at'],
                (string)$row['last_seen_at'],
                $row['status_changed_at'] === null ? null : (string)$row['status_changed_at'],
            ];
        }
        return [
            'headers' => [
                'rec_id', 'status', 'rule_code', 'store_id', 'title', 'subject_type', 'subject_ref',
                'affected_count', 'impact_score', 'metric', 'feedback', 'first_seen_at', 'last_seen_at',
                'status_changed_at',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Fixes applied during the range with their verification result (empty columns while not verified)
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array{headers: string[], rows: array<int, array>}
     */
    private function verdicts(DateRange $range, int $storeId, bool $includeSim): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(['a' => $this->table(ActionLogInterface::TABLE)], [
                'action_id', 'rec_id', 'rule_code', 'store_id', 'metric', 'subjects', 'applied_at', 'applied_by',
                'undone_at', 'verify_after', 'status', 'note',
            ])
            ->joinLeft(
                ['i' => $this->table(ImpactCalculatorInterface::TABLE)],
                'i.action_id = a.action_id',
                [
                    'verdict', 'method', 'window_days', 'effect', 'ci_low', 'ci_high', 'events',
                    'subjects_treated', 'subjects_control', 'computed_at',
                ]
            )
            ->where('a.applied_at >= ?', $range->startDateTime())
            ->where('a.applied_at < ?', $range->endDateTimeExclusive())
            ->order(['a.applied_at DESC', 'a.action_id DESC'])
            ->limit(self::MAX_ROWS);
        $this->rowFilters($select, 'a', $storeId, $includeSim);
        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $subjects = json_decode((string)($row['subjects'] ?? ''), true);
            $rows[] = [
                (int)$row['action_id'],
                $row['rec_id'] === null ? null : (int)$row['rec_id'],
                (string)$row['rule_code'],
                (int)$row['store_id'],
                (string)$row['metric'],
                is_array($subjects) ? count($subjects) : 0,
                (string)$row['applied_at'],
                $row['applied_by'] === null ? null : (string)$row['applied_by'],
                (string)$row['status'],
                (string)$row['verify_after'],
                $row['undone_at'] === null ? null : (string)$row['undone_at'],
                $row['verdict'] === null ? null : (string)$row['verdict'],
                $row['method'] === null ? null : (string)$row['method'],
                $row['window_days'] === null ? null : (int)$row['window_days'],
                $this->decimal($row['effect']),
                $this->decimal($row['ci_low']),
                $this->decimal($row['ci_high']),
                $row['events'] === null ? null : (int)$row['events'],
                $row['subjects_treated'] === null ? null : (int)$row['subjects_treated'],
                $row['subjects_control'] === null ? null : (int)$row['subjects_control'],
                $row['computed_at'] === null ? null : (string)$row['computed_at'],
                $row['note'] === null ? null : (string)$row['note'],
            ];
        }
        return [
            'headers' => [
                'action_id', 'rec_id', 'rule_code', 'store_id', 'metric', 'subjects', 'applied_at', 'applied_by',
                'status', 'verify_after', 'undone_at', 'verdict', 'method', 'window_days', 'effect', 'ci_low',
                'ci_high', 'events', 'subjects_treated', 'subjects_control', 'computed_at', 'note',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Nullable DECIMAL column as float
     *
     * @param mixed $value
     * @return float|null
     */
    private function decimal($value): ?float
    {
        return $value === null || $value === '' ? null : (float)$value;
    }

    /**
     * Display name of a source code ("cand:<id>" = unconfirmed discovery candidate)
     *
     * @param string $code
     * @return string
     */
    private function sourceName(string $code): string
    {
        if ($code === '') {
            return 'Unknown';
        }
        if (str_starts_with($code, HitWriterInterface::CANDIDATE_PREFIX)) {
            return 'Unconfirmed source #' . substr($code, strlen(HitWriterInterface::CANDIDATE_PREFIX));
        }
        try {
            return (string)$this->sourceClassifier->nameFor($code);
        } catch (\Throwable $e) {
            return $code;
        }
    }

    /**
     * Day range, store and simulation filters on a daily table (same rules as the dashboard)
     *
     * @param Select $select
     * @param string $alias
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return void
     */
    private function dailyFilters(Select $select, string $alias, DateRange $range, int $storeId, bool $includeSim): void
    {
        $select->where($alias . '.day >= ?', $range->from())
            ->where($alias . '.day <= ?', $range->to());
        $this->rowFilters($select, $alias, $storeId, $includeSim);
    }

    /**
     * Store and simulation filters
     *
     * @param Select $select
     * @param string $alias
     * @param int $storeId
     * @param bool $includeSim
     * @return void
     */
    private function rowFilters(Select $select, string $alias, int $storeId, bool $includeSim): void
    {
        if ($storeId > 0) {
            $select->where($alias . '.store_id = ?', $storeId);
        }
        if (!$includeSim) {
            $select->where($alias . '.is_simulated = ?', 0);
        }
    }

    /**
     * Attributed, not canceled orders whose order date is inside the range (as on the dashboard)
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
            ->from(['o' => $this->table(ReportRepository::ORDER_TABLE)], $columns)
            ->join(['so' => $this->table(ReportRepository::SALES_ORDER_TABLE)], 'so.entity_id = o.order_id', [])
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
