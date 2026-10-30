<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Insights;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\ScopeInterface;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\DateRange;

/**
 * robots.txt policy check, only when Panth_RobotsSeo is installed and enabled.
 *
 * Reads the per-bot switches under panth_robots_seo/llm_bots (1 = allowed). A blocked search or
 * live-answer bot is a warning: the store cannot be found or cited by that assistant. Blocked
 * training bots are a deliberate choice for many merchants and are only listed as info.
 * Switches without a matching crawler in the catalogue (Google-Extended, Applebot-Extended,
 * Facebookbot) are control tokens or non-AI crawlers and are ignored here.
 */
class RobotsInsight
{
    public const MODULE = 'Panth_RobotsSeo';
    public const XML_PATH_ENABLED = 'panth_robots_seo/general/enabled';
    public const XML_PATH_OVERRIDE = 'panth_robots_seo/robots_txt/override_enabled';
    public const XML_PATH_BOT_PREFIX = 'panth_robots_seo/llm_bots/';

    /**
     * Panth_RobotsSeo llm_bots field => bot catalogue code
     */
    public const FIELD_MAP = [
        'gptbot' => 'gptbot',
        'oai_searchbot' => 'oai_searchbot',
        'chatgpt_user' => 'chatgpt_user',
        'claudebot' => 'claudebot',
        'anthropic_ai' => 'anthropic_ai',
        'perplexitybot' => 'perplexitybot',
        'ccbot' => 'ccbot',
        'bytespider' => 'bytespider',
        'cohere_ai' => 'cohere_ai',
        'amazonbot' => 'amazonbot',
        'meta_externalagent' => 'meta_externalagent',
    ];

    /**
     * @param ModuleManager $moduleManager
     * @param ScopeConfigInterface $scopeConfig
     * @param BotDirectory $botDirectory
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ScopeConfigInterface $scopeConfig,
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
        if (!$this->moduleManager->isEnabled(self::MODULE) || !$this->flag(self::XML_PATH_ENABLED, $storeId)) {
            return [];
        }
        if ($this->flag(self::XML_PATH_OVERRIDE, $storeId)) {
            return [[
                'level' => InsightProvider::LEVEL_INFO,
                'title' => (string)__('robots.txt uses a custom body'),
                'text' => (string)__(
                    'The per-bot switches of Robots SEO are ignored while the custom robots.txt body is in use. '
                    . 'Check by hand that AI search and live-answer bots are not disallowed.'
                ),
            ]];
        }

        $blockedLive = [];
        $blockedTraining = [];
        foreach (self::FIELD_MAP as $field => $code) {
            $value = $this->value(self::XML_PATH_BOT_PREFIX . $field, $storeId);
            if ($value === null || $value === '' || (int)$value === 1) {
                continue;
            }
            $bot = $this->botDirectory->describe($code);
            if ($bot['category'] === Category::SEARCH || $bot['category'] === Category::LIVE) {
                $blockedLive[] = $bot['name'];
            } else {
                $blockedTraining[] = $bot['name'];
            }
        }

        $insights = [];
        if ($blockedLive !== []) {
            $insights[] = [
                'level' => InsightProvider::LEVEL_WARNING,
                'title' => (string)__('AI search or answer bots are blocked in robots.txt'),
                'text' => (string)__(
                    'Blocked: %1. These bots build AI search results or fetch pages to answer shoppers, so your '
                    . 'products cannot be found or cited there. Allow them in Stores > Configuration > '
                    . 'Robots SEO > LLM Bot Policy unless this is intended.',
                    implode(', ', $blockedLive)
                ),
            ];
        }
        if ($blockedTraining !== []) {
            $insights[] = [
                'level' => InsightProvider::LEVEL_INFO,
                'title' => (string)__('AI training bots are blocked in robots.txt'),
                'text' => (string)__(
                    'Blocked: %1. This keeps your content out of future model training; it does not affect AI '
                    . 'search or live answers.',
                    implode(', ', $blockedTraining)
                ),
            ];
        }
        return $insights;
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

    /**
     * Yes/no config value for the store view (0 = default scope)
     *
     * @param string $path
     * @param int $storeId
     * @return bool
     */
    private function flag(string $path, int $storeId): bool
    {
        return (int)$this->value($path, $storeId) === 1;
    }
}
