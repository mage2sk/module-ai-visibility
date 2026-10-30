<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

/**
 * Read / update access to panth_aiv_recommendation.
 *
 * Rows are arrays with the table columns; "evidence" is decoded from JSON to an array, and the subject
 * refs of the finding are stored inside it under EVIDENCE_SUBJECTS.
 */
interface RecommendationRepositoryInterface
{
    public const TABLE = 'panth_aiv_recommendation';

    public const STATUS_OPEN = 'open';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_RESOLVED = 'resolved';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_APPLIED,
        self::STATUS_DISMISSED,
        self::STATUS_RESOLVED,
    ];

    public const FEEDBACK_USEFUL = 1;
    public const FEEDBACK_NOT_USEFUL = -1;

    /**
     * Key of the subject list inside the stored evidence JSON
     */
    public const EVIDENCE_SUBJECTS = 'subjects';

    /**
     * Open recommendations, ranked (impact score adjusted by feedback), best first
     *
     * @param int|null $storeId Store view, or null for all
     * @param int $limit Max rows (>= 1)
     * @param bool $simulated Simulator rows instead of real ones (previews only; admin and cron stay real)
     * @return array[]
     */
    public function listOpen(?int $storeId, int $limit, bool $simulated = false): array;

    /**
     * One recommendation row, or null when it does not exist
     *
     * @param int $recId
     * @return array|null
     */
    public function get(int $recId): ?array;

    /**
     * Change the status (one of STATUSES) and status_changed_at
     *
     * @param int $recId
     * @param string $status
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException unknown status or recommendation
     */
    public function setStatus(int $recId, string $status): void;

    /**
     * Store merchant feedback: FEEDBACK_USEFUL (1) or FEEDBACK_NOT_USEFUL (-1)
     *
     * @param int $recId
     * @param int $value
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException invalid value or unknown recommendation
     */
    public function setFeedback(int $recId, int $value): void;

    /**
     * Number of recommendations per status: status => count (every status present, 0 when none)
     *
     * @return array<string, int>
     */
    public function counts(): array;
}
