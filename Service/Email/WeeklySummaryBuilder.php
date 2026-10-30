<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Email;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\Api\HitWriterInterface;
use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;
use Panth\AiVisibility\Service\DataScope;
use Psr\Log\LoggerInterface;

/**
 * Collects the numbers of the weekly summary email: the last 7 complete UTC days against the 7 days before.
 *
 * Real data only (simulated rows are left out, except in the CLI preview email --dry-run --simulated,
 * which reads them like the dashboard's "include simulated" switch), all store views. Every section is read on its own: a failing
 * section is logged and left empty instead of stopping the summary. The result holds raw values; escaping
 * is done by WeeklySummaryFormatter.
 */
class WeeklySummaryBuilder
{
    public const DAYS = 7;
    public const TOP_PAGES = 5;
    public const TOP_SOURCES = 5;
    public const TOP_RECOMMENDATIONS = 3;
    public const MAX_VERDICTS = 5;
    public const MAX_CANDIDATES = 5;

    /**
     * Verdicts that count as "reached" (an insufficient result is checked again later)
     */
    public const REACHED_VERDICTS = [
        ImpactCalculatorInterface::VERDICT_HELPED,
        ImpactCalculatorInterface::VERDICT_NO_EFFECT,
        ImpactCalculatorInterface::VERDICT_WORSE,
    ];

    private const VISIT_DAILY_TABLE = 'panth_aiv_visit_daily';
    private const CANDIDATE_TABLE = 'panth_aiv_candidate';
    private const CANDIDATE_STATUS_NEW = 'new';
    private const OPEN_FETCH_LIMIT = 50;

    /**
     * @param ReportRepository $reportRepository
     * @param RecommendationRepositoryInterface $recommendations
     * @param SourceClassifierInterface $sourceClassifier
     * @param CatalogLookup $catalogLookup
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param DataScope $scope
     */
    public function __construct(
        private readonly ReportRepository $reportRepository,
        private readonly RecommendationRepositoryInterface $recommendations,
        private readonly SourceClassifierInterface $sourceClassifier,
        private readonly CatalogLookup $catalogLookup,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly DataScope $scope
    ) {
    }

    /**
     * Build the summary for the week that ended yesterday (UTC)
     *
     * @param int $now Unix time
     * @return array{
     *     from: string, to: string, prev_from: string, prev_to: string,
     *     metrics: array<string, array{current: int|float, previous: int|float}>,
     *     money: array{current: string, previous: string},
 *     mixed_currencies: bool,
     *     top_pages: array<int, array{label: string, path: string, type: string, hits: int}>,
     *     top_sources: array<int, array{name: string, visits: int}>,
     *     recommendations: array<int, array{title: string, rule_code: string, impact_score: int,
     *         affected_count: int}>,
     *     verdicts: array<int, array{title: string, verdict: string, effect: ?float, ci_low: ?float,
     *         ci_high: ?float, metric: string}>,
     *     candidates: array{count: int, items: array<int, array{type: string, key: string, hits: int}>}
     * }
     */
    public function build(int $now): array
    {
        $periods = self::periods($now);
        $current = new DateRange($periods['from'], $periods['to'], DateRange::PERIOD_CUSTOM);
        $previous = new DateRange($periods['prev_from'], $periods['prev_to'], DateRange::PERIOD_CUSTOM);

        $crawlNow = $this->safe('crawl', fn (): array => $this->reportRepository->crawlTotals($current, 0, $this->scope->isIncludingSimulated()), []);
        $crawlPrev = $this->safe('crawl', fn (): array => $this->reportRepository->crawlTotals($previous, 0, $this->scope->isIncludingSimulated()), []);
        $visitsNow = $this->safe('visits', fn (): int => $this->reportRepository->visitTotal($current, 0, $this->scope->isIncludingSimulated()), 0);
        $visitsPrev = $this->safe('visits', fn (): int => $this->reportRepository->visitTotal($previous, 0, $this->scope->isIncludingSimulated()), 0);
        $ordersNow = $this->safe('orders', fn (): array => $this->reportRepository->orderTotals($current, 0, $this->scope->isIncludingSimulated()), []);
        $ordersPrev = $this->safe('orders', fn (): array => $this->reportRepository->orderTotals($previous, 0, $this->scope->isIncludingSimulated()), []);

        $revenueNow = (float)($ordersNow['revenue'] ?? 0.0);
        $revenuePrev = (float)($ordersPrev['revenue'] ?? 0.0);

        return $periods + [
            'metrics' => [
                'crawler_hits' => ['current' => (int)($crawlNow['hits'] ?? 0), 'previous' => (int)($crawlPrev['hits'] ?? 0)],
                'live_fetches' => ['current' => (int)($crawlNow['live'] ?? 0), 'previous' => (int)($crawlPrev['live'] ?? 0)],
                'ai_visits' => ['current' => $visitsNow, 'previous' => $visitsPrev],
                'ai_orders' => [
                    'current' => (int)($ordersNow['orders'] ?? 0),
                    'previous' => (int)($ordersPrev['orders'] ?? 0),
                ],
                'ai_revenue' => ['current' => $revenueNow, 'previous' => $revenuePrev],
            ],
            'money' => ['current' => $this->formatMoney($revenueNow), 'previous' => $this->formatMoney($revenuePrev)],
            'mixed_currencies' => $this->safe('currencies', fn (): bool => $this->hasMixedCurrencies(), false),
            'top_pages' => $this->safe('top pages', fn (): array => $this->topPages($current), []),
            'top_sources' => $this->safe('top sources', fn (): array => $this->topSources($current), []),
            'recommendations' => $this->safe('recommendations', fn (): array => $this->openRecommendations(), []),
            'verdicts' => $this->safe('verdicts', fn (): array => $this->verdicts($current), []),
            'candidates' => $this->safe(
                'candidates',
                fn (): array => $this->candidates($current),
                ['count' => 0, 'items' => []]
            ),
        ];
    }

