<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Api\HitWriterInterface;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Model\BotMatch;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Model\TextFilter;

/**
 * Writes raw crawler hits and discovery candidates.
 *
 * Every raw row first bumps the per-day counter "raw_hits" with one atomic upsert. Above
 * bots/raw_daily_cap the hit is added to panth_aiv_overflow_daily instead, so a crawler storm
 * can never grow panth_aiv_hit without bound. Database errors are thrown to the caller.
 */
class HitWriter implements HitWriterInterface
{
    public const TABLE_HIT = 'panth_aiv_hit';
    public const TABLE_OVERFLOW = 'panth_aiv_overflow_daily';
    public const TABLE_COUNTER = 'panth_aiv_counter';
    public const TABLE_CANDIDATE = 'panth_aiv_candidate';

    public const COUNTER_RAW_HITS = 'raw_hits';
    public const CANDIDATE_TYPE_BOT = 'bot';

    /**
     * Overflow code shared by all discovery candidates above the raw cap (keeps the overflow table small)
     */
    public const CANDIDATE_OVERFLOW_CODE = self::CANDIDATE_PREFIX . 'other';

    public const STATUS_NEW = 'new';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_NOT_AI = 'not_ai';
    public const STATUS_IGNORED = 'ignored';

    private const MAX_PATH_LENGTH = 512;
    private const MAX_KEY_LENGTH = 512;
    private const MAX_DURATION_MS = 4294967295;

    /**
     * @param ResourceConnection $resource
     * @param Config $config
     * @param PageTypeResolverInterface $pageTypeResolver
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly PageTypeResolverInterface $pageTypeResolver
    ) {
    }

    /**
     * @inheritdoc
     */
    public function recordHit(
        int $storeId,
        BotMatch $bot,
        string $path,
        bool $hasQuery,
        int $status,
        int $durationMs,
        string $origin = 'php',
        ?int $timestamp = null
    ): void {
        $category = Category::isValid($bot->category) ? $bot->category : Category::OTHER;
        $this->write(
            $storeId,
            $bot->code,
            $category,
            self::cleanPath($path),
            $hasQuery,
            $status,
            $durationMs,
            $origin,
            $timestamp ?? time()
        );
    }

