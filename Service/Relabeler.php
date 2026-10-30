<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Api\RelabelerInterface;
use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Model\Source\Category;
use Psr\Log\LoggerInterface;

/**
 * Moves the raw rows of a confirmed discovery candidate ("cand:<id>") onto its new bot or source code
 * and re-rolls every day that held such rows; restoreCandidate() moves them back when the rule is deleted.
 *
 * Bot candidates: panth_aiv_hit rows get the new code and category; panth_aiv_overflow_daily counters
 * are merged into the new code (added to any counters the new code already has for that key). For days
 * whose raw hits are no longer complete (before the raw floor) the rollup keeps the existing daily rows,
 * so the merged overflow hits are added to panth_aiv_crawl_daily directly.
 * Source candidates: panth_aiv_visit rows get the new source code.
 */
class Relabeler implements RelabelerInterface
{
    public const TYPE_BOT = 'bot';
    public const TYPE_SOURCE = 'source';
    public const CANDIDATE_TABLE = 'panth_aiv_candidate';

    /**
     * @param ResourceConnection $resource
     * @param RollupInterface $rollup
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly RollupInterface $rollup,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function relabelCandidate(int $candidateId, string $type, string $code, ?string $category = null): int
    {
        if ($candidateId <= 0) {
            throw new \InvalidArgumentException('Invalid candidate ID.');
        }
        $code = $this->validCode($code);
        $this->validType($type);
        if ($type === self::TYPE_BOT) {
            $category ??= Category::OTHER;
            if (!Category::isValid($category)) {
                throw new \InvalidArgumentException(sprintf('Invalid bot category "%s".', $category));
            }
        }
        return $this->move($type, Rollup::CANDIDATE_PREFIX . $candidateId, $code, (string)$category);
    }

    /**
     * @inheritdoc
     */
    public function restoreCandidate(int $candidateId, string $type, string $code): int
    {
        if ($candidateId <= 0) {
            throw new \InvalidArgumentException('Invalid candidate ID.');
        }
        $this->validType($type);
        $moved = $this->move($type, $this->validCode($code), Rollup::CANDIDATE_PREFIX . $candidateId, Category::UNKNOWN);
        $this->refreshCandidateHits($candidateId, $type);
        return $moved;
    }

