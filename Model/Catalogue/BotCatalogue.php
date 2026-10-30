<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model\Catalogue;

/**
 * Built-in catalogue of AI crawlers, plus the non-AI bots that are never discovery candidates.
 *
 * Pure data and string checks: safe to use on every request.
 */
class BotCatalogue
{
    /**
     * Known AI bots: code => [name, company, category, User-Agent token (case-insensitive substring)]
     */
    public const BOTS = [
        'gptbot' => ['GPTBot', 'OpenAI', 'training', 'GPTBot'],
        'oai_searchbot' => ['OAI-SearchBot', 'OpenAI', 'search', 'OAI-SearchBot'],
        'chatgpt_user' => ['ChatGPT-User', 'OpenAI', 'live', 'ChatGPT-User'],
        'claudebot' => ['ClaudeBot', 'Anthropic', 'training', 'ClaudeBot'],
        'claude_searchbot' => ['Claude-SearchBot', 'Anthropic', 'search', 'Claude-SearchBot'],
        'claude_user' => ['Claude-User', 'Anthropic', 'live', 'Claude-User'],
        'anthropic_ai' => ['anthropic-ai', 'Anthropic', 'training', 'anthropic-ai'],
        'claude_web' => ['Claude-Web', 'Anthropic', 'live', 'Claude-Web'],
        'perplexitybot' => ['PerplexityBot', 'Perplexity', 'search', 'PerplexityBot'],
        'perplexity_user' => ['Perplexity-User', 'Perplexity', 'live', 'Perplexity-User'],
        'meta_externalagent' => ['Meta External Agent', 'Meta', 'training', 'meta-externalagent'],
        'meta_externalfetcher' => ['Meta External Fetcher', 'Meta', 'live', 'meta-externalfetcher'],
        'amazonbot' => ['Amazonbot', 'Amazon', 'search', 'Amazonbot'],
        'applebot' => ['Applebot', 'Apple', 'search', 'Applebot'],
        'duckassistbot' => ['DuckAssistBot', 'DuckDuckGo', 'live', 'DuckAssistBot'],
        'mistralai_user' => ['MistralAI-User', 'Mistral', 'live', 'MistralAI-User'],
        'youbot' => ['YouBot', 'You.com', 'search', 'YouBot'],
        'bytespider' => ['Bytespider', 'ByteDance', 'training', 'Bytespider'],
        'ccbot' => ['CCBot', 'Common Crawl', 'training', 'CCBot'],
        'cohere_ai' => ['cohere-ai', 'Cohere', 'training', 'cohere-ai'],
        'cohere_crawler' => ['Cohere Training Crawler', 'Cohere', 'training', 'cohere-training-data-crawler'],
        'diffbot' => ['Diffbot', 'Diffbot', 'other', 'Diffbot'],
    ];

    /**
     * Lower-case User-Agent substrings of non-AI bots and tools that are never discovery candidates:
     * search engines, social previews, SEO tools, uptime monitors, audit tools, internal cache warmers.
     * "cubot" is a phone brand whose browser User-Agent would otherwise look bot-like. "yandex" is a
     * prefix rule for the whole Yandex robot family.
     */
    public const NON_AI_TOKENS = [
        'googlebot', 'google-inspectiontool', 'googleother', 'storebot-google', 'adsbot-google',
        'mediapartners-google', 'apis-google', 'feedfetcher-google', 'bingbot', 'bingpreview', 'msnbot',
        'adidxbot', 'yandexbot', 'yandeximages', 'baiduspider', 'duckduckbot', 'slurp', 'sogou', 'exabot',
        'seznambot', 'petalbot', 'facebookexternalhit', 'facebookcatalog', 'twitterbot', 'linkedinbot',
        'pinterestbot', 'slackbot', 'discordbot', 'telegrambot', 'whatsapp', 'embedly', 'ahrefsbot',
        'semrushbot', 'mj12bot', 'dotbot', 'rogerbot', 'blexbot', 'dataforseobot', 'serpstatbot',
        'uptimerobot', 'pingdom', 'statuscake', 'site24x7', 'gtmetrix', 'lighthouse', 'chrome-lighthouse',
        'headlesschrome', 'magento', 'panth', 'cache-warm', 'cubot',
        // Every Yandex robot (YandexMobileBot, YandexAccessibilityBot, ...) and further search, social,
        // SEO and uptime clients.
        'yandex', 'duckduckgo-favicons-bot', 'facebot', 'pinterest/', 'siteauditbot', 'screaming frog',
        'betteruptime', 'better uptime', 'redditbot',
    ];

    /**
     * User-Agents that look like an automated client
     */
    public const DISCOVERY_REGEX = '/(bot|crawl|spider|agent|fetch|-user\b|gpt|llm|scrap)/i';

    /**
     * Whether the User-Agent belongs to a known non-AI bot or tool (never a discovery candidate)
     *
     * @param string $userAgent
     * @return bool
     */
    public static function isNonAi(string $userAgent): bool
    {
        $lower = strtolower($userAgent);
        foreach (self::NON_AI_TOKENS as $token) {
            if (str_contains($lower, $token)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the User-Agent looks like an automated client
     *
     * @param string $userAgent
     * @return bool
     */
    public static function looksLikeBot(string $userAgent): bool
    {
        return preg_match(self::DISCOVERY_REGEX, $userAgent) === 1;
    }
}
