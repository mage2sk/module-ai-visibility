<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation\Rule\Support;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\DataScope;

/**
 * Shared plumbing of the recommendation rules: connection, quoted table names, UTC day windows,
 * bound placeholder lists, store filters and the raw impact score.
 *
 * Rules build plain SQL strings with bound parameters (one set-based query per data set, always
 * bounded by a day window and a LIMIT or an IN list).
 */
class RuleSupport
{
    /**
     * Default look-back window of the rules (UTC days, today included)
     */
    public const WINDOW_DAYS = 30;

    /**
     * Examples listed in the evidence
     */
    public const TOP_EXAMPLES = 5;

    /**
     * Most subject refs one finding carries (what an applied fix is verified on)
     */
    public const MAX_SUBJECTS = 200;

    /**
     * Most rows a rule scans from a summary table before deciding
     */
    public const SCAN_LIMIT = 500;

    /**
     * Longest example string kept in the evidence (user agents, paths)
     */
    public const MAX_EXAMPLE_LENGTH = 200;

    /**
     * @param ResourceConnection $resource
     * @param Config $config
     * @param DataScope $scope
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly DataScope $scope
    ) {
    }

    /**
     * SQL condition on an is_simulated column: real rows only, unless a CLI preview includes simulated rows
     *
     * @param string $column e.g. "p.is_simulated"
     * @return string
     */
    public function simulatedCondition(string $column): string
    {
        return $this->scope->condition($column);
    }

    /**
     * Whether simulated rows are read as well (for services that take an $includeSim flag)
     *
     * @return bool
     */
    public function includesSimulated(): bool
    {
        return $this->scope->isIncludingSimulated();
    }

    /**
     * Default connection
     *
     * @return AdapterInterface
     */
    public function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * Quoted, prefixed table name for SQL strings
     *
     * @param string $name
     * @return string
     */
    public function table(string $name): string
    {
        return $this->connection()->quoteIdentifier($this->resource->getTableName($name));
    }

    /**
     * Prefixed table name (unquoted, for schema checks)
     *
     * @param string $name
     * @return string
     */
    public function tableName(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    /**
     * Findings below this many events are ignored (recommendations/min_volume)
     *
     * @return int
     */
    public function minVolume(): int
    {
        return max(1, $this->config->recommendationsMinVolume());
    }

    /**
     * Module configuration
     *
     * @return Config
     */
    public function config(): Config
    {
        return $this->config;
    }

    /**
     * First UTC day of a window of $days days that ends on $today (inclusive), 'Y-m-d'
     *
     * @param \DateTimeImmutable $today
     * @param int $days
     * @return string
     */
    public static function fromDay(\DateTimeImmutable $today, int $days): string
    {
        return self::utcDay($today)->modify('-' . max(0, $days - 1) . ' days')->format('Y-m-d');
    }

    /**
     * The evaluation day itself, 'Y-m-d' (UTC)
     *
     * @param \DateTimeImmutable $today
     * @return string
     */
    public static function toDay(\DateTimeImmutable $today): string
    {
        return self::utcDay($today)->format('Y-m-d');
    }

    /**
     * Start of a UTC day as a datetime string
     *
     * @param string $day 'Y-m-d'
     * @return string
     */
    public static function startOf(string $day): string
    {
        return $day . ' 00:00:00';
    }

    /**
     * Start of the UTC day after $day (exclusive upper bound for created_at)
     *
     * @param string $day 'Y-m-d'
     * @return string
     */
    public static function endExclusive(string $day): string
    {
        return (new \DateTimeImmutable($day . ' 00:00:00', new \DateTimeZone('UTC')))
            ->modify('+1 day')
            ->format('Y-m-d H:i:s');
    }

    /**
     * Raw impact score 0-100: rule weight times the order of magnitude of the affected volume
     *
     * 10 events with weight 3 score 31, 100 events 60, 1000 events 90 (the engine may re-normalise).
     *
     * @param float $weight
     * @param int $volume
     * @return int
     */
    public static function impactScore(float $weight, int $volume): int
    {
        if ($volume <= 0 || $weight <= 0) {
            return 0;
        }
        return (int)max(0, min(100, round($weight * 10 * log10(1 + $volume))));
    }

    /**
     * "?, ?, ?" for an IN list of $count values (at least one placeholder)
     *
     * @param int $count
     * @return string
     */
    public static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, max(1, $count), '?'));
    }

    /**
     * " AND <column> = ?" with the store bound, or '' for store 0 (all store views)
     *
     * @param string $column
     * @param int $storeId
     * @param array $bind Bind list the store ID is appended to
     * @return string
     */
    public static function storeSql(string $column, int $storeId, array &$bind): string
    {
        if ($storeId <= 0) {
            return '';
        }
        $bind[] = $storeId;
        return ' AND ' . $column . ' = ?';
    }

    /**
     * Cut a string for the evidence
     *
     * @param string $value
     * @return string
     */
    public static function clip(string $value): string
    {
        return mb_substr($value, 0, self::MAX_EXAMPLE_LENGTH);
    }

    /**
     * Midnight UTC of a date
     *
     * @param \DateTimeImmutable $day
     * @return \DateTimeImmutable
     */
    private static function utcDay(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
    }
}
