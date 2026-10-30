<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Discovery;

use Magento\Framework\App\ResourceConnection;
use Panth\AiVisibility\Model\Catalogue\BrowserUserAgents;
use Magento\Framework\DB\Sql\Expression;

/**
 * Reads discovery candidates (panth_aiv_candidate) and changes their review status.
 */
class CandidateRepository
{
    public const TABLE = 'panth_aiv_candidate';

    public const STATUS_NEW = 'new';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_NOT_AI = 'not_ai';
    public const STATUS_IGNORED = 'ignored';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_CONFIRMED, self::STATUS_NOT_AI, self::STATUS_IGNORED];

    private const COLUMNS = [
        'candidate_id', 'type', 'key_value', 'first_seen', 'last_seen', 'hits',
        'llms_fetches', 'robots_fetches', 'evidence', 'status', 'is_simulated',
    ];

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * One candidate row, or null
     *
     * @param int $candidateId
     * @return array|null
     */
    public function get(int $candidateId): ?array
    {
        if ($candidateId <= 0) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table(), self::COLUMNS)
                ->where('candidate_id = ?', $candidateId)
        );
        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * Candidates with a status, most active first
     *
     * @param string $status
     * @param int $limit
     * @return array
     */
    public function getList(string $status, int $limit = 200): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            return [];
        }
        $connection = $this->resource->getConnection();
        return $connection->fetchAll(
            $connection->select()
                ->from($this->table(), self::COLUMNS)
                ->where('status = ?', $status)
                ->order(['evidence DESC', 'hits DESC', 'candidate_id DESC'])
                ->limit(max(1, min(1000, $limit)))
        );
    }

    /**
     * Number of candidates per status (every status present, 0 when none)
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $connection = $this->resource->getConnection();
        $pairs = $connection->fetchPairs(
            $connection->select()
                ->from($this->table(), ['status', 'total' => new Expression('COUNT(*)')])
                ->group('status')
        );
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($pairs as $status => $total) {
            if (isset($counts[$status])) {
                $counts[$status] = (int)$total;
            }
        }
        return $counts;
    }

    /**
     * Key values (User-Agent or host:/utm: key) of several candidates: id => key_value
     *
     * @param int[] $candidateIds
     * @return array<int, string>
     */
    public function keyValues(array $candidateIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $candidateIds), fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $pairs = $connection->fetchPairs(
            $connection->select()
                ->from($this->table(), ['candidate_id', 'key_value'])
                ->where('candidate_id IN (?)', $ids)
        );
        $result = [];
        foreach ($pairs as $id => $value) {
            $result[(int)$id] = (string)$value;
        }
        return $result;
    }

    /**
     * Change the review status of a candidate
     *
     * @param int $candidateId
     * @param string $status
     * @return void
     */
    public function setStatus(int $candidateId, string $status): void
    {
        if ($candidateId <= 0 || !in_array($status, self::STATUSES, true)) {
            return;
        }
        $this->resource->getConnection()->update(
            $this->table(),
            ['status' => $status],
            ['candidate_id = ?' => $candidateId]
        );
    }

    /**
     * Mark new bot candidates whose User-Agent is an ordinary browser as "not AI"; returns the number changed
     *
     * Candidates collected before browsers were excluded from Discovery would otherwise stay listed forever.
     *
     * @return int
     */
    public function retireBrowserCandidates(): int
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->table(), ['candidate_id', 'key_value'])
                ->where('type = ?', 'bot')
                ->where('status = ?', self::STATUS_NEW)
        );
        $ids = [];
        foreach ($rows as $candidateId => $userAgent) {
            if (BrowserUserAgents::isPlainBrowser((string)$userAgent)) {
                $ids[] = (int)$candidateId;
            }
        }
        if ($ids === []) {
            return 0;
        }
        return (int)$connection->update(
            $this->table(),
            ['status' => self::STATUS_NOT_AI],
            ['candidate_id IN (?)' => $ids, 'status = ?' => self::STATUS_NEW]
        );
    }

    /**
     * Candidate table name
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
