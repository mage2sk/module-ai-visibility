<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Discovery;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Api\RelabelerInterface;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Model\Catalogue\BrowserUserAgents;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Model\RuleWriter;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\BotNames;

/**
 * Applies a Discovery decision to a candidate.
 *
 * Confirming creates a rule (code "disc_<slug of name>", unique per type, never a built-in
 * code), moves the candidate's earlier rows onto it and marks the candidate confirmed.
 * "Not AI" and "Ignore" only change the status; "restore" moves it back to the New list.
 */
class CandidateClassifier
{
    public const CODE_PREFIX = 'disc_';

    public const DECISION_CONFIRM = 'confirm';
    public const DECISION_NOT_AI = 'not_ai';
    public const DECISION_IGNORED = 'ignored';
    public const DECISION_RESTORE = 'restore';

    public const NAME_MAX = 128;
    public const COMPANY_MAX = 64;
    public const TOKEN_MIN = 3;
    public const TOKEN_MAX = 128;

    /**
     * Allowed characters of names and companies
     */
    private const NAME_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N} .,&()+\/\'_:-]*$/u';

    /**
     * Allowed characters of User-Agent tokens
     */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._\/+:-]*$/';

    /**
     * Tokens that appear in ordinary browser User-Agents or are too generic to identify one bot
     */
    private const GENERIC_TOKENS = [
        'mozilla', 'compatible', 'applewebkit', 'khtml', 'like gecko', 'gecko', 'chrome', 'chromium', 'safari',
        'firefox', 'edge', 'opera', 'windows', 'windows nt', 'linux', 'android', 'iphone', 'ipad', 'macintosh',
        'mac os x', 'mobile', 'version', 'http', 'https', 'www', 'bot', 'bots', 'crawler', 'crawl', 'spider',
        'agent', 'fetch', 'fetcher', 'user', 'scraper', 'gpt', 'llm', 'python', 'curl', 'wget', 'java', 'okhttp',
        'node', 'go-http-client', 'x11', 'ubuntu', 'wow64', 'win64', 'x64',
    ];

    /**
     * Discovery bot-like token regex (spec catalogue)
     */
    private const BOT_LIKE = '/(bot|crawl|spider|agent|fetch|-user\b|gpt|llm|scrap)/i';

    /**
     * @param CandidateRepository $candidates
     * @param RuleProvider $ruleProvider
     * @param RuleWriter $ruleWriter
     * @param RelabelerInterface $relabeler
     * @param BotClassifierInterface $botClassifier
     * @param SourceClassifierInterface $sourceClassifier
     * @param BotNames $botNames
     */
    public function __construct(
        private readonly CandidateRepository $candidates,
        private readonly RuleProvider $ruleProvider,
        private readonly RuleWriter $ruleWriter,
        private readonly RelabelerInterface $relabeler,
        private readonly BotClassifierInterface $botClassifier,
        private readonly SourceClassifierInterface $sourceClassifier,
        private readonly BotNames $botNames
    ) {
    }

    /**
     * Apply a decision and return the success message
     *
     * Decisions: a bot category (training | search | live | other) confirms a bot candidate,
     * "confirm" confirms a source candidate, plus "not_ai", "ignored" and "restore".
     *
     * @param int $candidateId
     * @param string $decision
     * @param array $input name, token (bots), company (bots, optional)
     * @return Phrase
     * @throws LocalizedException When the input is invalid or the change could not be saved
     */
    public function classify(int $candidateId, string $decision, array $input): Phrase
    {
        $candidate = $this->candidates->get($candidateId);
        if ($candidate === null) {
            throw new LocalizedException(__('This candidate no longer exists. It may have been removed by retention.'));
        }
        $type = (string)$candidate['type'];
        $status = (string)$candidate['status'];
        $label = $this->displayValue($candidate);

        if (in_array($decision, [self::DECISION_NOT_AI, self::DECISION_IGNORED, self::DECISION_RESTORE], true)) {
            if ($status === CandidateRepository::STATUS_CONFIRMED) {
                throw new LocalizedException(
                    __('"%1" is already confirmed. Delete its rule under Confirmed first, then classify it again.', $label)
                );
            }
            $target = $decision === self::DECISION_RESTORE ? CandidateRepository::STATUS_NEW : $decision;
            $this->candidates->setStatus($candidateId, $target);
            if ($decision === self::DECISION_NOT_AI) {
                return __('Marked "%1" as not AI. Its requests are no longer logged as candidates.', $label);
            }
            if ($decision === self::DECISION_IGNORED) {
                return __('Ignored "%1". Its requests are no longer logged as candidates.', $label);
            }
            return __('Moved "%1" back to the New list.', $label);
        }

        if ($type === RuleProvider::TYPE_BOT) {
            if (!Category::isValid($decision)) {
                throw new LocalizedException(__('Choose Training, Search, Live or Other to confirm an AI bot.'));
            }
            return $this->confirmBot($candidate, $decision, $input);
        }
        if ($type === RuleProvider::TYPE_SOURCE) {
            if ($decision !== self::DECISION_CONFIRM) {
                throw new LocalizedException(__('This candidate is an AI referrer source; use "Confirm as AI source".'));
            }
            return $this->confirmSource($candidate, $input);
        }
        throw new LocalizedException(__('This candidate has an unknown type and cannot be confirmed.'));
    }

    /**
     * Delete a confirmed rule and move its candidate back to the New list
     *
     * @param int $ruleId
     * @return Phrase
     * @throws LocalizedException
     */
    public function deleteRule(int $ruleId): Phrase
    {
        $rule = null;
        foreach (array_merge($this->ruleProvider->botRules(), $this->ruleProvider->sourceRules()) as $row) {
            if ((int)$row['rule_id'] === $ruleId && $ruleId > 0) {
                $rule = $row;
                break;
            }
        }
        if ($rule === null) {
            throw new LocalizedException(__('This rule no longer exists.'));
        }
        $this->ruleWriter->delete($ruleId);
        $candidateId = (int)($rule['candidate_id'] ?? 0);
        if ($candidateId > 0 && $this->candidates->get($candidateId) !== null) {
            $this->candidates->setStatus($candidateId, CandidateRepository::STATUS_NEW);
            try {
                $this->relabeler->restoreCandidate($candidateId, (string)$rule['type'], (string)$rule['code']);
            } catch (\Throwable $e) {
                return __(
                    'Deleted "%1" and moved it back to the New list, but its earlier rows could not be moved back '
                    . '(%2). They keep the code "%3".',
                    (string)$rule['name'],
                    $e->getMessage(),
                    (string)$rule['code']
                );
            }
            return __(
                'Deleted "%1" and moved it back to the New list with its raw rows. Summaries of days whose raw '
                . 'rows were already removed by retention keep the code "%2".',
                (string)$rule['name'],
                (string)$rule['code']
            );
        }
        return __(
            'Deleted "%1". Rows already counted under it keep the code "%2"; if it shows up again it is listed '
            . 'as a new candidate.',
            (string)$rule['name'],
            (string)$rule['code']
        );
    }

    /**
     * Suggested User-Agent token for a bot candidate ('' when none is obvious)
     *
     * @param string $userAgent
     * @return string
     */
    public function suggestToken(string $userAgent): string
    {
        if (BrowserUserAgents::isPlainBrowser($userAgent)) {
            // An ordinary browser has no token of its own; every word also matches other shoppers.
            return '';
        }
        $fallback = '';
        foreach (preg_split('/[\s;()]+/', $userAgent) ?: [] as $part) {
            if ($part === '' || $part[0] === '+' || str_contains($part, '://') || str_contains($part, '@')) {
                continue;
            }
            $product = trim(explode('/', $part)[0], " ,.");
            if (strlen($product) < self::TOKEN_MIN || strlen($product) > self::TOKEN_MAX
                || !preg_match(self::TOKEN_PATTERN, $product) || !preg_match('/[A-Za-z]{2}/', $product)
                || $this->isGeneric($product)
            ) {
                continue;
            }
            if (preg_match(self::BOT_LIKE, $product)) {
                return $product;
            }
            if ($fallback === '') {
                $fallback = $product;
            }
        }
        return $fallback;
    }

    /**
     * Readable candidate value: the User-Agent, host or utm_source value
     *
     * @param array $candidate
     * @return string
     */
    public function displayValue(array $candidate): string
    {
        $value = (string)($candidate['key_value'] ?? '');
        if ((string)($candidate['type'] ?? '') === RuleProvider::TYPE_SOURCE) {
            [, $value] = $this->parseSourceKey($value) + [null, $value];
        }
        return mb_strlen($value) > 80 ? mb_substr($value, 0, 77) . '...' : $value;
    }

    /**
     * Confirm a bot candidate
     *
     * @param array $candidate
     * @param string $category
     * @param array $input
     * @return Phrase
     * @throws LocalizedException
     */
    private function confirmBot(array $candidate, string $category, array $input): Phrase
    {
        $candidateId = (int)$candidate['candidate_id'];
        $userAgent = (string)$candidate['key_value'];
        $name = $this->validateName((string)($input['name'] ?? ''), __('name'), self::NAME_MAX, true);
        $company = $this->validateName((string)($input['company'] ?? ''), __('company'), self::COMPANY_MAX, false);
        $token = trim(preg_replace('/\s+/', ' ', (string)($input['token'] ?? '')) ?? '');
        if ($token === '') {
            $token = $this->suggestToken($userAgent);
        }
        if (strlen($token) < self::TOKEN_MIN || strlen($token) > self::TOKEN_MAX
            || !preg_match(self::TOKEN_PATTERN, $token)
        ) {
            throw new LocalizedException(
                __(
                    'Enter the part of the User-Agent that identifies this bot (%1-%2 letters, digits or . _ / + : -), '
                    . 'for example its product name.',
                    self::TOKEN_MIN,
                    self::TOKEN_MAX
                )
            );
        }
        if ($this->isGeneric($token)) {
            throw new LocalizedException(
                __(
                    '"%1" also appears in normal browser or tool User-Agents, or is shorter than 4 characters. '
                    . 'Enter the bot\'s own name instead.',
                    $token
                )
            );
        }
        if (stripos($userAgent, $token) === false) {
            throw new LocalizedException(__('"%1" does not appear in this candidate\'s User-Agent.', $token));
        }
        // Retry after "the rule was saved, but earlier rows could not be moved": the candidate is now
        // recognised by its own rule, which is not a clash.
        $ownCode = (string)($this->ruleForCandidate(RuleProvider::TYPE_BOT, $candidateId)['code'] ?? '');
        $known = $this->botClassifier->classify($userAgent);
        if ($known !== null && $known->code !== $ownCode) {
            throw new LocalizedException(
                __(
                    'This User-Agent is already recognised as "%1" (code %2). Mark the candidate as Ignored.',
                    $known->name,
                    $known->code
                )
            );
        }
        foreach ($this->ruleProvider->botRules() as $rule) {
            if ((int)($rule['candidate_id'] ?? 0) !== $candidateId
                && strcasecmp((string)$rule['pattern'], $token) === 0
            ) {
                throw new LocalizedException(
                    __('The confirmed bot "%1" already uses the token "%2".', (string)$rule['name'], $token)
                );
            }
        }

        $code = $this->uniqueCode(RuleProvider::TYPE_BOT, $name, $candidateId);
        $this->ruleWriter->save([
            'type' => RuleProvider::TYPE_BOT,
            'code' => $code,
            'name' => $name,
            'match_on' => RuleProvider::MATCH_UA,
            'pattern' => $token,
            'category' => $category,
            'company' => $company !== '' ? $company : $name,
            'candidate_id' => $candidateId,
        ]);
        $moved = $this->relabel($candidateId, RuleProvider::TYPE_BOT, $code, $category);
        $this->candidates->setStatus($candidateId, CandidateRepository::STATUS_CONFIRMED);
        return __(
            'Saved "%1" as an AI bot (%2, code %3). %4 earlier hit(s) were moved to it.',
            $name,
            $category,
            $code,
            $moved
        );
    }

    /**
     * Confirm a source candidate
     *
     * @param array $candidate
     * @param array $input
     * @return Phrase
     * @throws LocalizedException
     */
    private function confirmSource(array $candidate, array $input): Phrase
    {
        $candidateId = (int)$candidate['candidate_id'];
        $parsed = $this->parseSourceKey((string)$candidate['key_value']);
        if ($parsed === []) {
            throw new LocalizedException(__('This candidate has no valid host or utm_source value.'));
        }
        [$matchOn, $value] = $parsed;
        $name = $this->validateName((string)($input['name'] ?? ''), __('name'), self::NAME_MAX, true);
        $ownCode = (string)($this->ruleForCandidate(RuleProvider::TYPE_SOURCE, $candidateId)['code'] ?? '');
        $known = $matchOn === RuleProvider::MATCH_HOST
            ? $this->sourceClassifier->classifyHost($value)
            : $this->sourceClassifier->classifyUtm($value);
        if ($known !== null && $known->code !== $ownCode) {
            throw new LocalizedException(
                __(
                    '"%1" is already recognised as "%2" (code %3). Mark the candidate as Ignored.',
                    $value,
                    $known->name,
                    $known->code
                )
            );
        }
        $code = $this->uniqueCode(RuleProvider::TYPE_SOURCE, $name, $candidateId);
        $this->ruleWriter->save([
            'type' => RuleProvider::TYPE_SOURCE,
            'code' => $code,
            'name' => $name,
            'match_on' => $matchOn,
            'pattern' => $value,
            'candidate_id' => $candidateId,
        ]);
        $moved = $this->relabel($candidateId, RuleProvider::TYPE_SOURCE, $code, null);
        $this->candidates->setStatus($candidateId, CandidateRepository::STATUS_CONFIRMED);
        return __('Saved "%1" as an AI source (code %2). %3 earlier visit(s) were moved to it.', $name, $code, $moved);
    }

    /**
     * Move the candidate rows; the rule is already saved at this point
     *
     * @param int $candidateId
     * @param string $type
     * @param string $code
     * @param string|null $category
     * @return int
     * @throws LocalizedException
     */
    private function relabel(int $candidateId, string $type, string $code, ?string $category): int
    {
        try {
            return $this->relabeler->relabelCandidate($candidateId, $type, $code, $category);
        } catch (\Throwable $e) {
            throw new LocalizedException(
                __(
                    'The rule was saved and new traffic is recognised, but earlier rows could not be moved (%1). '
                    . 'Confirm the candidate again to retry.',
                    $e->getMessage()
                ),
                $e instanceof \Exception ? $e : null
            );
        }
    }

    /**
     * Confirmed rule that was created from the candidate, or null
     *
     * @param string $type
     * @param int $candidateId
     * @return array|null
     */
    private function ruleForCandidate(string $type, int $candidateId): ?array
    {
        $rules = $type === RuleProvider::TYPE_BOT ? $this->ruleProvider->botRules() : $this->ruleProvider->sourceRules();
        foreach ($rules as $rule) {
            if ($candidateId > 0 && (int)($rule['candidate_id'] ?? 0) === $candidateId) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Rule code for a candidate: its existing rule code, else "disc_<slug>" made unique
     *
     * Codes are checked against every confirmed rule of the type, the built-in bot codes and,
     * for sources, every known source code.
     *
     * @param string $type
     * @param string $name
     * @param int $candidateId
     * @return string
     */
    private function uniqueCode(string $type, string $name, int $candidateId): string
    {
        $rules = $type === RuleProvider::TYPE_BOT ? $this->ruleProvider->botRules() : $this->ruleProvider->sourceRules();
        $taken = [];
        foreach ($rules as $rule) {
            if ((int)($rule['candidate_id'] ?? 0) === $candidateId) {
                return (string)$rule['code'];
            }
            $taken[(string)$rule['code']] = true;
        }
        if ($type === RuleProvider::TYPE_SOURCE) {
            foreach (array_keys($this->sourceClassifier->allSources()) as $code) {
                $taken[(string)$code] = true;
            }
        }

        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        if ($slug === '') {
            $slug = $type . '_' . $candidateId;
        }
        $base = substr(self::CODE_PREFIX . $slug, 0, 64);
        $code = rtrim($base, '_');
        for ($i = 2; $this->isTaken($code, $type, $taken); $i++) {
            $suffix = '_' . $i;
            $code = rtrim(substr($base, 0, 64 - strlen($suffix)), '_') . $suffix;
        }
        return $code;
    }

    /**
     * Whether a code is already used
     *
     * @param string $code
     * @param string $type
     * @param array $taken
     * @return bool
     */
    private function isTaken(string $code, string $type, array $taken): bool
    {
        return isset($taken[$code]) || ($type === RuleProvider::TYPE_BOT && $this->botNames->isBuiltIn($code));
    }

    /**
     * Validate a display name or company
     *
     * @param string $value
     * @param Phrase $field
     * @param int $max
     * @param bool $required
     * @return string
     * @throws LocalizedException
     */
    private function validateName(string $value, Phrase $field, int $max, bool $required): string
    {
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        if ($value === '' && !$required) {
            return '';
        }
        if ($value === '' || mb_strlen($value) > $max || !preg_match(self::NAME_PATTERN, $value)) {
            throw new LocalizedException(
                __(
                    'Enter a %1 of 1-%2 characters: letters, digits, spaces and . , & ( ) + / \' _ : -',
                    $field,
                    $max
                )
            );
        }
        return $value;
    }

    /**
     * [match_on, value] of a source key "host:NAME" / "utm:VALUE", or [] when invalid
     *
     * @param string $key
     * @return array
     */
    private function parseSourceKey(string $key): array
    {
        if (str_starts_with($key, 'host:')) {
            $host = strtolower(substr($key, 5));
            if ($host !== '' && strlen($host) <= 255 && !str_contains($host, '..')
                && preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host)
            ) {
                return [RuleProvider::MATCH_HOST, $host];
            }
            return [];
        }
        if (str_starts_with($key, 'utm:')) {
            $utm = strtolower(substr($key, 4));
            return preg_match('/^[a-z0-9._-]{1,64}$/', $utm) ? [RuleProvider::MATCH_UTM, $utm] : [];
        }
        return [];
    }

    /**
     * Whether a token is too generic to identify a bot
     *
     * @param string $token
     * @return bool
     */
    private function isGeneric(string $token): bool
    {
        return in_array(strtolower($token), self::GENERIC_TOKENS, true)
            || BrowserUserAgents::sharesBrowserText($token);
    }
}
