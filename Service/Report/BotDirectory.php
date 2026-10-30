<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Report;

use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\BotClassifier;

/**
 * Display data (name, company, category) for the bot codes stored in the daily tables.
 *
 * Reads BotClassifier::knownBots(): the built-in catalogue, custom bots from the configuration
 * ("custom_<slug>", the codes the classifier stores) and bots confirmed on the Discovery page.
 * Unknown codes are shown as the code itself.
 */
class BotDirectory
{
    /**
     * @var array<string, array{name: string, company: string, category: string}>|null
     */
    private ?array $map = null;

    /**
     * @param BotClassifier $botClassifier
     */
    public function __construct(
        private readonly BotClassifier $botClassifier
    ) {
    }

    /**
     * Name, company and category for a bot code
     *
     * @param string $code
     * @param string $fallbackCategory Category stored with the row, used for unknown codes
     * @return array{name: string, company: string, category: string}
     */
    public function describe(string $code, string $fallbackCategory = Category::OTHER): array
    {
        $map = $this->map();
        if (isset($map[$code])) {
            return $map[$code];
        }
        if (str_starts_with($code, 'disc_')) {
            // Summary rows of a Discovery rule that was deleted after its raw rows were pruned.
            $label = (string)__('Deleted rule (%1)', $code);
            return ['name' => $label, 'company' => $label, 'category' => $fallbackCategory];
        }
        return ['name' => $code, 'company' => $code, 'category' => $fallbackCategory];
    }

    /**
     * Build the code map once per request
     *
     * @return array<string, array{name: string, company: string, category: string}>
     */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }
        $map = [];
        foreach ($this->botClassifier->knownBots() as $code => $bot) {
            $map[$code] = ['name' => $bot->name, 'company' => $bot->company, 'category' => $bot->category];
        }
        return $this->map = $map;
    }
}
