<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Simulator;

/**
 * Everything the simulator generates, as data: rates, rhythms, lags and shares.
 *
 * Later releases plant known effects for the learning models (hidden AI share, a bot that
 * stops crawling, a fix that raises crawl rate) by deriving a scenario with with() instead
 * of changing the generator. All numbers are integers (per mille, seconds, milliseconds),
 * so no float formatting can leak into stored values.
 *
 * Bot profile keys: name, company, category, per_day, hours (profile name), source (AI source
 * code its live fetches lead to, or null), click_permille (share of page fetches followed by a
 * shopper click), status (HTTP code => per mille; the rest is 200), ms ([min, max]),
 * mix (robots|llms|sitemap|home => per mille; the rest are store pages), types
 * (product|category|cms => weight), query_permille.
 * Source profile keys: name, host, utm_permille (share of visits tagged with utm_source),
 * direct_per_day (visits that do not follow a simulated live fetch).
 */
class Scenario
{
    /**
     * Simulated bots: code => profile. Codes, names, companies and categories match the
     * built-in bot catalogue.
     */
    private const BOTS = [
        'gptbot' => ['GPTBot', 'OpenAI', 'training', 220, null, 0],
        'oai_searchbot' => ['OAI-SearchBot', 'OpenAI', 'search', 90, null, 0],
        'chatgpt_user' => ['ChatGPT-User', 'OpenAI', 'live', 120, 'chatgpt', 150],
        'claudebot' => ['ClaudeBot', 'Anthropic', 'training', 160, null, 0],
        'claude_searchbot' => ['Claude-SearchBot', 'Anthropic', 'search', 40, null, 0],
        'claude_user' => ['Claude-User', 'Anthropic', 'live', 45, 'claude', 150],
        'anthropic_ai' => ['anthropic-ai', 'Anthropic', 'training', 6, null, 0],
        'claude_web' => ['Claude-Web', 'Anthropic', 'live', 4, 'claude', 100],
        'perplexitybot' => ['PerplexityBot', 'Perplexity', 'search', 70, null, 0],
        'perplexity_user' => ['Perplexity-User', 'Perplexity', 'live', 55, 'perplexity', 180],
        'meta_externalagent' => ['Meta External Agent', 'Meta', 'training', 110, null, 0],
        'meta_externalfetcher' => ['Meta External Fetcher', 'Meta', 'live', 15, 'meta_ai', 80],
        'amazonbot' => ['Amazonbot', 'Amazon', 'search', 80, null, 0],
        'applebot' => ['Applebot', 'Apple', 'search', 60, null, 0],
        'duckassistbot' => ['DuckAssistBot', 'DuckDuckGo', 'live', 10, null, 0],
        'mistralai_user' => ['MistralAI-User', 'Mistral', 'live', 8, 'mistral', 120],
        'youbot' => ['YouBot', 'You.com', 'search', 10, null, 0],
        'bytespider' => ['Bytespider', 'ByteDance', 'training', 90, null, 0],
        'ccbot' => ['CCBot', 'Common Crawl', 'training', 40, null, 0],
        'cohere_ai' => ['cohere-ai', 'Cohere', 'training', 5, null, 0],
        'cohere_crawler' => ['Cohere Training Crawler', 'Cohere', 'training', 8, null, 0],
        'diffbot' => ['Diffbot', 'Diffbot', 'other', 5, null, 0],
    ];

    /**
     * Simulated AI sources: code => [name, host, utm_permille, direct_per_day]. Match the
     * built-in source catalogue.
     */
    private const SOURCES = [
        'chatgpt' => ['ChatGPT', 'chatgpt.com', 600, 6],
        'perplexity' => ['Perplexity', 'perplexity.ai', 100, 3],
        'gemini' => ['Gemini', 'gemini.google.com', 50, 5],
        'copilot' => ['Microsoft Copilot', 'copilot.microsoft.com', 50, 3],
        'claude' => ['Claude', 'claude.ai', 50, 2],
        'meta_ai' => ['Meta AI', 'meta.ai', 0, 1],
        'mistral' => ['Le Chat (Mistral)', 'chat.mistral.ai', 0, 1],
        'deepseek' => ['DeepSeek', 'chat.deepseek.com', 0, 2],
        'grok' => ['Grok', 'grok.com', 0, 1],
        'you' => ['You.com', 'you.com', 0, 1],
    ];

