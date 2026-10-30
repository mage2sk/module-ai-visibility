<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

/**
 * Log of fixes the merchant applied (panth_aiv_action).
 *
 * apply() copies rule_code, store_id, metric and the subject list from the recommendation, sets
 * verify_after = applied UTC day + Config::verifyWindowDays() + 1 and marks the recommendation applied.
 */
interface ActionLogInterface
{
    public const TABLE = 'panth_aiv_action';

    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_INSUFFICIENT = 'insufficient';
    public const STATUS_UNDONE = 'undone';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_INSUFFICIENT,
        self::STATUS_UNDONE,
    ];

    public const MAX_NOTE = 512;

    /**
     * Record that a recommendation was applied
     *
     * @param int $recId
     * @param string|null $note Merchant note (max 512)
     * @param string|null $adminUser Admin username, null from the CLI
     * @return int New action_id
     * @throws \Magento\Framework\Exception\LocalizedException unknown recommendation
     */
    public function apply(int $recId, ?string $note, ?string $adminUser): int;

    /**
     * Mark an action undone (undone_at = now, status undone); undone actions are never verified
     *
     * @param int $actionId
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException unknown action
     */
    public function undo(int $actionId): void;

    /**
     * Latest actions, newest first, each with its impact row under "impact" (null while not verified)
     * and the recommendation's "title" and "subject_type" joined in one query (null when it is gone)
     *
     * @param int $limit
     * @return array[]
     */
    public function listRecent(int $limit): array;
}
