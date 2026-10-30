<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Insights;

use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;

/**
 * Server errors served to AI bots: a bot whose 5xx share is above 1% gets a warning.
 *
 * Bots with fewer than MIN_HITS hits in the period are skipped (one error in a handful of
 * requests is noise, not a pattern).
 */
class ErrorRateInsight
{
    public const THRESHOLD = 0.01;
    public const MIN_HITS = 20;

    /**
     * @param ReportRepository $reportRepository
     * @param BotDirectory $botDirectory
     */
    public function __construct(
        private readonly ReportRepository $reportRepository,
        private readonly BotDirectory $botDirectory
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
        $insights = [];
        foreach ($this->reportRepository->botStats($range, $storeId, $includeSim) as $row) {
            $hits = (int)$row['hits'];
            $errors = (int)$row['s5xx'];
            if ($hits < self::MIN_HITS || $errors <= 0) {
                continue;
            }
            $share = $errors / $hits;
            if ($share <= self::THRESHOLD) {
                continue;
            }
            $name = $this->botDirectory->describe((string)$row['bot_code'], (string)$row['category'])['name'];
            $insights[] = [
                'level' => InsightProvider::LEVEL_WARNING,
                'title' => (string)__('%1 receives server errors', $name),
                'text' => (string)__(
                    '%1 of %2 requests by %3 (%4%) ended in a 5xx error in this period. Bots that keep hitting '
                    . 'errors crawl less and may drop your pages. Check the Crawler Log for the failing URLs '
                    . 'and the server error log.',
                    number_format($errors),
                    number_format($hits),
                    $name,
                    number_format($share * 100, 1)
                ),
            ];
        }
        return $insights;
    }
}