    /**
     * Current week (last 7 complete UTC days) and the 7 days before it
     *
     * @param int $now Unix time
     * @return array{from: string, to: string, prev_from: string, prev_to: string}
     */
    public static function periods(int $now): array
    {
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $day = static fn (int $back): string => $today->modify('-' . $back . ' days')->format('Y-m-d');
        return [
            'from' => $day(self::DAYS),
            'to' => $day(1),
            'prev_from' => $day(2 * self::DAYS),
            'prev_to' => $day(self::DAYS + 1),
        ];
    }

    /**
     * Pages read most by AI bots, with product / category / CMS names where resolved
     *
     * @param DateRange $range
     * @return array<int, array{label: string, path: string, type: string, hits: int}>
     */
    private function topPages(DateRange $range): array
    {
        $rows = $this->reportRepository->topPaths($range, 0, $this->scope->isIncludingSimulated(), null, self::TOP_PAGES);
        $names = $rows === [] ? [] : $this->catalogLookup->entityNames($rows, 0);
        $types = ['product' => (string)__('Product'), 'category' => (string)__('Category'), 'cms' => (string)__('CMS page')];
        $result = [];
        foreach ($rows as $row) {
            $key = $row['entity_type'] !== null ? $row['entity_type'] . ':' . $row['entity_id'] : '';
            $result[] = [
                'label' => (string)($names[$key] ?? $row['path']),
                'path' => (string)$row['path'],
                'type' => $types[$row['entity_type'] ?? ''] ?? (string)__('Page'),
                'hits' => (int)$row['hits'],
            ];
        }
        return $result;
    }

