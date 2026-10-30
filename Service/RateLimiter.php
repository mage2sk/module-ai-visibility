<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Per-IP and global per-minute limits for the storefront collector.
 *
 * Counters live in panth_aiv_rate_limit and are incremented with one atomic upsert. The IP
 * is never stored: the bucket key holds sha1(salt . ip), the salt derived from the deployment
 * crypt key. When the table cannot be used the application cache is the fallback.
 */
class RateLimiter
{
    public const TABLE = 'panth_aiv_rate_limit';

    /**
     * Seconds a counter row is kept (purged by the retention cron)
     */
    private const TTL = 120;

    /**
     * Global limit per minute = per-IP limit x this factor
     */
    private const GLOBAL_MULTIPLIER = 50;

    private const CACHE_PREFIX = 'panth_aiv_rl_';

    /**
     * @var string|null
     */
    private ?string $salt = null;

    /**
     * @param ResourceConnection $resource
     * @param CacheInterface $cache
     * @param DeploymentConfig $deploymentConfig
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Count one request for the IP; false when the IP or the global limit is exceeded
     *
     * @param string $ip
     * @param int $perMinuteLimit
     * @return bool
     */
    public function allow(string $ip, int $perMinuteLimit): bool
    {
        if ($perMinuteLimit <= 0) {
            return false;
        }
        $now = (int)$this->dateTime->gmtTimestamp();
        $minute = intdiv($now, 60);
        // Per IP first: requests one client already exceeded must not use up the budget of everybody else.
        if (!$this->hit('ip:' . $this->ipHash($ip) . ':' . $minute, $perMinuteLimit, $now)) {
            return false;
        }
        return $this->hit('g:' . $minute, $perMinuteLimit * self::GLOBAL_MULTIPLIER, $now);
    }

    /**
     * Salted SHA-1 of an IP address (the only form in which an IP is stored)
     *
     * @param string $ip
     * @return string
     */
    public function ipHash(string $ip): string
    {
        return sha1($this->salt() . '|' . $ip);
    }

    /**
     * Increment a bucket and compare it with its limit
     *
     * @param string $bucket
     * @param int $limit
     * @param int $now
     * @return bool
     */
    private function hit(string $bucket, int $limit, int $now): bool
    {
        $hits = $this->atomicHit($bucket, $now);
        if ($hits !== null) {
            return $hits <= $limit;
        }
        $key = self::CACHE_PREFIX . sha1($bucket);
        $current = (int)$this->cache->load($key);
        if ($current >= $limit) {
            return false;
        }
        $this->cache->save((string)($current + 1), $key, [], self::TTL);
        return true;
    }

    /**
     * Atomic upsert of a counter row; null when the table cannot be used
     *
     * @param string $bucket
     * @param int $now
     * @return int|null
     */
    private function atomicHit(string $bucket, int $now): ?int
    {
        try {
            $connection = $this->resource->getConnection();
            $connection->query(
                'INSERT INTO ' . $connection->quoteIdentifier($this->resource->getTableName(self::TABLE))
                . ' (bucket, hits, expires_at) VALUES (?, LAST_INSERT_ID(1), ?)'
                . ' ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)',
                [$bucket, $now + self::TTL]
            );
            $hits = (int)$connection->fetchOne('SELECT LAST_INSERT_ID()');
            return $hits > 0 ? $hits : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Salt derived from the newest deployment crypt key
     *
     * @return string
     */
    private function salt(): string
    {
        if ($this->salt === null) {
            $raw = (string)$this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY);
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
            $this->salt = hash('sha256', 'panth_aiv_rl|' . ($lines !== [] ? (string)end($lines) : ''));
        }
        return $this->salt;
    }
}
