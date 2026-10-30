<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AiVisibility\Service\RateLimiter;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private const NOW = 1790000040;

    /**
     * @var array<string, int>
     */
    private array $rows = [];

    /**
     * @var int
     */
    private int $lastId = 0;

    /**
     * @var array<int, array>
     */
    private array $queries = [];

    /**
     * @var array<string, string>
     */
    private array $cacheStore = [];

    private function limiter(bool $dbFails = false, string $cryptKey = 'k1'): RateLimiter
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(fn ($v) => '`' . $v . '`');
        $connection->method('query')->willReturnCallback(function (string $sql, array $bind) use ($dbFails) {
            if ($dbFails) {
                throw new \RuntimeException('table missing');
            }
            $this->queries[] = [$sql, $bind];
            $this->rows[$bind[0]] = ($this->rows[$bind[0]] ?? 0) + 1;
            $this->lastId = $this->rows[$bind[0]];
            return $this->createStub(\Zend_Db_Statement_Interface::class);
        });
        $connection->method('fetchOne')->willReturnCallback(fn () => (string)$this->lastId);
        $connection->method('delete')->willReturn(3);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn ($key) => $this->cacheStore[$key] ?? false);
        $cache->method('save')->willReturnCallback(function ($value, $key) {
            $this->cacheStore[$key] = $value;
            return true;
        });
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturn($cryptKey);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);
        return new RateLimiter($resource, $cache, $deployment, $dateTime);
    }

    public function testAllowsUpToTheLimitPerIp(): void
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($limiter->allow('192.0.2.10', 3));
        }
        $this->assertFalse($limiter->allow('192.0.2.10', 3));
        $this->assertTrue($limiter->allow('192.0.2.11', 3));
    }

    public function testIpIsStoredOnlyAsSaltedHash(): void
    {
        $limiter = $this->limiter();
        $limiter->allow('192.0.2.10', 5);
        $buckets = array_keys($this->rows);
        $minute = intdiv(self::NOW, 60);
        // Per-IP bucket first, so requests an IP already exceeded never count towards the global bucket.
        $this->assertSame(['ip:' . $limiter->ipHash('192.0.2.10') . ':' . $minute, 'g:' . $minute], $buckets);
        foreach ($this->queries as [$sql, $bind]) {
            $this->assertStringNotContainsString('192.0.2.10', implode('|', $bind));
            $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
            $this->assertSame(self::NOW + 120, $bind[1]);
            $this->assertLessThanOrEqual(96, strlen($bind[0]));
        }
        $this->assertNotSame(sha1('192.0.2.10'), $limiter->ipHash('192.0.2.10'));
        $this->assertNotSame($limiter->ipHash('192.0.2.10'), $this->limiter(false, 'k2')->ipHash('192.0.2.10'));
    }

    public function testGlobalLimit(): void
    {
        $limiter = $this->limiter();
        $this->rows['g:' . intdiv(self::NOW, 60)] = 50;
        $this->assertFalse($limiter->allow('192.0.2.99', 1));
        $this->assertSame(51, $this->rows['g:' . intdiv(self::NOW, 60)]);
    }

    public function testZeroLimitDenies(): void
    {
        $this->assertFalse($this->limiter()->allow('192.0.2.1', 0));
    }

    public function testCacheFallbackWhenTableUnavailable(): void
    {
        $limiter = $this->limiter(true);
        $this->assertTrue($limiter->allow('192.0.2.10', 2));
        $this->assertTrue($limiter->allow('192.0.2.10', 2));
        $this->assertFalse($limiter->allow('192.0.2.10', 2));
        foreach (array_keys($this->cacheStore) as $key) {
            $this->assertStringNotContainsString('192.0.2.10', $key);
        }
    }
}
