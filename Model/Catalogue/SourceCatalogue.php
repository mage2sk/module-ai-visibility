<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model\Catalogue;

/**
 * Built-in AI assistant referrer sources, the candidate host heuristic and the non-AI host list.
 *
 * Pure data, no dependencies. Hosts match exactly or as a parent domain of the referrer host
 * (chatgpt.com matches www.chatgpt.com, never notchatgpt.com or chatgpt.com.example.invalid).
 * utm_source values match case-insensitively and exactly.
 */
class SourceCatalogue
{
    /**
     * code => [name, hosts, utm values]
     */
    public const SOURCES = [
        'chatgpt' => ['ChatGPT', ['chatgpt.com', 'chat.openai.com'], ['chatgpt.com', 'chatgpt', 'openai']],
        'perplexity' => ['Perplexity', ['perplexity.ai'], ['perplexity']],
        'gemini' => ['Gemini', ['gemini.google.com', 'bard.google.com'], ['gemini', 'bard']],
        'copilot' => ['Microsoft Copilot', ['copilot.microsoft.com', 'copilot.cloud.microsoft'], ['copilot']],
        'claude' => ['Claude', ['claude.ai'], ['claude', 'claude.ai']],
        'meta_ai' => ['Meta AI', ['meta.ai'], ['meta.ai', 'metaai']],
        'mistral' => ['Le Chat (Mistral)', ['chat.mistral.ai'], ['mistral']],
        'deepseek' => ['DeepSeek', ['chat.deepseek.com'], ['deepseek']],
        'grok' => ['Grok', ['grok.com'], ['grok']],
        'you' => ['You.com', ['you.com'], ['you.com']],
    ];

    /**
     * Candidate heuristic for unknown hosts and utm values (no delimiters: valid in PCRE and JavaScript)
     */
    public const CANDIDATE_PATTERN =
        '(^|[.-])(ai|gpt|chat|llm|copilot|gemini|claude|assistant|agent|perplexity)([.-]|$)';

    /**
     * Hosts that are never candidates. "name.*" matches name.<any suffix> and its subdomains,
     * any other entry matches the host itself and its subdomains.
     */
    public const NON_AI_HOSTS = [
        'google.*', 'bing.com', 'yahoo.*', 'duckduckgo.com', 'baidu.com', 'yandex.*', 'ecosia.org',
        'facebook.com', 'instagram.com', 't.co', 'x.com', 'twitter.com', 'linkedin.com', 'pinterest.*',
        'reddit.com', 'youtube.com', 'whatsapp.com', 'telegram.org', 'tiktok.com', 'lnkd.in', 'android-app',
    ];

    /**
     * Built-in sources: code => ['name' => string, 'hosts' => string[], 'utm' => string[]]
     *
     * @return array<string, array{name: string, hosts: array<int, string>, utm: array<int, string>}>
     */
    public function sources(): array
    {
        $result = [];
        foreach (self::SOURCES as $code => [$name, $hosts, $utm]) {
            $result[$code] = ['name' => $name, 'hosts' => $hosts, 'utm' => $utm];
        }
        return $result;
    }

    /**
     * Candidate regex source without delimiters
     *
     * @return string
     */
    public function candidatePattern(): string
    {
        return self::CANDIDATE_PATTERN;
    }

    /**
     * Non-AI host entries
     *
     * @return array<int, string>
     */
    public function nonAiHosts(): array
    {
        return self::NON_AI_HOSTS;
    }
}