    /**
     * Per-category defaults applied to every bot profile
     */
    private const CATEGORY_DEFAULTS = [
        'training' => [
            'hours' => 'crawler',
            'status' => [301 => 15, 404 => 25, 500 => 4, 503 => 6],
            'ms' => [80, 1400],
            'mix' => ['robots' => 20, 'llms' => 10, 'sitemap' => 25, 'home' => 25],
            'types' => ['product' => 650, 'category' => 250, 'cms' => 100],
            'query_permille' => 30,
        ],
        'search' => [
            'hours' => 'crawler',
            'status' => [301 => 12, 404 => 20, 500 => 3, 503 => 3],
            'ms' => [70, 1100],
            'mix' => ['robots' => 30, 'llms' => 10, 'sitemap' => 40, 'home' => 30],
            'types' => ['product' => 650, 'category' => 250, 'cms' => 100],
            'query_permille' => 30,
        ],
        'live' => [
            'hours' => 'human',
            'status' => [301 => 5, 404 => 8, 500 => 3, 503 => 2],
            'ms' => [60, 900],
            'mix' => ['robots' => 0, 'llms' => 15, 'sitemap' => 0, 'home' => 10],
            'types' => ['product' => 800, 'category' => 150, 'cms' => 50],
            'query_permille' => 10,
        ],
        'other' => [
            'hours' => 'flat',
            'status' => [301 => 15, 404 => 25, 500 => 4, 503 => 6],
            'ms' => [80, 1400],
            'mix' => ['robots' => 20, 'llms' => 10, 'sitemap' => 25, 'home' => 25],
            'types' => ['product' => 650, 'category' => 250, 'cms' => 100],
            'query_permille' => 30,
        ],
    ];