    /**
     * AI sources that sent the most visits
     *
     * @param DateRange $range
     * @return array<int, array{name: string, visits: int}>
     */
    private function topSources(DateRange $range): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(
                ['v' => $this->resource->getTableName(self::VISIT_DAILY_TABLE)],
                ['source_code' => 'v.source_code', 'visits' => new Expression('SUM(v.visits)')]
            )
            ->where('v.day >= ?', $range->from())
            ->where('v.day <= ?', $range->to())
            ->where($this->scope->condition('v.is_simulated'))
            ->group('v.source_code')
            ->order(['visits DESC', 'source_code ASC'])
            ->limit(self::TOP_SOURCES);
        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $visits = (int)$row['visits'];
            if ($visits > 0) {
                $result[] = ['name' => $this->sourceName((string)$row['source_code']), 'visits' => $visits];
            }
        }
        return $result;
    }

    /**
     * Top open recommendations (ranked by the repository): real ones, plus the simulated ones in the
     * CLI preview (email --dry-run --simulated), merged by rank
     *
     * @return array<int, array{title: string, rule_code: string, impact_score: int, affected_count: int}>
     */
    private function openRecommendations(): array
    {
        $rows = $this->recommendations->listOpen(null, self::OPEN_FETCH_LIMIT);
        if ($this->scope->isIncludingSimulated()) {
            $rows = array_merge($rows, $this->recommendations->listOpen(null, self::OPEN_FETCH_LIMIT, true));
            usort(
                $rows,
                static fn ($a, $b): int => (float)($b['rank_score'] ?? 0) <=> (float)($a['rank_score'] ?? 0)
            );
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (!empty($row['is_simulated']) && !$this->scope->isIncludingSimulated())) {
                continue;
            }
            $result[] = [
                'title' => (string)($row['title'] ?? ''),
                'rule_code' => (string)($row['rule_code'] ?? ''),
                'impact_score' => (int)($row['impact_score'] ?? 0),
                'affected_count' => (int)($row['affected_count'] ?? 0),
            ];
            if (count($result) >= self::TOP_RECOMMENDATIONS) {
                break;
            }
        }
        return $result;
    }

    /**
     * Verdicts computed during the week for applied (not undone) fixes
     *
     * @param DateRange $range
     * @return array<int, array{title: string, verdict: string, effect: ?float, ci_low: ?float, ci_high: ?float,
     *     metric: string}>
     */
    private function verdicts(DateRange $range): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(
                ['i' => $this->resource->getTableName(ImpactCalculatorInterface::TABLE)],
                ['verdict', 'effect', 'ci_low', 'ci_high', 'computed_at']
            )
            ->join(
                ['a' => $this->resource->getTableName(ActionLogInterface::TABLE)],
                'a.action_id = i.action_id',
                ['rule_code', 'metric', 'note']
            )
            ->joinLeft(
                ['r' => $this->resource->getTableName(RecommendationRepositoryInterface::TABLE)],
                'r.rec_id = a.rec_id',
                ['title']
            )
            ->where('i.computed_at >= ?', $range->startDateTime())
            ->where('i.computed_at < ?', $range->endDateTimeExclusive())
            ->where('i.verdict IN (?)', self::REACHED_VERDICTS)
            ->where($this->scope->condition('i.is_simulated'))
            ->where('a.undone_at IS NULL')
            ->order(['i.computed_at DESC', 'i.impact_id DESC'])
            ->limit(self::MAX_VERDICTS);
        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                $title = (string)__('Fix for rule %1', (string)$row['rule_code']);
            }
            $result[] = [
                'title' => $title,
                'verdict' => (string)$row['verdict'],
                'effect' => self::nullableFloat($row['effect'] ?? null),
                'ci_low' => self::nullableFloat($row['ci_low'] ?? null),
                'ci_high' => self::nullableFloat($row['ci_high'] ?? null),
                'metric' => (string)$row['metric'],
            ];
        }
        return $result;
    }

    /**
     * Discovery candidates first seen during the week and still waiting for review
     *
     * @param DateRange $range
     * @return array{count: int, items: array<int, array{type: string, key: string, hits: int}>}
     */
    private function candidates(DateRange $range): array
    {
        $connection = $this->connection();
        $table = $this->resource->getTableName(self::CANDIDATE_TABLE);
        $base = $connection->select()
            ->from(['c' => $table], [])
            ->where('c.status = ?', self::CANDIDATE_STATUS_NEW)
            ->where($this->scope->condition('c.is_simulated'))
            ->where('c.first_seen >= ?', $range->startDateTime())
            ->where('c.first_seen < ?', $range->endDateTimeExclusive());

        $count = (clone $base)->columns(['total' => new Expression('COUNT(*)')]);
        $items = (clone $base)->columns(['type', 'key_value', 'hits'])
            ->order(['c.hits DESC', 'c.candidate_id ASC'])
            ->limit(self::MAX_CANDIDATES);

        $result = ['count' => (int)$connection->fetchOne($count), 'items' => []];
        foreach ($connection->fetchAll($items) as $row) {
            $result['items'][] = [
                'type' => (string)$row['type'],
                'key' => (string)$row['key_value'],
                'hits' => (int)$row['hits'],
            ];
        }
        return $result;
    }

    /**
     * Display name of a source code ("cand:<id>" = unconfirmed discovery candidate)
     *
     * @param string $code
     * @return string
     */
    private function sourceName(string $code): string
    {
        if (str_starts_with($code, HitWriterInterface::CANDIDATE_PREFIX)) {
            return (string)__('Unconfirmed source #%1', substr($code, strlen(HitWriterInterface::CANDIDATE_PREFIX)));
        }
        try {
            $name = $this->sourceClassifier->nameFor($code);
        } catch (\Throwable $e) {
            unset($e);
            $name = '';
        }
        return $name !== '' ? $name : $code;
    }

    /**
     * Whether the store views use more than one base currency (revenue is then summed across them, like the
     * dashboard with "All store views")
     *
     * @return bool
     */
    private function hasMixedCurrencies(): bool
    {
        $codes = [];
        foreach ($this->storeManager->getStores() as $store) {
            $codes[(string)$store->getBaseCurrencyCode()] = true;
        }
        return count($codes) > 1;
    }

    /**
     * Amount in the base currency of the default store view
     *
     * @param float $amount
     * @return string
     */
    private function formatMoney(float $amount): string
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
            if ($store !== null && method_exists($store, 'getBaseCurrency')) {
                return (string)$store->getBaseCurrency()->formatPrecision($amount, 2, [], false);
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        return number_format($amount, 2);
    }

    /**
     * Run one section; log and fall back on failure
     *
     * @param string $section
     * @param callable $read
     * @param mixed $fallback
     * @return mixed
     */
    private function safe(string $section, callable $read, $fallback)
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[Panth_AiVisibility] weekly summary: ' . $section . ' could not be read: ' . $e->getMessage()
            );
            return $fallback;
        }
    }

    /**
     * Float or null
     *
     * @param mixed $value
     * @return float|null
     */
    private static function nullableFloat($value): ?float
    {
        return $value === null || $value === '' ? null : (float)$value;
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
