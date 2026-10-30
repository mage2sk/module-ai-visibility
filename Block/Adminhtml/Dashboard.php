<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Chart\SvgChart;
use Panth\AiVisibility\Service\Insights\InsightProvider;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;

/**
 * AI Visibility dashboard: filters, KPIs, charts, tables and insights.
 *
 * Filters (GET): period = 7 | 30 | 90 | custom, from / to = Y-m-d (custom), store = store view id
 * (0 = all), sim = 1 to include simulated rows. All figures are memoised per request.
 */
class Dashboard extends Template
{
    /**
     * Fixed colour slot per well-known company, so a company keeps its colour across filters
     */
    private const COMPANY_SLOTS = [
        'OpenAI' => 0,
        'Anthropic' => 1,
        'Perplexity' => 2,
        'Meta' => 3,
        'Amazon' => 4,
        'Apple' => 5,
        'ByteDance' => 6,
        'Common Crawl' => 7,
    ];

    private const TABLE_LIMIT = 10;

    /**
     * Drawing width of the half-width charts, so their labels stay readable
     */
    private const HALF_CHART_WIDTH = 460;

    /**
     * @var array<string, mixed>
     */
    private array $memo = [];

    /**
     * @param Context $context
     * @param ReportRepository $reportRepository
     * @param SvgChart $svgChart
     * @param InsightProvider $insightProvider
     * @param BotDirectory $botDirectory
     * @param CatalogLookup $catalogLookup
     * @param SourceClassifierInterface $sourceClassifier
     * @param Config $config
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly ReportRepository $reportRepository,
        private readonly SvgChart $svgChart,
        private readonly InsightProvider $insightProvider,
        private readonly BotDirectory $botDirectory,
        private readonly CatalogLookup $catalogLookup,
        private readonly SourceClassifierInterface $sourceClassifier,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Selected date range
     *
     * @return DateRange
     */
    public function getRange(): DateRange
    {
        return $this->memo(__FUNCTION__, function (): DateRange {
            $request = $this->getRequest();
            return DateRange::fromInput(
                $request->getParam('period'),
                $request->getParam('from'),
                $request->getParam('to')
            );
        });
    }

    /**
     * Selected store view id (0 = all store views); unknown ids fall back to 0
     *
     * @return int
     */
    public function getStoreId(): int
    {
        return $this->memo(__FUNCTION__, function (): int {
            $storeId = $this->getRequest()->getParam('store', 0);
            $storeId = is_scalar($storeId) ? (int)$storeId : 0;
            return isset($this->getStoreOptions()[$storeId]) ? $storeId : 0;
        }, 0);
    }

    /**
     * Whether simulated rows are included (?sim=1)
     *
     * @return bool
     */
    public function includeSimulated(): bool
    {
        $sim = $this->getRequest()->getParam('sim', '');
        return is_scalar($sim) && (string)$sim === '1';
    }

    /**
     * Store view options: id => "Website / Store view"
     *
     * @return array<int, string>
     */
    public function getStoreOptions(): array
    {
        return $this->memo(__FUNCTION__, function (): array {
            $options = [];
            foreach ($this->_storeManager->getStores() as $store) {
                try {
                    $website = (string)$store->getWebsite()->getName();
                } catch (\Throwable $e) {
                    $website = '';
                }
                $name = (string)$store->getName();
                $options[(int)$store->getId()] = $website !== '' ? $website . ' / ' . $name : $name;
            }
            ksort($options);
            return $options;
        });
    }

    /**
     * Headline numbers
     *
     * @return array{hits: int, live: int, visits: int, orders: int, revenue: float, conversion: ?float}
     */
    public function getKpis(): array
    {
        return $this->memo(__FUNCTION__, function (): array {
            [$range, $store, $sim] = $this->filters();
            $crawl = $this->reportRepository->crawlTotals($range, $store, $sim);
            $visits = $this->reportRepository->visitTotal($range, $store, $sim);
            $orders = $this->reportRepository->orderTotals($range, $store, $sim);
            return [
                'hits' => $crawl['hits'],
                'live' => $crawl['live'],
                'visits' => $visits,
                'orders' => $orders['orders'],
                'revenue' => $orders['revenue'],
                'conversion' => $visits > 0 ? $orders['orders'] / $visits : null,
            ];
        }, ['hits' => 0, 'live' => 0, 'visits' => 0, 'orders' => 0, 'revenue' => 0.0, 'conversion' => null]);
    }

