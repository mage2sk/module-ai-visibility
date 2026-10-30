<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

use Panth\AiVisibility\Model\BotMatch;

/**
 * Classifies User-Agent strings. Pure in-memory checks plus the cached
 * confirmed rules: it must stay cheap because it runs for every request.
 */
interface BotClassifierInterface
{
    /**
     * Known AI bot (built-in catalogue, custom config patterns or confirmed rules), or null
     *
     * @param string $userAgent
     * @return BotMatch|null
     */
    public function classify(string $userAgent): ?BotMatch;

    /**
     * Bot-like User-Agent that is not a known AI bot and not on the non-AI exclusion list
     *
     * @param string $userAgent
     * @return bool
     */
    public function isDiscoveryCandidate(string $userAgent): bool;
}