    /**
     * @inheritdoc
     */
    public function recordCandidateHit(
        int $storeId,
        string $userAgent,
        string $path,
        bool $hasQuery,
        int $status,
        int $durationMs,
        string $origin = 'php',
        ?int $timestamp = null
    ): void {
        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            return;
        }
        $timestamp = $timestamp ?? time();
        $path = self::cleanPath($path);
        $origin = $this->origin($origin);
        $candidate = $this->upsertCandidate($userAgent, $path, $origin, $timestamp);
        if ($candidate === null) {
            return;
        }
        [$candidateId, $candidateStatus] = $candidate;
        if ($candidateStatus === self::STATUS_NOT_AI || $candidateStatus === self::STATUS_IGNORED) {
            return;
        }
        $this->write(
            $storeId,
            self::CANDIDATE_PREFIX . $candidateId,
            Category::UNKNOWN,
            $path,
            $hasQuery,
            $status,
            $durationMs,
            $origin,
            $timestamp
        );
    }

    /**
     * Normalised path as stored: starts with "/", no query, max 512 characters, valid UTF-8
     *
     * @param string $path
     * @return string
     */
    public static function cleanPath(string $path): string
    {
        $cut = strcspn($path, '?#');
        $path = (string)preg_replace('/[\x00-\x1F\x7F]/', '', substr($path, 0, $cut));
        $path = TextFilter::encodeInvalidUtf8($path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return mb_substr($path, 0, self::MAX_PATH_LENGTH, 'UTF-8');
    }

    /**
     * Status class used by the overflow table: 2, 3, 4, 5, or 0 when unknown
     *
     * @param int $status
     * @return int
     */
    public static function statusClass(int $status): int
    {
        $class = intdiv($status, 100);
        return $class >= 2 && $class <= 5 && $status <= 599 ? $class : 0;
    }

    /**
     * Raw row below the daily cap, overflow upsert above it
     *
     * @param int $storeId
     * @param string $botCode
     * @param string $category
     * @param string $path
     * @param bool $hasQuery
     * @param int $status
     * @param int $durationMs
     * @param string $origin
     * @param int $timestamp
     * @return void
     */
    private function write(
        int $storeId,
        string $botCode,
        string $category,
        string $path,
        bool $hasQuery,
        int $status,
        int $durationMs,
        string $origin,
        int $timestamp
    ): void {
        $connection = $this->resource->getConnection();
        $origin = $this->origin($origin);
        $day = gmdate('Y-m-d', $timestamp);
        $storeId = max(0, min(65535, $storeId));
        $status = $status >= 100 && $status <= 999 ? $status : 0;
        $simulated = $origin === self::ORIGIN_SIM ? 1 : 0;
        $botCode = substr($botCode, 0, 64);

        if ($this->nextCounterValue($connection, $day) > $this->config->rawDailyCap()) {
            if (str_starts_with($botCode, self::CANDIDATE_PREFIX)) {
                // One overflow row per day for every unknown User-Agent together, never one per User-Agent.
                $botCode = self::CANDIDATE_OVERFLOW_CODE;
            }
            $connection->query(
                'INSERT INTO ' . $this->table($connection, self::TABLE_OVERFLOW)
                . ' (day, store_id, bot_code, category, status_class, hits, is_simulated)'
                . ' VALUES (?, ?, ?, ?, ?, 1, ?)'
                . ' ON DUPLICATE KEY UPDATE hits = hits + 1, category = ?',
                [$day, $storeId, $botCode, $category, self::statusClass($status), $simulated, $category]
            );
            return;
        }
        $connection->insert(
            $this->resource->getTableName(self::TABLE_HIT),
            [
                'created_at' => gmdate('Y-m-d H:i:s', $timestamp),
                'store_id' => $storeId,
                'bot_code' => $botCode,
                'category' => $category,
                'path' => $path,
                'path_hash' => sha1($path),
                'has_query' => $hasQuery ? 1 : 0,
                'status' => $status,
                'duration_ms' => max(0, min(self::MAX_DURATION_MS, $durationMs)),
                'origin' => $origin,
                'is_simulated' => $simulated,
            ]
        );
    }

    /**
     * Atomically increment today's raw hit counter and return the new value
     *
     * @param AdapterInterface $connection
     * @param string $day
     * @return int
     */
    private function nextCounterValue(AdapterInterface $connection, string $day): int
    {
        $connection->query(
            'INSERT INTO ' . $this->table($connection, self::TABLE_COUNTER)
            . ' (counter_key, day, value) VALUES (?, ?, LAST_INSERT_ID(1))'
            . ' ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)',
            [self::COUNTER_RAW_HITS, $day]
        );
        return (int)$connection->fetchOne('SELECT LAST_INSERT_ID()');
    }

    /**
     * Create or update the bot candidate; returns [candidate_id, status] or null
     *
     * @param string $userAgent
     * @param string $path
     * @param string $origin
     * @param int $timestamp
     * @return array{0: int, 1: string}|null
     */
    private function upsertCandidate(string $userAgent, string $path, string $origin, int $timestamp): ?array
    {
        $connection = $this->resource->getConnection();
        $pageType = $this->pageTypeResolver->resolve($path);
        $llms = $pageType === PageTypeResolverInterface::TYPE_LLMS ? 1 : 0;
        $robots = $pageType === PageTypeResolverInterface::TYPE_ROBOTS ? 1 : 0;
        $keyHash = sha1(strtolower($userAgent));
        $keyValue = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $userAgent);
        if (!mb_check_encoding($keyValue, 'UTF-8')) {
            $keyValue = (string)mb_convert_encoding($keyValue, 'UTF-8', 'UTF-8');
        }
        $keyValue = mb_substr($keyValue, 0, self::MAX_KEY_LENGTH, 'UTF-8');
        $now = gmdate('Y-m-d H:i:s', $timestamp);
        $table = $this->table($connection, self::TABLE_CANDIDATE);

        $connection->query(
            'INSERT INTO ' . $table
            . ' (type, key_hash, key_value, first_seen, last_seen, hits, llms_fetches, robots_fetches, status,'
            . ' is_simulated) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE hits = hits + 1, llms_fetches = llms_fetches + ?,'
            . ' robots_fetches = robots_fetches + ?, first_seen = LEAST(first_seen, ?),'
            . ' last_seen = GREATEST(last_seen, ?)',
            [
                self::CANDIDATE_TYPE_BOT, $keyHash, $keyValue, $now, $now, $llms, $robots, self::STATUS_NEW,
                $origin === self::ORIGIN_SIM ? 1 : 0,
                $llms, $robots, $now, $now,
            ]
        );
        $row = $connection->fetchRow(
            'SELECT candidate_id, status FROM ' . $table . ' WHERE type = ? AND key_hash = ?',
            [self::CANDIDATE_TYPE_BOT, $keyHash]
        );
        if (!is_array($row) || (int)($row['candidate_id'] ?? 0) <= 0) {
            return null;
        }
        return [(int)$row['candidate_id'], (string)($row['status'] ?? self::STATUS_NEW)];
    }

    /**
     * Valid origin, "php" for anything else
     *
     * @param string $origin
     * @return string
     */
    private function origin(string $origin): string
    {
        return in_array($origin, [self::ORIGIN_PHP, self::ORIGIN_LOG, self::ORIGIN_SIM], true)
            ? $origin
            : self::ORIGIN_PHP;
    }

    /**
     * Quoted table name
     *
     * @param AdapterInterface $connection
     * @param string $table
     * @return string
     */
    private function table(AdapterInterface $connection, string $table): string
    {
        return $connection->quoteIdentifier($this->resource->getTableName($table));
    }
}
