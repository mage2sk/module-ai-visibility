<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Insights;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\ScopeInterface;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;

/**
 * llms.txt: which AI bots fetched it in the period, and whether the store serves one at all
 * (Panth_LlmsTxt installed and switched on).
 */
class LlmsInsight
{
    public const MODULE = 'Panth_LlmsTxt';
    public const XML_PATH_ENABLED = 'panth_llms_txt/llms_txt/enabled';
    private const MAX_BOTS_LISTED = 5;

    /**
     * @param ReportRepository $reportRepository
     * @param BotDirectory $botDirectory
     * @param ModuleManager $moduleManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ReportRepository $reportRepository,
        private readonly BotDirectory $botDirectory,
        private readonly ModuleManager $moduleManager,
        private readonly ScopeConfigInterface $scopeConfig
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
        $fetches = $this->reportRepository->llmsFetchesByBot($range, $storeId, $includeSim);
        $total = array_sum($fetches);

        if (!$this->servesLlmsTxt($storeId)) {
            return [[
                'level' => InsightProvider::LEVEL_INFO,
                'title' => (string)__('No llms.txt module is active'),
                'text' => $total > 0
                    ? (string)__(
                        'AI bots asked for /llms.txt %1 times in this period, but Panth_LlmsTxt is not enabled, '
                        . 'so they get no curated summary of your store. Enabling it gives assistants a clean map '
                        . 'of your catalogue.',
                        number_format($total)
                    )
                    : (string)__(
                        'Panth_LlmsTxt is not enabled. An llms.txt file gives AI assistants a clean, curated map '
                        . 'of your catalogue and key pages.'
                    ),
            ]];
        }
        if ($total === 0) {
            return [[
                'level' => InsightProvider::LEVEL_INFO,
                'title' => (string)__('llms.txt was not fetched in this period'),
                'text' => (string)__(
                    'Your store serves llms.txt, but no known AI bot requested it in the selected period. '
                    . 'Few bots read it yet; check again over a longer period.'
                ),
            ]];
        }
        $parts = [];
        foreach (array_slice($fetches, 0, self::MAX_BOTS_LISTED, true) as $code => $count) {
            $parts[] = $this->botDirectory->describe((string)$code)['name'] . ' (' . number_format($count) . ')';
        }
        if (count($fetches) > self::MAX_BOTS_LISTED) {
            $parts[] = (string)__('%1 more', count($fetches) - self::MAX_BOTS_LISTED);
        }
        return [[
            'level' => InsightProvider::LEVEL_OK,
            'title' => (string)__('llms.txt is being read'),
            'text' => (string)__(
                'llms.txt was fetched %1 times in this period: %2.',
                number_format($total),
                implode(', ', $parts)
            ),
        ]];
    }

    /**
     * Whether Panth_LlmsTxt is enabled as a module and in its configuration
     *
     * @param int $storeId
     * @return bool
     */
    private function servesLlmsTxt(int $storeId): bool
    {
        if (!$this->moduleManager->isEnabled(self::MODULE)) {
            return false;
        }
        return $storeId > 0
            ? $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)
            : $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }
}
