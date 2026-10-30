<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Insights;

use Panth\AiVisibility\Service\Report\DateRange;
use Psr\Log\LoggerInterface;

/**
 * Collects the dashboard insights from every insight class.
 *
 * An insight is an array ['level' => 'warning'|'info'|'ok', 'title' => string, 'text' => string].
 * Warnings come first. A failing insight is logged and skipped so the dashboard always renders.
 */
class InsightProvider
{
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_INFO = 'info';
    public const LEVEL_OK = 'ok';

    private const ORDER = [self::LEVEL_WARNING => 0, self::LEVEL_INFO => 1, self::LEVEL_OK => 2];

    /**
     * @param RobotsInsight $robotsInsight
     * @param LlmsInsight $llmsInsight
     * @param CoverageInsight $coverageInsight
     * @param ErrorRateInsight $errorRateInsight
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RobotsInsight $robotsInsight,
        private readonly LlmsInsight $llmsInsight,
        private readonly CoverageInsight $coverageInsight,
        private readonly ErrorRateInsight $errorRateInsight,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * All insights for the filters, warnings first
     *
     * @param DateRange $range
     * @param int $storeId
     * @param bool $includeSim
     * @return array<int, array{level: string, title: string, text: string}>
     */
    public function collect(DateRange $range, int $storeId, bool $includeSim): array
    {
        $all = [];
        foreach ([$this->errorRateInsight, $this->robotsInsight, $this->llmsInsight, $this->coverageInsight] as $insight) {
            try {
                foreach ($insight->collect($range, $storeId, $includeSim) as $item) {
                    $level = (string)($item['level'] ?? self::LEVEL_INFO);
                    $all[] = [
                        'level' => isset(self::ORDER[$level]) ? $level : self::LEVEL_INFO,
                        'title' => (string)($item['title'] ?? ''),
                        'text' => (string)($item['text'] ?? ''),
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[Panth_AiVisibility] insight failed: ' . $e->getMessage());
            }
        }
        // usort is stable since PHP 8.0: insights of the same level keep their order.
        usort($all, static function (array $a, array $b): int {
            return self::ORDER[$a['level']] <=> self::ORDER[$b['level']];
        });
        return $all;
    }
}
