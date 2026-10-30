<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation\Rule;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\ScopeInterface;
use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Insights\LlmsInsight;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\GuardedRule;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\RuleSupport;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;
use Psr\Log\LoggerInterface;

/**
 * Rule R7 (llms_not_served): AI bots crawl the store, but llms.txt does not reach them.
 *
 * Only when known AI bots made at least recommendations/min_volume requests in the last 30 days.
 * Reasons, first match wins: Panth_LlmsTxt is not enabled (module or setting) and no llms request
 * was answered with 2xx; llms requests mostly answered with 4xx/5xx; llms.txt not fetched at all.
 * One site finding per store view (verified with llms_fetches).
 *
 * Only the store's own root files count (/llms.txt and /llms-full.txt, also below the store code when
 * store codes are in URLs): requests such as /.well-known/llms.txt or /blog/llms.txt that answer 404 are
 * taken out of the llms totals (panth_aiv_crawl_daily page type llms) using the per-path rows of
 * panth_aiv_path_daily. That table keeps the top Rollup::PATHS_PER_GROUP paths per day and category, and
 * beyond them only paths with at least Rollup::MIN_EXTRA_EVENTS error answers (or slow timed hits) that
 * day, at most Rollup::MAX_EXTRA_PATHS per store view and day; a stray path with one or two 404s a day
 * outside the top paths is therefore not subtracted. /llms.json is not counted: it is not
 * a page type of its own and Panth_LlmsTxt may not serve it. Simulated rows follow the data scope
 * (recommend --simulated previews read them).
 */
class LlmsNotServed extends GuardedRule
{
    public const CODE = 'llms_not_served';

    public const REASON_DISABLED = 'disabled';
    public const REASON_ERRORS = 'errors';
    public const REASON_NEVER_FETCHED = 'never_fetched';

    /**
     * Error answers needed (and at least half of all llms requests) for the "errors" reason
     */
    public const MIN_ERRORS = 3;

    /**
     * llms file names served at the store root
     */
    public const ROOT_FILES = ['llms.txt', 'llms-full.txt'];

    private const WEIGHT = 2.0;