    /**
     * Relative weight per UTC hour (index 0-23)
     */
    private const HOURS = [
        'flat' => [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
        'crawler' => [12, 12, 13, 13, 12, 11, 10, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 10, 10, 11, 11, 12, 12],
        'human' => [3, 2, 1, 1, 1, 2, 4, 7, 9, 10, 11, 12, 12, 12, 11, 11, 12, 13, 15, 17, 18, 16, 11, 6],
    ];

    /**
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * @param array<string, mixed> $data Normalised scenario data (see standard())
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * The default scenario shipped with 1.0
     *
     * @param int $scalePermille Volume multiplier in per mille (1000 = scale 1)
     * @return self
     */
    public static function standard(int $scalePermille = 1000): self
    {
        $bots = [];
        foreach (self::BOTS as $code => [$name, $company, $category, $perDay, $source, $click]) {
            $bots[$code] = [
                'name' => $name,
                'company' => $company,
                'category' => $category,
                'per_day' => $perDay,
                'source' => $source,
                'click_permille' => $click,
            ] + self::CATEGORY_DEFAULTS[$category];
        }
        $sources = [];
        foreach (self::SOURCES as $code => [$name, $host, $utm, $direct]) {
            $sources[$code] = [
                'name' => $name,
                'host' => $host,
                'utm_permille' => $utm,
                'direct_per_day' => $direct,
            ];
        }
        return new self([
            'scale_permille' => self::clampScale($scalePermille),
            'bots' => $bots,
            'sources' => $sources,
            'hours' => self::HOURS,
            // ISO weekday 1 (Monday) .. 7 (Sunday) => per mille of the base rate.
            'weekday_permille' => [1 => 1040, 2 => 1060, 3 => 1050, 4 => 1030, 5 => 1000, 6 => 900, 7 => 920],
            'growth_permille_per_day' => 4,
            'noise_percent' => 15,
            // Seconds between a live-answer fetch and the shopper's click.
            'lag' => ['min' => 4, 'max' => 900],
            // Share of AI clicks that arrive without a referrer (seen only as direct landings).
            'hidden_permille' => 300,
            // Collector landing sample rate (config collector/landing_sample_rate, 25 %).
            'landing_sample_permille' => 250,
            // Ordinary direct deep landings per day, before sampling.
            'direct_landings_per_day' => 120,
            'status_404_paths' => 400,
            // Planted discovery candidates. Counts are exact (not scaled).
            'bot_candidate' => [
                'user_agent' => 'SimAgent/1.0 (+https://example.invalid/bot)',
                'last_days' => 21,
                'llms_per_day' => 2,
                'robots_per_day' => 1,
                'pages_per_day' => 24,
                'ms' => [90, 1200],
            ],
            'source_candidate' => [
                'host' => 'assistant.example.invalid',
                'last_days' => 14,
                'visits_per_day' => 3,
            ],
            // Orders attributed by --with-orders: lag between first touch and the order.
            'order_lag' => ['min' => 600, 'max' => 259200],
            // Source weights for attributed orders, and the share whose last touch differs.
            'order_sources' => [
                'chatgpt' => 50, 'perplexity' => 15, 'gemini' => 10, 'copilot' => 8, 'claude' => 10,
                'meta_ai' => 2, 'mistral' => 1, 'deepseek' => 2, 'grok' => 1, 'you' => 1,
            ],
            'order_switch_permille' => 200,
        ]);
    }

    /**
     * Copy with some top-level values replaced (planting effects for later models)
     *
     * @param array<string, mixed> $changes
     * @return self
     */
    public function with(array $changes): self
    {
        $data = array_replace($this->data, $changes);
        $data['scale_permille'] = self::clampScale((int) $data['scale_permille']);
        return new self($data);
    }

    /**
     * Raw value of a top-level key
     *
     * @param string $key
     * @return mixed
     */
    public function get(string $key)
    {
        if (!array_key_exists($key, $this->data)) {
            throw new \InvalidArgumentException(sprintf('Unknown scenario key "%s".', $key));
        }
        return $this->data[$key];
    }

    /**
     * Volume multiplier in per mille
     *
     * @return int
     */
    public function scalePermille(): int
    {
        return (int) $this->data['scale_permille'];
    }

    /**
     * Bot profiles, code => profile
     *
     * @return array<string, array<string, mixed>>
     */
    public function bots(): array
    {
        return $this->data['bots'];
    }

    /**
     * Source profiles, code => profile
     *
     * @return array<string, array<string, mixed>>
     */
    public function sources(): array
    {
        return $this->data['sources'];
    }

    /**
     * Hour weights of a rhythm profile
     *
     * @param string $profile
     * @return int[]
     */
    public function hourWeights(string $profile): array
    {
        return $this->data['hours'][$profile] ?? $this->data['hours']['flat'];
    }

    /**
     * Expected count for a base daily rate on a day, as an exact fraction
     *
     * Returns [whole, remainder] where the true value is whole + remainder / denominator().
     * Applies scale, weekday rhythm and linear growth.
     *
     * @param int $perDay Base rate at scale 1
     * @param int $dayIndex 0 for the first simulated day
     * @param int $isoWeekday 1 (Monday) .. 7 (Sunday)
     * @return int[]
     */
    public function expected(int $perDay, int $dayIndex, int $isoWeekday): array
    {
        $weekday = (int) ($this->data['weekday_permille'][$isoWeekday] ?? 1000);
        $growth = 1000 + (int) $this->data['growth_permille_per_day'] * max(0, $dayIndex);
        $numerator = max(0, $perDay) * $this->scalePermille() * $weekday * max(0, $growth);
        return [intdiv($numerator, self::denominator()), $numerator % self::denominator()];
    }

    /**
     * Denominator of expected()
     *
     * @return int
     */
    public static function denominator(): int
    {
        return 1000000000;
    }

    /**
     * Clamp a scale to 0.01 .. 100
     *
     * @param int $permille
     * @return int
     */
    private static function clampScale(int $permille): int
    {
        return max(10, min(100000, $permille));
    }
}