    /**
     * Daily AI crawler hits stacked by company
     *
     * @return array{svg: string, legend: array<int, array{name: string, color: string}>, empty: bool}
     */
    public function getHitsChart(): array
    {
        return $this->memo(__FUNCTION__, function (): array {
            [$range, $store, $sim] = $this->filters();
            $byCompany = $this->reportRepository->hitsByCompany($range, $store, $sim);
            $series = [];
            $legend = [];
            $taken = [];
            foreach (array_keys($byCompany) as $company) {
                if (isset(self::COMPANY_SLOTS[$company])) {
                    $taken[self::COMPANY_SLOTS[$company]] = true;
                }
            }
            foreach ($byCompany as $company => $days) {
                $company = (string)$company;
                $color = $this->companyColor($company, $taken);
                $name = $company === ReportRepository::OTHER_LABEL ? (string)__('Other') : $company;
                $series[] = ['name' => $name, 'color' => $color, 'values' => array_values($days)];
                $legend[] = ['name' => $name, 'color' => $color];
            }
            $svg = $this->svgChart->bars(
                $range->days(),
                $series,
                (string)__('Daily AI crawler hits by company'),
                (string)__(
                    'Stacked bars, one per day from %1 to %2: AI crawler hits per company, top 5 and Other.',
                    $range->from(),
                    $range->to()
                ),
                $this->axisLabels($range)
            );
            return ['svg' => $svg, 'legend' => $legend, 'empty' => $byCompany === []];
        }, ['svg' => '', 'legend' => [], 'empty' => true]);
    }

    /**
     * Daily confirmed AI visits
     *
     * @return array{svg: string, empty: bool}
     */
    public function getVisitsChart(): array
    {
        return $this->memo(__FUNCTION__, function (): array {
            [$range, $store, $sim] = $this->filters();
            $days = $this->reportRepository->visitsByDay($range, $store, $sim);
            return $this->singleSeriesChart(
                $range,
                $days,
                (string)__('AI visits'),
                (string)__('Daily AI visits'),
                SvgChart::PALETTE[0]
            );
        }, ['svg' => '', 'empty' => true]);
    }

    /**
     * Daily attributed orders
     *
     * @return array{svg: string, empty: bool}
     */
    public function getOrdersChart(): array
    {
        return $this->memo(__FUNCTION__, function (): array {
            [$range, $store, $sim] = $this->filters();
            $days = $this->reportRepository->ordersByDay($range, $store, $sim);
            return $this->singleSeriesChart(
                $range,
                $days,
                (string)__('AI orders'),
                (string)__('Daily orders from AI visits'),
                SvgChart::PALETTE[2]
            );
        }, ['svg' => '', 'empty' => true]);
    }

    /**
     * Most fetched pages with resolved entity names
     *
     * @return array<int, array{label: string, path: string, type: string, hits: int}>
     */
    public function getTopPages(): array
    {
        return $this->memo(__FUNCTION__, function (): array {
            [$range, $store, $sim] = $this->filters();
            return $this->labelPaths($this->reportRepository->topPaths($range, $store, $sim, null, self::TABLE_LIMIT));
        });
    }

    /**
     * Pages fetched by live-answer bots (a shopper asked an assistant about them)
     *
     * @return array<int, array{label: string, path: string, type: string, hits: int}>
     */
    public function getLivePages(): array
    {
        return $this->memo(__FUNCTION__, function (): array {
            [$range, $store, $sim] = $this->filters();
            return $this->labelPaths(
                $this->reportRepository->topPaths($range, $store, $sim, Category::LIVE, self::TABLE_LIMIT)
            );
        });
    }

    /**
     * Bots by hits with error shares and average response time
     *
     * @return array<int, array{name: string, company: string, category: string, hits: int,
     *     share4xx: float, share5xx: float, avg_ms: int}>
     */
    public function getBotRows(): array
    {
        return $this->memo(__FUNCTION__, function (): array {
            [$range, $store, $sim] = $this->filters();
            $rows = [];
            foreach ($this->reportRepository->botStats($range, $store, $sim) as $row) {
                $bot = $this->botDirectory->describe($row['bot_code'], $row['category']);
                $hits = max(1, $row['hits']);
                $rows[] = [
                    'name' => $bot['name'],
                    'company' => $bot['company'],
                    'category' => $this->categoryLabel($row['category']),
                    'hits' => $row['hits'],
                    'share4xx' => $row['s4xx'] / $hits,
                    'share5xx' => $row['s5xx'] / $hits,
                    'avg_ms' => (int)round($row['total_ms'] / $hits),
                ];
            }
            return $rows;
        });
    }

    /**
     * Orders and revenue per AI source
     *
     * @return array<int, array{name: string, orders: int, revenue: float}>
     */
    public function getRevenueBySource(): array
    {
        return $this->memo(__FUNCTION__, function (): array {
            [$range, $store, $sim] = $this->filters();
            $rows = [];
            $model = $this->config->attributionModel();
            foreach ($this->reportRepository->revenueBySource($range, $store, $sim, $model) as $row) {
                $rows[] = [
                    'name' => $row['source'] !== ''
                        ? $this->sourceName($row['source'])
                        : (string)__('Unknown'),
                    'orders' => $row['orders'],
                    'revenue' => $row['revenue'],
                ];
            }
            return $rows;
        });
    }

