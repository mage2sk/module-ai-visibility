<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;

/**
 * Recommendation repository (panth_aiv_recommendation).
 *
 * Ranking: impact_score, halved while the merchant marked another recommendation of the same rule and
 * store view "not useful" in the last FEEDBACK_DAYS days (dated by its status change, or its last
 * sighting when the status never changed). Simulator rows are left out of counts() and of listOpen() unless
 * it is asked for them (simulated previews such as email --simulated --dry-run).
 * The engine-facing methods (byFingerprints, openRows, save, resolve, penalisedRules) work in batches
 * of at most CHUNK rows.
 */
class Repository implements RecommendationRepositoryInterface
{
    public const FEEDBACK_DAYS = 30;
    public const FEEDBACK_FACTOR = 0.5;
    public const MAX_LIST = 500;
    private const CHUNK = 500;

    private const INT_COLUMNS = ['rec_id', 'store_id', 'affected_count', 'impact_score', 'is_simulated'];

    /**
     * Columns the engine writes (besides rec_id)
     */
    public const WRITE_COLUMNS = [
        'fingerprint',
        'rule_code',
        'store_id',
        'subject_type',
        'subject_ref',
        'title',
        'evidence',
        'affected_count',
        'impact_score',
        'metric',
        'status',
        'first_seen_at',
        'last_seen_at',
        'status_changed_at',
        'is_simulated',
    ];

    /**
     * Columns refreshed when an open recommendation is found again
     */
    public const REFRESH_COLUMNS = [
        'subject_type',
        'title',
        'evidence',
        'affected_count',
        'impact_score',
        'metric',
        'last_seen_at',
    ];

    /**
     * @param ResourceConnection $resource
     * @param Json $json
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json
    ) {
    }

    /**
     * @inheritdoc
     */
    public function listOpen(?int $storeId, int $limit, bool $simulated = false): array
    {
        $bind = [
            'status' => self::STATUS_OPEN,
            'since' => $this->feedbackSince(time()),
            'simulated' => $simulated ? 1 : 0,
        ];
        $rank = $this->rankExpression();
        $sql = 'SELECT r.*, ' . $rank . ' AS rank_score FROM ' . $this->table() . ' AS r'
            . ' WHERE r.status = :status AND r.is_simulated = :simulated';
        if ($storeId !== null) {
            $sql .= ' AND r.store_id = :store';
            $bind['store'] = $storeId;
        }
        $sql .= ' ORDER BY rank_score DESC, r.affected_count DESC, r.rec_id ASC LIMIT '
            . max(1, min(self::MAX_LIST, $limit));
        return array_map([$this, 'hydrate'], $this->connection()->fetchAll($sql, $bind));
    }

    /**
     * @inheritdoc
     */
    public function get(int $recId): ?array
    {
        $row = $this->connection()->fetchRow(
            'SELECT * FROM ' . $this->table() . ' WHERE rec_id = :id',
            ['id' => $recId]
        );
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @inheritdoc
     */
    public function setStatus(int $recId, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new LocalizedException(__('Unknown recommendation status "%1".', $status));
        }
        $this->assertExists($recId);
        $this->connection()->update(
            $this->table(),
            ['status' => $status, 'status_changed_at' => gmdate('Y-m-d H:i:s')],
            ['rec_id = ?' => $recId]
        );
    }

    /**
     * @inheritdoc
     */
    public function setFeedback(int $recId, int $value): void
    {
        if ($value !== self::FEEDBACK_USEFUL && $value !== self::FEEDBACK_NOT_USEFUL) {
            throw new LocalizedException(__('Feedback must be 1 (useful) or -1 (not useful).'));
        }
        $this->assertExists($recId);
        $this->connection()->update(
            $this->table(),
            ['feedback' => $value, 'feedback_at' => gmdate('Y-m-d H:i:s')],
            ['rec_id = ?' => $recId]
        );
    }

