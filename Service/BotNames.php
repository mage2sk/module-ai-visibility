<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

/**
 * Display names of bot codes for the admin pages.
 *
 * Names come from BotClassifier::knownBots() (built-in catalogue, custom bots from the configuration
 * and confirmed Discovery rules, with the exact codes stored in the hit table). Candidate codes
 * ("cand:ID") and anything else are made readable from the code.
 */
class BotNames
{
    public const CANDIDATE_PREFIX = \Panth\AiVisibility\Api\HitWriterInterface::CANDIDATE_PREFIX;

    /**
     * @var array<string, string>|null
     */
    private ?array $names = null;

    /**
     * @param BotClassifier $botClassifier
     */
    public function __construct(
        private readonly BotClassifier $botClassifier
    ) {
    }

    /**
     * Every known bot: code => "Name (Company)", sorted by name
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }
        $names = [];
        foreach ($this->botClassifier->knownBots() as $code => $bot) {
            $names[$code] = $this->label($bot->name, $bot->company);
        }
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $this->names = $names;
    }

    /**
     * Display name of one bot code
     *
     * @param string $code
     * @return string
     */
    public function nameFor(string $code): string
    {
        $all = $this->all();
        if (isset($all[$code])) {
            return $all[$code];
        }
        $candidateId = $this->candidateId($code);
        if ($candidateId !== null) {
            return (string)__('Unknown bot #%1', $candidateId);
        }
        if (str_starts_with($code, 'disc_')) {
            return (string)__('Deleted rule (%1)', $code);
        }
        return $code === '' ? (string)__('(none)') : $code;
    }

    /**
     * Candidate ID of a "cand:ID" code, or null
     *
     * @param string $code
     * @return int|null
     */
    public function candidateId(string $code): ?int
    {
        if (!str_starts_with($code, self::CANDIDATE_PREFIX)) {
            return null;
        }
        $id = substr($code, strlen(self::CANDIDATE_PREFIX));
        return ctype_digit($id) && (int)$id > 0 ? (int)$id : null;
    }

    /**
     * Whether a code belongs to the built-in catalogue
     *
     * @param string $code
     * @return bool
     */
    public function isBuiltIn(string $code): bool
    {
        return $this->botClassifier->isBuiltIn($code);
    }

    /**
     * "Name (Company)", or just the name when the company is empty or the same
     *
     * @param string $name
     * @param string $company
     * @return string
     */
    private function label(string $name, string $company): string
    {
        $company = trim($company);
        if ($company === '' || strcasecmp($company, $name) === 0) {
            return $name;
        }
        return $name . ' (' . $company . ')';
    }
}