    /**
     * Attribution model label for the revenue table
     *
     * @return string
     */
    public function getAttributionModelLabel(): string
    {
        return $this->config->attributionModel() === 'last'
            ? (string)__('last AI touch')
            : (string)__('first AI touch');
    }

    /**
     * Insights for the filters, warnings first
     *
     * @return array<int, array{level: string, title: string, text: string}>
     */
    public function getInsights(): array
    {
        return $this->memo(__FUNCTION__, function (): array {
            [$range, $store, $sim] = $this->filters();
            return $this->insightProvider->collect($range, $store, $sim);
        });
    }

    /**
     * Whether simulated rows exist in the selected range
     *
     * @return bool
     */
    public function hasSimulatedData(): bool
    {
        return $this->memo(__FUNCTION__, function (): bool {
            return $this->reportRepository->hasSimulatedData($this->getRange(), $this->getStoreId());
        }, false);
    }

    /**
     * Whether any AI data was ever recorded (else the first-run guide is shown)
     *
     * @return bool
     */
    public function hasAnyData(): bool
    {
        return $this->memo(__FUNCTION__, function (): bool {
            return $this->reportRepository->hasAnyData($this->includeSimulated());
        }, true);
    }

    /**
     * Discovery candidates waiting for review
     *
     * @return int
     */
    public function getNewCandidateCount(): int
    {
        return $this->memo(__FUNCTION__, function (): int {
            return $this->reportRepository->newCandidateCount($this->includeSimulated());
        }, 0);
    }

    /**
     * Whether the store views use more than one base currency (revenue is then summed across them)
     *
     * @return bool
     */
    public function hasMixedCurrencies(): bool
    {
        if ($this->getStoreId() > 0) {
            return false;
        }
        $codes = [];
        foreach ($this->_storeManager->getStores() as $store) {
            $codes[(string)$store->getBaseCurrencyCode()] = true;
        }
        return count($codes) > 1;
    }

    /**
     * Discovery page URL
     *
     * @return string
     */
    public function getDiscoveryUrl(): string
    {
        return $this->getUrl('panth_aivisibility/discovery/index');
    }

    /**
     * Crawler Log page URL
     *
     * @return string
     */
    public function getLogUrl(): string
    {
        return $this->getUrl('panth_aivisibility/log/index');
    }