    /**
     * @param RuleSupport $support
     * @param LoggerInterface $logger
     * @param ReportRepository $reportRepository
     * @param BotDirectory $botDirectory
     * @param ModuleManager $moduleManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        RuleSupport $support,
        LoggerInterface $logger,
        private readonly ReportRepository $reportRepository,
        private readonly BotDirectory $botDirectory,
        private readonly ModuleManager $moduleManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($support, $logger);
    }

    /**
     * @inheritdoc
     */
    public function code(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    public function label(): string
    {
        return (string)__('llms.txt is not served to AI bots');
    }

    /**
     * @inheritdoc
     */
    public function metric(): string
    {
        return MetricProviderInterface::METRIC_LLMS_FETCHES;
    }

    /**
     * @inheritdoc
     */
    protected function find(int $storeId, \DateTimeImmutable $today): array
    {
        $from = RuleSupport::fromDay($today, RuleSupport::WINDOW_DAYS);
        $to = RuleSupport::toDay($today);
        $range = new DateRange($from, $to, DateRange::PERIOD_CUSTOM);
        $includeSim = $this->support->includesSimulated();
        $crawl = (int)$this->reportRepository->crawlTotals($range, $storeId, $includeSim)['hits'];
        if ($crawl < $this->support->minVolume()) {
            return [];
        }
        $llms = $this->llmsStatus($storeId, $from, $to);
        $moduleEnabled = $this->moduleManager->isEnabled(LlmsInsight::MODULE);
        $settingEnabled = $moduleEnabled && $this->settingEnabled($storeId);
        $errors = $llms['s4xx'] + $llms['s5xx'];

        if (!$settingEnabled && $llms['s2xx'] === 0) {
            $reason = self::REASON_DISABLED;
            $title = $moduleEnabled
                ? __('AI bots find no llms.txt: it is switched off in Panth_LlmsTxt')
                : __('AI bots find no llms.txt: the Panth_LlmsTxt module is not enabled');
            $affected = $crawl;
        } elseif ($errors >= self::MIN_ERRORS && $errors * 2 >= $llms['requests']) {
            $reason = self::REASON_ERRORS;
            $title = __('AI bots get errors when they ask for llms.txt (%1 of %2 requests)', $errors, $llms['requests']);
            $affected = $errors;
        } elseif ($llms['requests'] === 0) {
            $reason = self::REASON_NEVER_FETCHED;
            $title = __('No AI bot fetched your llms.txt in 30 days, while AI bots crawled %1 pages', $crawl);
            $affected = $crawl;
        } else {
            return [];
        }

        $bots = [];
        $fetches = $this->reportRepository->llmsFetchesByBot($range, $storeId, $includeSim);
        foreach (array_slice($fetches, 0, RuleSupport::TOP_EXAMPLES, true) as $code => $count) {
            $bots[] = ['bot' => $this->botDirectory->describe((string)$code)['name'], 'requests' => (int)$count];
        }
        $evidence = [
            'window_days' => RuleSupport::WINDOW_DAYS,
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
            'module_enabled' => $moduleEnabled,
            'setting_enabled' => $settingEnabled,
            'ai_crawler_hits' => $crawl,
            'llms_requests' => $llms['requests'],
            'llms_2xx' => $llms['s2xx'],
            'llms_4xx' => $llms['s4xx'],
            'llms_5xx' => $llms['s5xx'],
            'examples' => $bots,
        ];
        return [new Finding(
            self::CODE,
            $storeId,
            Finding::SUBJECT_SITE,
            Finding::SUBJECT_SITE,
            (string)$title,
            $evidence,
            $affected,
            RuleSupport::impactScore(self::WEIGHT, $affected),
            $this->metric(),
            [Finding::SUBJECT_SITE]
        )];
    }

    /**
     * Requests for the root llms files by known AI bots and their status classes
     *
     * Totals of page type llms minus the requests for llms files below other folders.
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @return array{requests: int, s2xx: int, s4xx: int, s5xx: int}
     */
    private function llmsStatus(int $storeId, string $from, string $to): array
    {
        $categories = Category::BOT_CATEGORIES;
        $bind = array_merge([$from, $to, PageTypeResolverInterface::TYPE_LLMS], $categories);
        $sql = 'SELECT COALESCE(SUM(d.hits), 0) AS requests, COALESCE(SUM(d.s2xx), 0) AS s2xx,'
            . ' COALESCE(SUM(d.s4xx), 0) AS s4xx, COALESCE(SUM(d.s5xx), 0) AS s5xx'
            . ' FROM ' . $this->support->table(ReportRepository::CRAWL_TABLE) . ' AS d'
            . ' WHERE d.day >= ? AND d.day <= ? AND d.page_type = ?'
            . ' AND d.category IN (' . RuleSupport::placeholders(count($categories)) . ') AND ' . $this->support->simulatedCondition('d.is_simulated')
            . RuleSupport::storeSql('d.store_id', $storeId, $bind);
        $row = $this->support->connection()->fetchRow($sql, $bind) ?: [];
        $other = $this->otherLlmsPaths($storeId, $from, $to);
        $s4xx = max(0, (int)($row['s4xx'] ?? 0) - $other['s4xx']);
        $s5xx = max(0, (int)($row['s5xx'] ?? 0) - $other['s5xx']);
        $s2xx = max(0, (int)($row['s2xx'] ?? 0) - $other['ok']);
        return [
            'requests' => max($s2xx + $s4xx + $s5xx, (int)($row['requests'] ?? 0) - $other['requests']),
            's2xx' => $s2xx,
            's4xx' => $s4xx,
            's5xx' => $s5xx,
        ];
    }

    /**
     * Requests for llms files that are not at the store root (e.g. /.well-known/llms.txt)
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @return array{requests: int, ok: int, s4xx: int, s5xx: int} ok = requests without a 4xx / 5xx answer
     */
    private function otherLlmsPaths(int $storeId, string $from, string $to): array
    {
        $categories = Category::BOT_CATEGORIES;
        $like = [];
        $bind = [$from, $to];
        foreach (self::ROOT_FILES as $file) {
            $like[] = 'p.path LIKE ?';
            $bind[] = '%/' . $file;
        }
        $roots = $this->rootPaths($storeId);
        $bind = array_merge($bind, $roots, $categories);
        $sql = 'SELECT COALESCE(SUM(p.hits), 0) AS requests, COALESCE(SUM(p.s4xx), 0) AS s4xx,'
            . ' COALESCE(SUM(p.s5xx), 0) AS s5xx'
            . ' FROM ' . $this->support->table(ReportRepository::PATH_TABLE) . ' AS p'
            . ' WHERE p.day >= ? AND p.day <= ? AND (' . implode(' OR ', $like) . ')'
            . ' AND p.path NOT IN (' . RuleSupport::placeholders(count($roots)) . ')'
            . ' AND p.category IN (' . RuleSupport::placeholders(count($categories)) . ')'
            . ' AND ' . $this->support->simulatedCondition('p.is_simulated')
            . RuleSupport::storeSql('p.store_id', $storeId, $bind);
        $row = $this->support->connection()->fetchRow($sql, $bind) ?: [];
        $requests = (int)($row['requests'] ?? 0);
        $s4xx = (int)($row['s4xx'] ?? 0);
        $s5xx = (int)($row['s5xx'] ?? 0);
        return ['requests' => $requests, 'ok' => max(0, $requests - $s4xx - $s5xx), 's4xx' => $s4xx, 's5xx' => $s5xx];
    }

    /**
     * Root llms file paths of the store view (also below the store code when it is part of URLs)
     *
     * @param int $storeId
     * @return string[]
     */
    private function rootPaths(int $storeId): array
    {
        $prefixes = [''];
        $code = $storeId > 0 ? $this->support->config()->urlStoreCode($storeId) : '';
        if ($code !== '') {
            $prefixes[] = '/' . $code;
        }
        $paths = [];
        foreach ($prefixes as $prefix) {
            foreach (self::ROOT_FILES as $file) {
                $paths[] = $prefix . '/' . $file;
            }
        }
        return $paths;
    }

    /**
     * Whether Panth_LlmsTxt is switched on for the store view (default scope for store 0)
     *
     * @param int $storeId
     * @return bool
     */
    private function settingEnabled(int $storeId): bool
    {
        return $storeId > 0
            ? $this->scopeConfig->isSetFlag(LlmsInsight::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)
            : $this->scopeConfig->isSetFlag(LlmsInsight::XML_PATH_ENABLED);
    }
}
