<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Verification;

use Magento\Framework\App\ResourceConnection;
use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;

/**
 * Table access for verification: action rows, due actions, impact upserts and action status.
 */
class ImpactStore
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Action row with the recommendation's subject type under "rec_subject_type" (null when the
     * recommendation is gone), or null when the action does not exist
     *
     * @param int $actionId
     * @return array|null
     */
    public function getAction(int $actionId): ?array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->table(ActionLogInterface::TABLE)])
            ->joinLeft(
                ['r' => $this->table(RecommendationRepositoryInterface::TABLE)],
                'r.rec_id = a.rec_id',
                ['rec_subject_type' => 'r.subject_type']
            )
            ->where('a.action_id = ?', $actionId)
            ->limit(1);
        $row = $connection->fetchRow($select);
        return $row ? $row : null;
    }

    /**
     * IDs of pending, not undone actions whose verify_after day has been reached, oldest first
     *
     * @param string $today 'Y-m-d' (UTC)
     * @param int $limit
     * @return int[]
     */
    public function dueActionIds(string $today, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->table(ActionLogInterface::TABLE)], ['a.action_id'])
            ->where('a.status = ?', ActionLogInterface::STATUS_PENDING)
            ->where('a.undone_at IS NULL')
            ->where('a.verify_after <= ?', $today)
            ->order(['a.verify_after ASC', 'a.action_id ASC'])
            ->limit(max(1, $limit));
        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Insert or replace the impact row of an action (unique on action_id)
     *
     * @param array $row panth_aiv_impact columns; "details" already JSON-encoded
     * @return void
     */
    public function saveImpact(array $row): void
    {
        $connection = $this->resource->getConnection();
        $columns = array_values(array_diff(array_keys($row), ['action_id', 'impact_id']));
        $connection->insertOnDuplicate($this->table(ImpactCalculatorInterface::TABLE), $row, $columns);
    }

    /**
     * Stored impact row of an action ("details" as stored JSON), or null
     *
     * @param int $actionId
     * @return array|null
     */
    public function getImpact(int $actionId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table(ImpactCalculatorInterface::TABLE))
                ->where('action_id = ?', $actionId)
                ->limit(1)
        );
        return $row ? $row : null;
    }

    /**
     * Set the status of an action that has not been undone
     *
     * @param int $actionId
     * @param string $status
     * @return void
     */
    public function setActionStatus(int $actionId, string $status): void
    {
        $this->resource->getConnection()->update(
            $this->table(ActionLogInterface::TABLE),
            ['status' => $status],
            ['action_id = ?' => $actionId, 'undone_at IS NULL']
        );
    }

    /**
     * Prefixed table name
     *
     * @param string $name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
