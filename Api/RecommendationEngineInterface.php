<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

/**
 * Runs every recommendation rule and keeps panth_aiv_recommendation in sync.
 *
 * Upsert by fingerprint; open recommendations that are not found again become "resolved"; applied or
 * dismissed recommendations are not reopened by the same fingerprint for 30 days. Keeps at most
 * Config::recommendationsMaxOpen() open rows per store view (highest impact first).
 */
interface RecommendationEngineInterface
{
    /**
     * Evaluate the rules and store the result
     *
     * @param int|null $storeId One store view, or null for every active store view
     * @param bool $dryRun Evaluate and count only; write nothing
     * @return array{created: int, updated: int, resolved: int}
     */
    public function run(?int $storeId = null, bool $dryRun = false): array;
}
