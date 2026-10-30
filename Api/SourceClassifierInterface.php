<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

use Panth\AiVisibility\Model\SourceMatch;

/**
 * Classifies referrer hosts and utm_source values as AI assistant sources.
 */
interface SourceClassifierInterface
{
    /**
     * Exact or subdomain match on the AI referrer hosts (built-in, custom config, confirmed rules)
     *
     * @param string $host
     * @return SourceMatch|null
     */
    public function classifyHost(string $host): ?SourceMatch;

    /**
     * Case-insensitive match on the AI utm_source values
     *
     * @param string $utmSource
     * @return SourceMatch|null
     */
    public function classifyUtm(string $utmSource): ?SourceMatch;

    /**
     * AI-looking host that is not on the non-AI list and not already known
     *
     * @param string $host
     * @return bool
     */
    public function isCandidateHost(string $host): bool;

    /**
     * AI-looking utm_source value that is not already known
     *
     * @param string $utmSource
     * @return bool
     */
    public function isCandidateUtm(string $utmSource): bool;

    /**
     * Display name for a source code (falls back to the code itself)
     *
     * @param string $sourceCode
     * @return string
     */
    public function nameFor(string $sourceCode): string;

    /**
     * Every known source: code => name (built-in + custom config + confirmed rules)
     *
     * @return array<string, string>
     */
    public function allSources(): array;

    /**
     * Data for the storefront snippet
     *
     * Shape: ['hosts' => [host => code], 'utm' => [utm => code], 'candidateRegex' => string,
     * 'nonAiHosts' => [string, ...]]
     *
     * @return array
     */
    public function clientConfig(): array;
}
