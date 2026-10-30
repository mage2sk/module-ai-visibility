<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Model\Catalogue\SourceCatalogue;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Model\SourceMatch;

/**
 * Classifies referrer hosts and utm_source values against the built-in catalogue, the custom
 * configuration and the confirmed Discovery rules (in that order of precedence).
 *
 * Pure in-memory work: the index is built once per request from config and the cached rules.
 */
class SourceClassifier implements SourceClassifierInterface
{
    private const HOST_PATTERN = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/';
    private const UTM_PATTERN = '/^[a-z0-9._-]{1,64}$/';
    private const MAX_HOST = 255;
    private const MAX_CODE = 64;

    /**
     * @var array{hosts: array<string, string>, utm: array<string, string>, names: array<string, string>}|null
     */
    private ?array $index = null;

    /**
     * @param SourceCatalogue $catalogue
     * @param Config $config
     * @param RuleProvider $ruleProvider
     */
    public function __construct(
        private readonly SourceCatalogue $catalogue,
        private readonly Config $config,
        private readonly RuleProvider $ruleProvider
    ) {
    }

    /**
     * @inheritDoc
     */
    public function classifyHost(string $host): ?SourceMatch
    {
        $host = $this->normalizeHost($host);
        if ($host === null) {
            return null;
        }
        $index = $this->index();
        $candidate = $host;
        while (true) {
            if (isset($index['hosts'][$candidate])) {
                $code = $index['hosts'][$candidate];
                return new SourceMatch($code, $index['names'][$code] ?? $code);
            }
            $dot = strpos($candidate, '.');
            if ($dot === false) {
                return null;
            }
            $candidate = substr($candidate, $dot + 1);
        }
    }

    /**
     * @inheritDoc
     */
    public function classifyUtm(string $utmSource): ?SourceMatch
    {
        $utm = $this->normalizeUtm($utmSource);
        if ($utm === null) {
            return null;
        }
        $index = $this->index();
        if (!isset($index['utm'][$utm])) {
            return null;
        }
        $code = $index['utm'][$utm];
        return new SourceMatch($code, $index['names'][$code] ?? $code);
    }

    /**
     * @inheritDoc
     */
    public function isCandidateHost(string $host): bool
    {
        $host = $this->normalizeHost($host);
        if ($host === null || $this->classifyHost($host) !== null || $this->isNonAiHost($host)) {
            return false;
        }
        return $this->matchesCandidatePattern($host);
    }

    /**
     * @inheritDoc
     */
    public function isCandidateUtm(string $utmSource): bool
    {
        $utm = $this->normalizeUtm($utmSource);
        if ($utm === null || $this->classifyUtm($utm) !== null) {
            return false;
        }
        return $this->matchesCandidatePattern($utm);
    }

    /**
     * @inheritDoc
     */
    public function nameFor(string $sourceCode): string
    {
        return $this->index()['names'][$sourceCode] ?? $sourceCode;
    }

    /**
     * @inheritDoc
     */
    public function allSources(): array
    {
        return $this->index()['names'];
    }

    /**
     * @inheritDoc
     */
    public function clientConfig(): array
    {
        $index = $this->index();
        return [
            'hosts' => $index['hosts'],
            'utm' => $index['utm'],
            'candidateRegex' => $this->catalogue->candidatePattern(),
            'nonAiHosts' => $this->catalogue->nonAiHosts(),
        ];
    }

    /**
     * Whether a (normalised) host is on the non-AI list
     *
     * @param string $host
     * @return bool
     */
    public function isNonAiHost(string $host): bool
    {
        foreach ($this->catalogue->nonAiHosts() as $entry) {
            if (str_ends_with($entry, '.*')) {
                $base = substr($entry, 0, -1);
                if (str_starts_with($host, $base) || str_contains($host, '.' . $base)) {
                    return true;
                }
            } elseif ($host === $entry || str_ends_with($host, '.' . $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lower-cased host without a trailing dot, or null when it is not a valid host name
     *
     * @param string $host
     * @return string|null
     */
    private function normalizeHost(string $host): ?string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        if ($host === '' || strlen($host) > self::MAX_HOST || str_contains($host, '..')
            || !preg_match(self::HOST_PATTERN, $host)
        ) {
            return null;
        }
        return $host;
    }

    /**
     * Lower-cased utm value, or null when it is not a valid value
     *
     * @param string $utm
     * @return string|null
     */
    private function normalizeUtm(string $utm): ?string
    {
        $utm = strtolower(trim($utm));
        return preg_match(self::UTM_PATTERN, $utm) ? $utm : null;
    }

    /**
     * Whether a value matches the candidate heuristic
     *
     * @param string $value
     * @return bool
     */
    private function matchesCandidatePattern(string $value): bool
    {
        return (bool)preg_match('/' . $this->catalogue->candidatePattern() . '/i', $value);
    }

    /**
     * Build (once) the host, utm and name maps
     *
     * @return array{hosts: array<string, string>, utm: array<string, string>, names: array<string, string>}
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $hosts = [];
        $utm = [];
        $names = [];
        foreach ($this->catalogue->sources() as $code => $source) {
            $names[$code] = $source['name'];
            foreach ($source['hosts'] as $host) {
                $hosts[$host] ??= $code;
            }
            foreach ($source['utm'] as $value) {
                $utm[$value] ??= $code;
            }
        }
        foreach ($this->config->customSourceHosts() as $host => $name) {
            $host = $this->normalizeHost((string)$host);
            if ($host !== null && !isset($hosts[$host])) {
                $hosts[$host] = $this->codeForName((string)$name, $names);
            }
        }
        foreach ($this->config->customUtm() as $value => $name) {
            $value = $this->normalizeUtm((string)$value);
            if ($value !== null && !isset($utm[$value])) {
                $utm[$value] = $this->codeForName((string)$name, $names);
            }
        }
        foreach ($this->ruleProvider->sourceRules() as $rule) {
            $code = substr((string)$rule['code'], 0, self::MAX_CODE);
            if ($code === '') {
                continue;
            }
            $names[$code] ??= (string)$rule['name'];
            if ($rule['match_on'] === RuleProvider::MATCH_HOST) {
                $host = $this->normalizeHost((string)$rule['pattern']);
                if ($host !== null) {
                    $hosts[$host] ??= $code;
                }
            } elseif ($rule['match_on'] === RuleProvider::MATCH_UTM) {
                $value = $this->normalizeUtm((string)$rule['pattern']);
                if ($value !== null) {
                    $utm[$value] ??= $code;
                }
            }
        }
        return $this->index = ['hosts' => $hosts, 'utm' => $utm, 'names' => $names];
    }

    /**
     * Source code for a custom source name
     *
     * An existing source with the same name is reused, otherwise a slug of the name is used
     * (prefixed with "custom_" and numbered when it clashes with another source).
     *
     * @param string $name
     * @param array $names code => name, updated in place
     * @return string
     */
    private function codeForName(string $name, array &$names): string
    {
        foreach ($names as $code => $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return (string)$code;
            }
        }
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        if ($slug === '') {
            $slug = 'custom_' . substr(sha1($name), 0, 8);
        } elseif (isset($names[$slug])) {
            $slug = 'custom_' . $slug;
        }
        $slug = substr($slug, 0, self::MAX_CODE - 4);
        $base = $slug;
        for ($i = 2; isset($names[$slug]); $i++) {
            $slug = $base . '_' . $i;
        }
        $names[$slug] = $name;
        return $slug;
    }
}
