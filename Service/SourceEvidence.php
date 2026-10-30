<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

use Magento\Framework\App\ResourceConnection;
use Panth\AiVisibility\Model\Source\Category;

/**
 * Scores new AI referrer source candidates.
 *
 * evidence = visits of the candidate in the last 7 days whose landing page (same store, same
 * real/simulated flag) was fetched by a live-answer bot in the 10 minutes before the visit.
 * Computed for every 'new' source candidate in one UPDATE ... JOIN statement.
 */
class SourceEvidence
{
    public const CANDIDATE_TABLE = 'panth_aiv_candidate';
    public const WINDOW_DAYS = 7;
    public const LAG_MINUTES = 10;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Recompute evidence of all new source candidates
     *
     * @param int|null $now Unix time, null for now
     * @return int Candidate rows changed
     */
    public function update(?int $now = null): int
    {
        $now ??= time();
        $connection = $this->resource->getConnection();
        $candidates = $connection->quoteIdentifier($this->resource->getTableName(self::CANDIDATE_TABLE));
        $visits = $connection->quoteIdentifier($this->resource->getTableName(Rollup::VISIT_TABLE));
        $hits = $connection->quoteIdentifier($this->resource->getTableName(Rollup::HIT_TABLE));

        $sql = 'UPDATE ' . $candidates . ' AS c'
            . ' LEFT JOIN ('
            . 'SELECT v.source_code, COUNT(*) AS evidence FROM ' . $visits . ' AS v'
            . ' WHERE v.created_at >= ? AND v.source_code LIKE ?'
            . ' AND EXISTS (SELECT 1 FROM ' . $hits . ' AS h'
            . ' WHERE h.path_hash = v.path_hash AND h.store_id = v.store_id'
            . ' AND h.is_simulated = v.is_simulated AND h.category = ?'
            . ' AND h.created_at >= v.created_at - INTERVAL ' . self::LAG_MINUTES . ' MINUTE'
            . ' AND h.created_at <= v.created_at)'
            . ' GROUP BY v.source_code'
            . ') AS e ON e.source_code = CONCAT(?, c.candidate_id)'
            . ' SET c.evidence = COALESCE(e.evidence, 0)'
            . ' WHERE c.type = ? AND c.status = ?';
        $bind = [
            gmdate('Y-m-d H:i:s', $now - self::WINDOW_DAYS * 86400),
            Rollup::CANDIDATE_PREFIX . '%',
            Category::LIVE,
            Rollup::CANDIDATE_PREFIX,
            Relabeler::TYPE_SOURCE,
            'new',
        ];
        return (int)$connection->query($sql, $bind)->rowCount();
    }
}
