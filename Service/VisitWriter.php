<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Writes collector rows: AI visits, sampled direct landings and source discovery candidates.
 *
 * Bound parameters only; every datetime is UTC.
 */
class VisitWriter
{
    public const TABLE_VISIT = 'panth_aiv_visit';
    public const TABLE_LANDING = 'panth_aiv_landing';
    public const TABLE_CANDIDATE = 'panth_aiv_candidate';

    public const MEDIUM_REFERRER = 'referrer';
    public const MEDIUM_UTM = 'utm';

    public const CANDIDATE_TYPE_SOURCE = 'source';
    public const CANDIDATE_PREFIX = \Panth\AiVisibility\Api\HitWriterInterface::CANDIDATE_PREFIX;

    public const KEY_PREFIX_HOST = 'host:';
    public const KEY_PREFIX_UTM = 'utm:';

    /**
     * Candidate statuses whose visits are no longer recorded
     */
    public const SKIP_STATUSES = ['not_ai', 'ignored'];

    /**
     * @param ResourceConnection $resource
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Insert one visit from an AI source (or a candidate, source code "cand:<id>")
     *
     * @param int $storeId
     * @param string $sourceCode
     * @param string $medium referrer | utm
     * @param string|null $referrerHost Host name only
     * @param string $landingPath URL path without query
     * @return void
     */
    public function insertVisit(
        int $storeId,
        string $sourceCode,
        string $medium,
        ?string $referrerHost,
        string $landingPath
    ): void {
        $connection = $this->resource->getConnection();
        $connection->insert($this->resource->getTableName(self::TABLE_VISIT), [
            'created_at' => $this->nowString(),
            'store_id' => max(0, $storeId),
            'source_code' => substr($sourceCode, 0, 64),
            'medium' => $medium === self::MEDIUM_UTM ? self::MEDIUM_UTM : self::MEDIUM_REFERRER,
            'referrer_host' => $referrerHost !== null && $referrerHost !== '' ? substr($referrerHost, 0, 255) : null,
            'landing_path' => $landingPath,
            'path_hash' => sha1($landingPath),
        ]);
    }

    /**
     * Insert one sampled direct (no referrer) deep landing
     *
     * @param int $storeId
     * @param string $path
     * @return void
     */
    public function insertLanding(int $storeId, string $path): void
    {
        $connection = $this->resource->getConnection();
        $connection->insert($this->resource->getTableName(self::TABLE_LANDING), [
            'created_at' => $this->nowString(),
            'store_id' => max(0, $storeId),
            'path' => $path,
            'path_hash' => sha1($path),
        ]);
    }

    /**
     * Upsert a source candidate ("host:<host>" or "utm:<value>") and count one visit
     *
     * Returns the candidate id, or null when the candidate was marked not_ai / ignored
     * (its visits are then not recorded).
     *
     * @param string $key
     * @return int|null
     */
    public function upsertSourceCandidate(string $key): ?int
    {
        $key = strtolower(substr($key, 0, 512));
        $hash = self::candidateKeyHash($key);
        $now = $this->nowString();
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_CANDIDATE);
        $connection->query(
            'INSERT INTO ' . $connection->quoteIdentifier($table)
            . ' (type, key_hash, key_value, first_seen, last_seen, hits) VALUES (?, ?, ?, ?, ?, 1)'
            . ' ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = ?',
            [self::CANDIDATE_TYPE_SOURCE, $hash, $key, $now, $now, $now]
        );
        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, ['candidate_id', 'status'])
                ->where('type = ?', self::CANDIDATE_TYPE_SOURCE)
                ->where('key_hash = ?', $hash)
        );
        if (!is_array($row) || (int)($row['candidate_id'] ?? 0) <= 0) {
            return null;
        }
        if (in_array((string)($row['status'] ?? ''), self::SKIP_STATUSES, true)) {
            return null;
        }
        return (int)$row['candidate_id'];
    }

    /**
     * Source candidate key of a referrer host: "host:<host>", lower case, without a leading "www."
     *
     * Every writer of source candidates (collector, simulator) builds keys with these helpers,
     * so the same host always maps to the same candidate row.
     *
     * @param string $host
     * @return string
     */
    public static function hostCandidateKey(string $host): string
    {
        $host = (string)preg_replace('/^www\./', '', strtolower(trim($host)));
        return self::KEY_PREFIX_HOST . $host;
    }

    /**
     * Source candidate key of a utm_source value: "utm:<value>", lower case
     *
     * @param string $utm
     * @return string
     */
    public static function utmCandidateKey(string $utm): string
    {
        return self::KEY_PREFIX_UTM . strtolower(trim($utm));
    }

    /**
     * key_hash of a candidate key (case-insensitive, key cut to 512 characters like key_value)
     *
     * @param string $key
     * @return string
     */
    public static function candidateKeyHash(string $key): string
    {
        return sha1(strtolower(substr($key, 0, 512)));
    }

    /**
     * Current UTC time as 'Y-m-d H:i:s'
     *
     * @return string
     */
    private function nowString(): string
    {
        return gmdate('Y-m-d H:i:s', (int)$this->dateTime->gmtTimestamp());
    }
}