    /**
     * @inheritdoc
     */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        $pairs = $this->connection()->fetchPairs(
            'SELECT status, COUNT(*) FROM ' . $this->table() . ' WHERE is_simulated = 0 GROUP BY status'
        );
        foreach ($pairs as $status => $count) {
            if (isset($counts[$status])) {
                $counts[$status] = (int)$count;
            }
        }
        return $counts;
    }

    /**
     * Existing rows by fingerprint: fingerprint => [rec_id, status, status_changed_at, is_simulated, feedback]
     *
     * @param string[] $fingerprints
     * @return array<string, array>
     */
    public function byFingerprints(array $fingerprints): array
    {
        $result = [];
        $fingerprints = array_values(array_unique(array_map('strval', $fingerprints)));
        foreach (array_chunk($fingerprints, self::CHUNK) as $chunk) {
            $bind = [];
            foreach ($chunk as $i => $fingerprint) {
                $bind['f' . $i] = $fingerprint;
            }
            $sql = 'SELECT rec_id, fingerprint, status, status_changed_at, is_simulated, feedback FROM '
                . $this->table() . ' WHERE fingerprint IN (:' . implode(', :', array_keys($bind)) . ')';
            foreach ($this->connection()->fetchAll($sql, $bind) as $row) {
                $row['rec_id'] = (int)$row['rec_id'];
                $row['is_simulated'] = (int)$row['is_simulated'];
                $result[(string)$row['fingerprint']] = $row;
            }
        }
        return $result;
    }

    /**
     * Open real (not simulated) rows of a store view: rec_id, fingerprint, rule_code, title, impact_score
     *
     * @param int $storeId
     * @return array<int, array>
     */
    public function openRows(int $storeId): array
    {
        $rows = $this->connection()->fetchAll(
            'SELECT rec_id, fingerprint, rule_code, title, impact_score, affected_count FROM ' . $this->table()
            . ' WHERE store_id = :store AND status = :status AND is_simulated = 0 ORDER BY rec_id ASC',
            ['store' => $storeId, 'status' => self::STATUS_OPEN]
        );
        foreach ($rows as &$row) {
            $row['rec_id'] = (int)$row['rec_id'];
            $row['impact_score'] = (int)$row['impact_score'];
            $row['affected_count'] = (int)$row['affected_count'];
        }
        return $rows;
    }

    /**
     * Rule codes with "not useful" feedback on this store view in the last FEEDBACK_DAYS days
     *
     * @param int $storeId
     * @param int $now Unix time
     * @return string[]
     */
    public function penalisedRules(int $storeId, int $now): array
    {
        return array_map('strval', $this->connection()->fetchCol(
            'SELECT DISTINCT rule_code FROM ' . $this->table()
            . ' WHERE store_id = :store AND feedback = :feedback AND is_simulated = 0'
            . ' AND ' . self::FEEDBACK_TIME . ' >= :since',
            ['store' => $storeId, 'feedback' => self::FEEDBACK_NOT_USEFUL, 'since' => $this->feedbackSince($now)]
        ));
    }

    /**
     * Insert or update rows by fingerprint
     *
     * @param array<int, array> $rows Rows with WRITE_COLUMNS ("evidence" as array)
     * @param string[] $updateColumns Columns overwritten when the fingerprint exists
     * @return int Rows written
     */
    public function save(array $rows, array $updateColumns): int
    {
        if ($rows === []) {
            return 0;
        }
        $data = [];
        foreach ($rows as $row) {
            $item = [];
            foreach (self::WRITE_COLUMNS as $column) {
                $item[$column] = $row[$column] ?? null;
            }
            $item['evidence'] = $this->json->serialize(is_array($row['evidence'] ?? null) ? $row['evidence'] : []);
            $data[] = $item;
        }
        $written = 0;
        foreach (array_chunk($data, self::CHUNK) as $chunk) {
            $this->connection()->insertOnDuplicate($this->table(), $chunk, $updateColumns);
            $written += count($chunk);
        }
        return $written;
    }

    /**
     * Mark open rows resolved
     *
     * @param int[] $recIds
     * @param string $now 'Y-m-d H:i:s'
     * @return void
     */
    public function resolve(array $recIds, string $now): void
    {
        $recIds = array_values(array_unique(array_map('intval', $recIds)));
        foreach (array_chunk($recIds, self::CHUNK) as $chunk) {
            $this->connection()->update(
                $this->table(),
                ['status' => self::STATUS_RESOLVED, 'status_changed_at' => $now],
                ['rec_id IN (?)' => $chunk, 'status = ?' => self::STATUS_OPEN]
            );
        }
    }

    /**
     * Run a callback in a database transaction (rolled back and rethrown on failure)
     *
     * @param callable $callback
     * @return mixed
     * @throws \Throwable
     */
    public function transactional(callable $callback)
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $result = $callback();
            $connection->commit();
            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * SQL expression of the feedback-adjusted rank of row alias "r"
     *
     * @return string
     */
    private function rankExpression(): string
    {
        return '(r.impact_score * CASE WHEN EXISTS (SELECT 1 FROM ' . $this->table() . ' AS f'
            . ' WHERE f.rule_code = r.rule_code AND f.store_id = r.store_id AND f.feedback = '
            . self::FEEDBACK_NOT_USEFUL . ' AND f.is_simulated = 0'
            . ' AND COALESCE(f.feedback_at, f.status_changed_at, f.last_seen_at) >= :since) THEN '
            . self::FEEDBACK_FACTOR . ' ELSE 1 END)';
    }

    /**
     * When feedback was given: feedback_at, else (rows from before the column) the last status change / sighting
     */
    private const FEEDBACK_TIME = 'COALESCE(feedback_at, status_changed_at, last_seen_at)';

    /**
     * Start of the feedback window
     *
     * @param int $now
     * @return string
     */
    private function feedbackSince(int $now): string
    {
        return gmdate('Y-m-d H:i:s', $now - self::FEEDBACK_DAYS * 86400);
    }

    /**
     * Throw when the recommendation does not exist
     *
     * @param int $recId
     * @return void
     * @throws LocalizedException
     */
    private function assertExists(int $recId): void
    {
        $found = $this->connection()->fetchOne(
            'SELECT rec_id FROM ' . $this->table() . ' WHERE rec_id = :id',
            ['id' => $recId]
        );
        if (!$found) {
            throw new LocalizedException(__('Recommendation %1 does not exist.', $recId));
        }
    }

    /**
     * Typed row with decoded evidence
     *
     * @param array $row
     * @return array
     */
    private function hydrate(array $row): array
    {
        foreach (self::INT_COLUMNS as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = (int)$row[$column];
            }
        }
        $row['feedback'] = isset($row['feedback']) ? (int)$row['feedback'] : null;
        if (array_key_exists('rank_score', $row)) {
            $row['rank_score'] = round((float)$row['rank_score'], 2);
        }
        $evidence = [];
        if (is_string($row['evidence'] ?? null) && $row['evidence'] !== '') {
            try {
                $decoded = $this->json->unserialize($row['evidence']);
                $evidence = is_array($decoded) ? $decoded : [];
            } catch (\InvalidArgumentException $e) {
                $evidence = [];
            }
        }
        $row['evidence'] = $evidence;
        return $row;
    }

    /**
     * Prefixed table name
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    /**
     * Default connection
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