    /**
     * Raise the candidate's request counter to the rows it holds again after a restore
     *
     * Raw rows of old days may already be pruned, so the counter never goes below its earlier value.
     *
     * @param int $candidateId
     * @param string $type
     * @return void
     */
    private function refreshCandidateHits(int $candidateId, string $type): void
    {
        $connection = $this->resource->getConnection();
        $code = Rollup::CANDIDATE_PREFIX . $candidateId;
        if ($type === self::TYPE_BOT) {
            $count = (int)$connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($this->resource->getTableName(Rollup::HIT_TABLE))
                . ' WHERE bot_code = ?',
                [$code]
            ) + (int)$connection->fetchOne(
                'SELECT COALESCE(SUM(hits), 0) FROM '
                . $connection->quoteIdentifier($this->resource->getTableName(Rollup::OVERFLOW_TABLE))
                . ' WHERE bot_code = ?',
                [$code]
            );
        } else {
            $count = (int)$connection->fetchOne(
                'SELECT COUNT(*) FROM '
                . $connection->quoteIdentifier($this->resource->getTableName(Rollup::VISIT_TABLE))
                . ' WHERE source_code = ?',
                [$code]
            );
        }
        if ($count <= 0) {
            return;
        }
        $connection->update(
            $this->resource->getTableName(self::CANDIDATE_TABLE),
            ['hits' => new \Zend_Db_Expr('GREATEST(hits, ' . $count . ')')],
            ['candidate_id = ?' => $candidateId]
        );
    }

    /**
     * Move raw rows (and bot overflow counters) from one code to another, then re-roll the days
     *
     * @param string $type
     * @param string $oldCode
     * @param string $newCode
     * @param string $category
     * @return int Raw rows updated
     */
    private function move(string $type, string $oldCode, string $newCode, string $category): int
    {
        $connection = $this->resource->getConnection();
        $days = $type === self::TYPE_BOT
            ? $this->botDays($connection, $oldCode)
            : $this->sourceDays($connection, $oldCode);
        if ($days === []) {
            return 0;
        }

        $merged = [];
        $connection->beginTransaction();
        try {
            if ($type === self::TYPE_BOT) {
                $updated = (int)$connection->update(
                    $this->resource->getTableName(Rollup::HIT_TABLE),
                    ['bot_code' => $newCode, 'category' => $category],
                    ['bot_code = ?' => $oldCode]
                );
                $merged = $this->mergeOverflow($connection, $oldCode, $newCode, $category);
            } else {
                $updated = (int)$connection->update(
                    $this->resource->getTableName(Rollup::VISIT_TABLE),
                    ['source_code' => $newCode],
                    ['source_code = ?' => $oldCode]
                );
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $this->addOverflowToKeptDays($connection, $merged, $oldCode);
        foreach ($days as $day) {
            try {
                $this->rollup->rollupDay($day);
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    '[Panth_AiVisibility] re-roll of %s after relabelling %s failed: %s',
                    $day,
                    $oldCode,
                    $e->getMessage()
                ));
            }
        }
        return $updated;
    }

    /**
     * Valid target / source code
     *
     * @param string $code
     * @return string
     */
    private function validCode(string $code): string
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 64 || str_starts_with($code, Rollup::CANDIDATE_PREFIX)) {
            throw new \InvalidArgumentException(sprintf('Invalid code "%s".', $code));
        }
        return $code;
    }

    /**
     * Throw on an unknown candidate type
     *
     * @param string $type
     * @return void
     */
    private function validType(string $type): void
    {
        if ($type !== self::TYPE_BOT && $type !== self::TYPE_SOURCE) {
            throw new \InvalidArgumentException(sprintf('Invalid candidate type "%s".', $type));
        }
    }

    /**
     * UTC days holding raw hits or overflow counters of a bot code, oldest first
     *
     * @param AdapterInterface $connection
     * @param string $oldCode
     * @return string[]
     */
    private function botDays(AdapterInterface $connection, string $oldCode): array
    {
        $hitDays = $connection->fetchCol(
            'SELECT DISTINCT DATE(created_at) FROM '
            . $connection->quoteIdentifier($this->resource->getTableName(Rollup::HIT_TABLE))
            . ' WHERE bot_code = ?',
            [$oldCode]
        );
        $overflowDays = $connection->fetchCol(
            'SELECT DISTINCT day FROM '
            . $connection->quoteIdentifier($this->resource->getTableName(Rollup::OVERFLOW_TABLE))
            . ' WHERE bot_code = ?',
            [$oldCode]
        );
        return $this->sortDays(array_merge($hitDays, $overflowDays));
    }

    /**
     * UTC days holding raw visits of a source code, oldest first
     *
     * @param AdapterInterface $connection
     * @param string $oldCode
     * @return string[]
     */
    private function sourceDays(AdapterInterface $connection, string $oldCode): array
    {
        return $this->sortDays($connection->fetchCol(
            'SELECT DISTINCT DATE(created_at) FROM '
            . $connection->quoteIdentifier($this->resource->getTableName(Rollup::VISIT_TABLE))
            . ' WHERE source_code = ?',
            [$oldCode]
        ));
    }

    /**
     * Unique, sorted 'Y-m-d' days
     *
     * @param array $days
     * @return string[]
     */
    private function sortDays(array $days): array
    {
        $days = array_values(array_unique(array_map(
            static fn ($day): string => substr((string)$day, 0, 10),
            $days
        )));
        sort($days);
        return $days;
    }

    /**
     * Move overflow counters onto the new code, adding to existing counters; returns the moved rows
     *
     * @param AdapterInterface $connection
     * @param string $oldCode
     * @param string $code
     * @param string $category
     * @return array<int, array<string, mixed>>
     */
    private function mergeOverflow(AdapterInterface $connection, string $oldCode, string $code, string $category): array
    {
        $table = $this->resource->getTableName(Rollup::OVERFLOW_TABLE);
        $rows = $connection->fetchAll(
            'SELECT day, store_id, status_class, is_simulated, hits FROM ' . $connection->quoteIdentifier($table)
            . ' WHERE bot_code = ?',
            [$oldCode]
        );
        if ($rows === []) {
            return [];
        }
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'day' => (string)$row['day'],
                'store_id' => (int)$row['store_id'],
                'bot_code' => $code,
                'category' => $category,
                'status_class' => (int)$row['status_class'],
                'hits' => (int)$row['hits'],
                'is_simulated' => (int)$row['is_simulated'],
            ];
        }
        foreach (array_chunk($data, Rollup::INSERT_CHUNK) as $chunk) {
            $connection->insertOnDuplicate(
                $table,
                $chunk,
                ['category', 'hits' => new \Zend_Db_Expr('hits + VALUES(hits)')]
            );
        }
        $connection->delete($table, ['bot_code = ?' => $oldCode]);
        return $data;
    }

    /**
     * Add moved overflow hits to crawl_daily on slices the rollup will not rebuild
     *
     * A slice is kept by rollupDay() when its raw hits are gone (past hit retention) or incomplete with
     * daily rows already present; without this the merged hits would never be counted. Overflow rows of a
     * candidate (category unknown) are never in crawl_daily, so only moves onto a real code are added.
     *
     * @param AdapterInterface $connection
     * @param array<int, array<string, mixed>> $merged
     * @param string $oldCode
     * @return void
     */
    private function addOverflowToKeptDays(AdapterInterface $connection, array $merged, string $oldCode): void
    {
        $dailyTable = $this->resource->getTableName(Rollup::CRAWL_DAILY_TABLE);
        $complete = [];
        $rows = [];
        foreach ($merged as $row) {
            if ($row['category'] === Category::UNKNOWN || !str_starts_with($oldCode, Rollup::CANDIDATE_PREFIX)) {
                continue;
            }
            $day = $row['day'];
            try {
                $complete[$day] ??= $this->rollup->isRawComplete($day, RollupInterface::KIND_HITS);
                $rawExists = $this->sliceExists($connection, $this->resource->getTableName(Rollup::HIT_TABLE), $row);
                // Same decision as Rollup::rollupDay(): rebuilt when raw rows exist and are complete, or
                // when the slice has no daily rows yet; otherwise the daily rows are kept as they are.
                if ($rawExists && ($complete[$day] || !$this->sliceExists($connection, $dailyTable, $row))) {
                    continue;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[Panth_AiVisibility] overflow of ' . $day . ' not added: ' . $e->getMessage());
                continue;
            }
            $class = (int)$row['status_class'];
            $daily = [
                'day' => $day,
                'store_id' => $row['store_id'],
                'bot_code' => $row['bot_code'],
                'category' => $row['category'],
                'page_type' => 'other',
                'hits' => $row['hits'],
                's2xx' => $class === 2 ? $row['hits'] : 0,
                's3xx' => $class === 3 ? $row['hits'] : 0,
                's4xx' => $class === 4 ? $row['hits'] : 0,
                's5xx' => $class === 5 ? $row['hits'] : 0,
                'total_ms' => 0,
                'max_ms' => 0,
                'is_simulated' => $row['is_simulated'],
            ];
            $rows[] = $daily;
        }
        if ($rows === []) {
            return;
        }
        try {
            foreach (array_chunk($rows, Rollup::INSERT_CHUNK) as $chunk) {
                $connection->insertOnDuplicate($dailyTable, $chunk, [
                    'category',
                    'hits' => new \Zend_Db_Expr('hits + VALUES(hits)'),
                    's2xx' => new \Zend_Db_Expr('s2xx + VALUES(s2xx)'),
                    's3xx' => new \Zend_Db_Expr('s3xx + VALUES(s3xx)'),
                    's4xx' => new \Zend_Db_Expr('s4xx + VALUES(s4xx)'),
                    's5xx' => new \Zend_Db_Expr('s5xx + VALUES(s5xx)'),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] merged overflow not added to daily rows: ' . $e->getMessage());
        }
    }

    /**
     * Whether a raw or daily table has rows for the (day, store, is_simulated) slice of an overflow row
     *
     * @param AdapterInterface $connection
     * @param string $table panth_aiv_hit or panth_aiv_crawl_daily
     * @param array $row
     * @return bool
     */
    private function sliceExists(AdapterInterface $connection, string $table, array $row): bool
    {
        $isRaw = $table === $this->resource->getTableName(Rollup::HIT_TABLE);
        $found = $connection->fetchOne(
            'SELECT 1 FROM ' . $connection->quoteIdentifier($table)
            . ($isRaw ? ' WHERE created_at >= ? AND created_at < ?' : ' WHERE day = ?')
            . ' AND store_id = ? AND is_simulated = ? LIMIT 1',
            $isRaw
                ? [
                    $row['day'] . ' 00:00:00',
                    gmdate('Y-m-d', (int)strtotime($row['day'] . ' 00:00:00 UTC') + 86400) . ' 00:00:00',
                    $row['store_id'],
                    $row['is_simulated'],
                ]
                : [$row['day'], $row['store_id'], $row['is_simulated']]
        );
        return $found !== false && $found !== null && $found !== '';
    }
}
