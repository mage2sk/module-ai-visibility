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
use Panth\AiVisibility\Api\RecommendationRuleInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Insights\RobotsInsight;
use Panth\AiVisibility\Service\Recommendation\CrawlFacts;
use Panth\AiVisibility\Service\Report\BotDirectory;

/**
 * Rule R1 (robots_blocks_ai_search): robots.txt blocks AI search bots.
 *
 * Only when Panth_RobotsSeo is installed and enabled for the store view and its per-bot switches are in
 * use (no custom robots.txt body). Uses the same field mapping as the dashboard insight
 * (RobotsInsight::FIELD_MAP). A blocked search or live-answer bot is one site-wide finding; blocked
 * training bots are a deliberate choice and are not reported. This is a configuration problem, not a
 * traffic count, so recommendations/min_volume does not apply; the evidence still shows how often the
 * blocked bots came in the last 30 days.
 */
class RobotsBlocksAiSearch implements RecommendationRuleInterface
{
    public const CODE = 'robots_blocks_ai_search';
    public const SUBJECT_REF = 'site';
    public const EVIDENCE_DAYS = 30;
    public const IMPACT_FLOOR = 100;

    /**
     * @param ModuleManager $moduleManager
     * @param ScopeConfigInterface $scopeConfig
     * @param BotDirectory $botDirectory
     * @param CrawlFacts $facts
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly BotDirectory $botDirectory,
        private readonly CrawlFacts $facts
    ) {
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
        return (string)__('robots.txt blocks AI search or live bots');
    }

    /**
     * @inheritdoc
     */
    public function metric(): string
    {
        return MetricProviderInterface::METRIC_SEARCH_BOT_HITS;
    }

    /**
     * @inheritdoc
     */
    public function evaluate(int $storeId, \DateTimeImmutable $today): array
    {
        if (!$this->moduleManager->isEnabled(RobotsInsight::MODULE)
            || (int)$this->value(RobotsInsight::XML_PATH_ENABLED, $storeId) !== 1
            || (int)$this->value(RobotsInsight::XML_PATH_OVERRIDE, $storeId) === 1
        ) {
            return [];
        }
        $blocked = [];
        foreach (RobotsInsight::FIELD_MAP as $field => $code) {
            $value = $this->value(RobotsInsight::XML_PATH_BOT_PREFIX . $field, $storeId);
            if ($value === null || $value === '' || (int)$value === 1) {
                continue;
            }
            $bot = $this->botDirectory->describe($code);
            if ($bot['category'] === Category::SEARCH || $bot['category'] === Category::LIVE) {
                $blocked[$code] = $bot;
            }
        }
        if ($blocked === []) {
            return [];
        }

        $day = $today->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $from = $day->modify('-' . self::EVIDENCE_DAYS . ' days')->format('Y-m-d');
        $to = $day->modify('-1 day')->format('Y-m-d');
        $hits = $this->facts->botHitsByCode($storeId, array_keys($blocked), $from, $to);
        $bots = [];
        foreach ($blocked as $code => $bot) {
            $bots[] = [
                'code' => $code,
                'name' => $bot['name'],
                'company' => $bot['company'],
                'category' => $bot['category'],
                'hits_30d' => $hits[$code] ?? 0,
                'setting' => RobotsInsight::XML_PATH_BOT_PREFIX . array_search($code, RobotsInsight::FIELD_MAP, true),
            ];
        }
        $names = array_column($bots, 'name');
        return [new Finding(
            self::CODE,
            $storeId,
            Finding::SUBJECT_SITE,
            self::SUBJECT_REF,
            (string)__(
                'robots.txt blocks %1 AI search or answer bot(s): %2',
                count($bots),
                implode(', ', $names)
            ),
            [
                'window' => ['from' => $from, 'to' => $to],
                'blocked' => $bots,
                'blocked_count' => count($bots),
                'how_to_fix' => (string)__(
                    'Allow these bots in Stores > Configuration > Robots SEO > LLM Bot Policy unless blocking '
                    . 'them is intended. They build AI search results or fetch pages to answer shoppers, so '
                    . 'blocked products cannot be found or cited there.'
                ),
            ],
            count($bots),
            self::IMPACT_FLOOR,
            $this->metric(),
            [self::SUBJECT_REF]
        )];
    }

    /**
     * Raw config value for the store view (0 = default scope)
     *
     * @param string $path
     * @param int $storeId
     * @return string|null
     */
    private function value(string $path, int $storeId): ?string
    {
        $value = $storeId > 0
            ? $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId)
            : $this->scopeConfig->getValue($path);
        return $value === null ? null : trim((string)$value);
    }
}
