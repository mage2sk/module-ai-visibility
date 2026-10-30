<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Email;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;

/**
 * Remembers the ISO week the weekly summary was last sent in (one row of panth_aiv_counter).
 *
 * Row: counter_key = KEY, value = ISO week as YYYYWW, day = the day the row was last touched. The hourly cron
 * job touches the row on every run, so the counter retention (which drops rows older than a few days) keeps it.
 * claim() is a single conditional write, so two overlapping runs cannot both send in the same week.
 * The primary key is (counter_key, day), so two first claims on both sides of midnight can leave two rows:
 * every read uses the newest row, and touch() / claim() first delete the older extra rows.
 */
class WeeklySendMarker
{
    public const TABLE = 'panth_aiv_counter';
    public const KEY = 'email_weekly_sent';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * ISO week (YYYYWW) of the last send, or null when never sent
     *
     * @return int|null
     */
    public function lastWeek(): ?int
    {
        $connection = $this->connection();
        $value = $connection->fetchOne(
            $connection->select()
                ->from($this->table(), ['value'])
                ->where('counter_key = ?', self::KEY)
                ->order(['day DESC', 'value DESC'])
                ->limit(1)
        );
        return $value === false || $value === null || (int)$value <= 0 ? null : (int)$value;
    }

    /**
     * Move the row to today so the counter retention keeps it
     *
     * @param string $today 'Y-m-d' (UTC)
     * @return void
     */
    public function touch(string $today): void
    {
        $this->dropExtraRows();
        $this->connection()->update(
            $this->table(),
            ['day' => $today],
            ['counter_key = ?' => self::KEY, 'day <> ?' => $today]
        );
    }

    /**
     * Mark the week as sent unless it already is
     *
     * @param int $week ISO week YYYYWW
     * @param string $today 'Y-m-d' (UTC)
     * @return int|null The previous week value (0 = none) when this call claimed the week, null when it was taken
     */
    public function claim(int $week, string $today): ?int
    {
        $previous = (int)$this->lastWeek();
        if ($previous === $week) {
            return null;
        }
        $this->dropExtraRows();
        $connection = $this->connection();
        $exists = (bool)$connection->fetchOne(
            $connection->select()->from($this->table(), ['n' => new Expression('COUNT(*)')])
                ->where('counter_key = ?', self::KEY)
        );
        if ($exists) {
            $changed = $connection->update(
                $this->table(),
                ['value' => $week, 'day' => $today],
                ['counter_key = ?' => self::KEY, 'value <> ?' => $week]
            );
        } else {
            $changed = $connection->query(
                'INSERT IGNORE INTO ' . $connection->quoteIdentifier($this->table())
                . ' (counter_key, day, value) VALUES (?, ?, ?)',
                [self::KEY, $today, $week]
            )->rowCount();
        }
        return (int)$changed > 0 ? $previous : null;
    }

    /**
     * Undo a claim after the send failed, so the next run tries again
     *
     * @param int $week The claimed week
     * @param int $previous The value claim() returned
     * @return void
     */
    public function release(int $week, int $previous): void
    {
        $this->connection()->update(
            $this->table(),
            ['value' => max(0, $previous)],
            ['counter_key = ?' => self::KEY, 'value = ?' => $week]
        );
    }

    /**
     * Keep only the newest marker row (older rows of the key are left over from a claim race)
     *
     * @return void
     */
    private function dropExtraRows(): void
    {
        $connection = $this->connection();
        $newest = $connection->fetchOne(
            $connection->select()
                ->from($this->table(), ['day'])
                ->where('counter_key = ?', self::KEY)
                ->order(['day DESC', 'value DESC'])
                ->limit(1)
        );
        if ($newest === false || $newest === null || $newest === '') {
            return;
        }
        $connection->delete(
            $this->table(),
            ['counter_key = ?' => self::KEY, 'day <> ?' => (string)$newest]
        );
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

    /**
     * Prefixed table name
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
