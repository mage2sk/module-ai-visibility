<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Model\BotMatch;
use Panth\AiVisibility\Model\Catalogue\BotCatalogue;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Model\Source\Category;

/**
 * Classifies User-Agents against the built-in catalogue, the custom patterns from the configuration
 * and the bots confirmed on the Discovery page.
 *
 * Matching is a case-insensitive substring test. Tokens are checked longest first (ties: built-in,
 * then custom, then confirmed rules, each in their own order), so the result never depends on the
 * order in which tokens were configured. The token table is built once per request; the confirmed
 * rules come from the application cache, never from the database on a cache hit.
 */
class BotClassifier implements BotClassifierInterface
{
    /**
     * Longer User-Agents are cut before matching (no real crawler sends more)
     */
    private const MAX_UA_LENGTH = 2048;

    /**
     * @var array<int, array{0: string, 1: BotMatch}>|null token (lower case) and match, longest token first
     */
    private ?array $matchers = null;

    /**
     * @var array<int, array{0: string, 1: BotMatch}>|null token (lower case) and match, declaration order
     */
    private ?array $definitions = null;

    /**
     * @var string|null
     */
    private ?string $lastUserAgent = null;

    /**
     * @var BotMatch|null
     */
    private ?BotMatch $lastMatch = null;

    /**
     * @param Config $config
     * @param RuleProvider $ruleProvider
     */
    public function __construct(
        private readonly Config $config,
        private readonly RuleProvider $ruleProvider
    ) {
    }

    /**
     * @inheritdoc
     */
    public function classify(string $userAgent): ?BotMatch
    {
        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            return null;
        }
        if ($userAgent === $this->lastUserAgent) {
            return $this->lastMatch;
        }
        $lower = strtolower(substr($userAgent, 0, self::MAX_UA_LENGTH));
        $match = null;
        foreach ($this->matchers() as [$token, $bot]) {
            if (str_contains($lower, $token)) {
                $match = $bot;
                break;
            }
        }
        $this->lastUserAgent = $userAgent;
        $this->lastMatch = $match;
        return $match;
    }

    /**
     * @inheritdoc
     */
    public function isDiscoveryCandidate(string $userAgent): bool
    {
        $userAgent = trim($userAgent);
        if ($userAgent === '' || !BotCatalogue::looksLikeBot($userAgent)) {
            return false;
        }
        return $this->classify($userAgent) === null && !BotCatalogue::isNonAi($userAgent);
    }

    /**
     * Forget the token table (the next call reads the configuration and rules again)
     *
     * @return void
     */
    public function reset(): void
    {
        $this->matchers = null;
        $this->definitions = null;
        $this->lastUserAgent = null;
        $this->lastMatch = null;
    }

    /**
     * Every known bot (built-in catalogue, custom configuration patterns, confirmed rules): code => match.
     *
     * This is the single source of bot names, companies and categories for every admin page and report,
     * using exactly the codes the classifier stores (custom bots: "custom_<slug>"). When two entries share
     * a code the first one wins, so a rule can never rename a built-in bot.
     *
     * @return array<string, BotMatch>
     */
    public function knownBots(): array
    {
        $bots = [];
        foreach ($this->definitions() as [, $bot]) {
            if (!isset($bots[$bot->code])) {
                $bots[$bot->code] = $bot;
            }
        }
        return $bots;
    }

    /**
     * Whether a code belongs to the built-in catalogue
     *
     * @param string $code
     * @return bool
     */
    public function isBuiltIn(string $code): bool
    {
        return isset(BotCatalogue::BOTS[$code]);
    }

    /**
     * Token table, longest token first
     *
     * @return array<int, array{0: string, 1: BotMatch}>
     */
    private function matchers(): array
    {
        if ($this->matchers !== null) {
            return $this->matchers;
        }
        $entries = $this->definitions();
        $order = array_keys($entries);
        usort($order, static function (int $a, int $b) use ($entries): int {
            return [strlen($entries[$b][0]), $a] <=> [strlen($entries[$a][0]), $b];
        });
        $sorted = [];
        foreach ($order as $index) {
            $sorted[] = $entries[$index];
        }
        return $this->matchers = $sorted;
    }

    /**
     * Token and match of every known bot, in declaration order (built-in, custom, confirmed rules)
     *
     * @return array<int, array{0: string, 1: BotMatch}>
     */
    private function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }
        $entries = [];
        $codes = [];
        foreach (BotCatalogue::BOTS as $code => [$name, $company, $category, $token]) {
            $codes[$code] = true;
            $entries[] = [strtolower($token), new BotMatch($code, $name, $company, $category)];
        }
        foreach ($this->customPatterns() as $pattern) {
            $token = strtolower(trim((string)($pattern['token'] ?? '')));
            $name = trim((string)($pattern['name'] ?? ''));
            if (strlen($token) < Config::MIN_BOT_TOKEN_LENGTH || $name === '') {
                continue;
            }
            $code = $this->uniqueCode('custom_' . $this->slug($name, $token), $codes);
            $company = trim((string)($pattern['company'] ?? ''));
            $entries[] = [
                $token,
                new BotMatch(
                    $code,
                    $name,
                    $company !== '' ? $company : $name,
                    $this->category((string)($pattern['category'] ?? ''))
                ),
            ];
        }
        foreach ($this->ruleRows() as $rule) {
            $token = strtolower(trim((string)($rule['pattern'] ?? '')));
            $code = (string)($rule['code'] ?? '');
            if (strlen($token) < Config::MIN_BOT_TOKEN_LENGTH || $code === '') {
                continue;
            }
            $name = (string)($rule['name'] ?? '') !== '' ? (string)$rule['name'] : $code;
            $company = (string)($rule['company'] ?? '') !== '' ? (string)$rule['company'] : $name;
            $entries[] = [
                $token,
                new BotMatch($code, $name, $company, $this->category((string)($rule['category'] ?? ''))),
            ];
        }
        return $this->definitions = $entries;
    }

    /**
     * Custom patterns from the configuration (empty on any error)
     *
     * @return array
     */
    private function customPatterns(): array
    {
        try {
            return $this->config->customBotPatterns();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Confirmed bot rules (empty on any error)
     *
     * @return array
     */
    private function ruleRows(): array
    {
        try {
            return $this->ruleProvider->botRules();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * A selectable bot category, "other" for anything else
     *
     * @param string $category
     * @return string
     */
    private function category(string $category): string
    {
        $category = strtolower(trim($category));
        return Category::isValid($category) ? $category : Category::OTHER;
    }

    /**
     * Code-safe slug of a name (falls back to a short hash of the token)
     *
     * @param string $name
     * @param string $token
     * @return string
     */
    private function slug(string $name, string $token): string
    {
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        if ($slug === '') {
            $slug = substr(hash('sha1', $token), 0, 8);
        }
        return substr($slug, 0, 50);
    }

    /**
     * Append a number when a code is already taken (max 64 characters)
     *
     * @param string $code
     * @param array<string, bool> $codes
     * @return string
     */
    private function uniqueCode(string $code, array &$codes): string
    {
        $candidate = $code;
        $suffix = 2;
        while (isset($codes[$candidate])) {
            $candidate = $code . '_' . $suffix;
            $suffix++;
        }
        $codes[$candidate] = true;
        return $candidate;
    }
}