    /**
     * Module configuration URL
     *
     * @return string
     */
    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'panth_aivisibility']);
    }

    /**
     * Form action for the filter form (GET)
     *
     * @return string
     */
    public function getFilterUrl(): string
    {
        return $this->getUrl('panth_aivisibility/dashboard/index');
    }

    /**
     * Dashboard URL with the current filters and some changes
     *
     * @param array $changes
     * @return string
     */
    public function getDashboardUrl(array $changes = []): string
    {
        $range = $this->getRange();
        $query = ['period' => $range->period()];
        if ($range->isCustom()) {
            $query['from'] = $range->from();
            $query['to'] = $range->to();
        }
        if ($this->getStoreId() > 0) {
            $query['store'] = $this->getStoreId();
        }
        if ($this->includeSimulated()) {
            $query['sim'] = 1;
        }
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }
        if (($query['period'] ?? '') !== DateRange::PERIOD_CUSTOM) {
            unset($query['from'], $query['to']);
        }
        return $this->getUrl('panth_aivisibility/dashboard/index', ['_query' => $query]);
    }

    /**
     * Whole number with thousands separators
     *
     * @param int|float $value
     * @return string
     */
    public function formatNumber($value): string
    {
        return number_format((float)$value);
    }

    /**
     * Share as a percentage with one decimal; '-' when unknown
     *
     * @param float|null $share 0..1
     * @return string
     */
    public function formatPercent(?float $share): string
    {
        return $share === null ? '-' : number_format($share * 100, 1) . '%';
    }

    /**
     * Amount in the base currency of the selected store view (default store view for all)
     *
     * @param float $amount
     * @return string
     */
    public function formatMoney(float $amount): string
    {
        try {
            $store = $this->getStoreId() > 0
                ? $this->_storeManager->getStore($this->getStoreId())
                : $this->_storeManager->getDefaultStoreView();
            if ($store !== null && method_exists($store, 'getBaseCurrency')) {
                return (string)$store->getBaseCurrency()->formatPrecision($amount, 2, [], false);
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        return number_format($amount, 2);
    }

    /**
     * Range as text, e.g. "2026-10-01 to 2026-10-30 (30 days, UTC)"
     *
     * @return string
     */
    public function getRangeLabel(): string
    {
        $range = $this->getRange();
        return (string)__('%1 to %2 (%3 days, UTC)', $range->from(), $range->to(), $range->dayCount());
    }

    /**
     * Current filter values as [range, store id, include simulated]
     *
     * @return array{0: DateRange, 1: int, 2: bool}
     */
    private function filters(): array
    {
        return [$this->getRange(), $this->getStoreId(), $this->includeSimulated()];
    }

    /**
     * One-series bar chart for a day => count map
     *
     * @param DateRange $range
     * @param array<string, int> $days
     * @param string $seriesName
     * @param string $title
     * @param string $color
     * @return array{svg: string, empty: bool}
     */
    private function singleSeriesChart(
        DateRange $range,
        array $days,
        string $seriesName,
        string $title,
        string $color
    ): array {
        $svg = $this->svgChart->bars(
            array_keys($days),
            [['name' => $seriesName, 'color' => $color, 'values' => array_values($days)]],
            $title,
            (string)__('Bars, one per day from %1 to %2: %3 per day.', $range->from(), $range->to(), $seriesName),
            $this->axisLabels($range),
            self::HALF_CHART_WIDTH
        );
        return ['svg' => $svg, 'empty' => array_sum($days) <= 0];
    }

    /**
     * Short x-axis labels ("Oct 3")
     *
     * @param DateRange $range
     * @return string[]
     */
    private function axisLabels(DateRange $range): array
    {
        $utc = new \DateTimeZone('UTC');
        $labels = [];
        foreach ($range->days() as $day) {
            $labels[] = (new \DateTimeImmutable($day, $utc))->format('M j');
        }
        return $labels;
    }

    /**
     * Colour for a company: its fixed slot, else the first free palette slot
     *
     * @param string $company
     * @param array<int, bool> $taken Slots in use (updated)
     * @return string
     */
    private function companyColor(string $company, array &$taken): string
    {
        if ($company === ReportRepository::OTHER_LABEL) {
            return SvgChart::OTHER_COLOR;
        }
        if (isset(self::COMPANY_SLOTS[$company])) {
            return SvgChart::PALETTE[self::COMPANY_SLOTS[$company]];
        }
        foreach (SvgChart::PALETTE as $slot => $color) {
            if (!isset($taken[$slot])) {
                $taken[$slot] = true;
                return $color;
            }
        }
        return SvgChart::OTHER_COLOR;
    }

    /**
     * Add display labels (product / category / CMS page names) to path rows
     *
     * @param array $rows
     * @return array<int, array{label: string, path: string, type: string, hits: int}>
     */
    private function labelPaths(array $rows): array
    {
        $names = $this->catalogLookup->entityNames($rows, $this->getStoreId());
        $types = [
            'product' => (string)__('Product'),
            'category' => (string)__('Category'),
            'cms' => (string)__('CMS page'),
        ];
        $result = [];
        foreach ($rows as $row) {
            $key = $row['entity_type'] !== null ? $row['entity_type'] . ':' . $row['entity_id'] : '';
            $result[] = [
                'label' => $names[$key] ?? $row['path'],
                'path' => $row['path'],
                'type' => $types[$row['entity_type'] ?? ''] ?? (string)__('Page'),
                'hits' => $row['hits'],
            ];
        }
        return $result;
    }

    /**
     * Display name for a stored source code ("cand:<id>" = unconfirmed discovery candidate)
     *
     * @param string $code
     * @return string
     */
    private function sourceName(string $code): string
    {
        if (str_starts_with($code, \Panth\AiVisibility\Api\HitWriterInterface::CANDIDATE_PREFIX)) {
            return (string)__('Unconfirmed source #%1', substr($code, 5));
        }
        return $this->safe(fn () => $this->sourceClassifier->nameFor($code), $code);
    }

    /**
     * Label for a bot category
     *
     * @param string $category
     * @return string
     */
    private function categoryLabel(string $category): string
    {
        $labels = [
            Category::TRAINING => (string)__('Training'),
            Category::SEARCH => (string)__('Search'),
            Category::LIVE => (string)__('Live answer'),
            Category::OTHER => (string)__('Other'),
        ];
        return $labels[$category] ?? $category;
    }

    /**
     * Run a callable, returning $fallback when it throws
     *
     * @param callable $callback
     * @param mixed $fallback
     * @return mixed
     */
    private function safe(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            $this->_logger->warning('[Panth_AiVisibility] dashboard query failed: ' . $e->getMessage());
            return $fallback;
        }
    }

    /**
     * Compute a value once per request
     *
     * A failing query is logged and replaced by $fallback, so the page always renders.
     *
     * @param string $key
     * @param callable $callback
     * @param mixed $fallback
     * @return mixed
     */
    private function memo(string $key, callable $callback, $fallback = [])
    {
        if (!array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->safe($callback, $fallback);
        }
        return $this->memo[$key];
    }
}
