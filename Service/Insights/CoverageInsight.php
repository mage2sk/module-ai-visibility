<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Insights;

use Panth\AiVisibility\Service\Report\CatalogLookup;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;

/**
 * Product coverage: enabled, visible products that no AI bot fetched in the last 90 days.
 *
 * Always looks at the last 90 days (not the selected period), and stays silent until crawler
 * data exists for that window, so a fresh install does not report every product as missing.
 */
class CoverageInsight
{
    public const WINDOW_DAYS = '90';
    private const WARNING_SHARE = 0.5;

    /**
     * @param ReportRepository $reportRepository
     * @param CatalogLookup $catalogLookup
     */
    public function __construct(
        private readonly ReportRepository $reportRepository,
        private readonly CatalogLookup $catalogLookup
    ) {
    }

    /**
     * Insights for the filters
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array<int, array{level: string, title: string, text: string}>
     */
    public function collect(DateRange $range, int $storeId, bool $includeSim): array
    {
        $window = DateRange::fromInput(self::WINDOW_DAYS);
        if ($this->reportRepository->crawlTotals($window, $storeId, $includeSim)['hits'] <= 0) {
            return [];
        }
        $coverage = $this->catalogLookup->productCoverage($storeId, $window->from(), $includeSim);
        $eligible = $coverage['eligible'];
        $never = min($coverage['never'], $eligible);
        if ($eligible <= 0) {
            return [];
        }
        if ($never === 0) {
            return [[
                'level' => InsightProvider::LEVEL_OK,
                'title' => (string)__('Every visible product was fetched by AI bots'),
                'text' => (string)__(
                    'All %1 enabled, visible products were fetched at least once in the last 90 days.',
                    number_format($eligible)
                ),
            ]];
        }
        $share = $never / $eligible;
        return [[
            'level' => $share > self::WARNING_SHARE ? InsightProvider::LEVEL_WARNING : InsightProvider::LEVEL_INFO,
            'title' => (string)__('Products AI bots have not fetched'),
            'text' => (string)__(
                '%1 of %2 enabled, visible products (%3%) were never fetched by an AI bot in the last 90 days. '
                . 'Link them from category pages, include them in the XML sitemap and llms.txt, and check '
                . 'that robots.txt does not block their URLs.',
                number_format($never),
                number_format($eligible),
                number_format($share * 100, 1)
            ),
        ]];
    }
}
