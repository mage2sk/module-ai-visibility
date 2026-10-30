<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Service\BotClassifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RuleProviderTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private array $cacheStore = [];

    /**
     * @var array<int, array>
     */
    private array $cacheSaves = [];

    /**
     * @var int
     */
    private int $queries = 0;

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

    private function rows(): array
    {
        return [
            [
                'rule_id' => '1', 'type' => 'bot', 'code' => 'example_bot', 'name' => 'ExampleBot',
                'match_on' => 'ua', 'pattern' => 'ExampleBot/', 'category' => 'search', 'company' => 'Example Inc',
                'candidate_id' => '7', 'created_at' => '2026-01-02 03:04:05',
            ],
            [
                'rule_id' => '2', 'type' => 'source', 'code' => 'shop_chat', 'name' => 'Shop Chat',
                'match_on' => 'host', 'pattern' => 'chat.shop.test', 'category' => null, 'company' => '',
                'candidate_id' => null, 'created_at' => '2026-01-03 00:00:00',
            ],
            [
                'rule_id' => '3', 'type' => 'source', 'code' => 'shop_utm', 'name' => '',
                'match_on' => 'utm', 'pattern' => 'shop-ai', 'category' => '', 'company' => null,
                'candidate_id' => '0', 'created_at' => '2026-01-04 00:00:00',
            ],
            // Skipped: unknown type, empty pattern, source with an unknown match_on.
            ['rule_id' => '4', 'type' => 'other', 'code' => 'x', 'name' => 'X', 'match_on' => 'ua', 'pattern' => 'abc'],
            ['rule_id' => '5', 'type' => 'bot', 'code' => 'y', 'name' => 'Y', 'match_on' => 'ua', 'pattern' => ' '],
            ['rule_id' => '6', 'type' => 'source', 'code' => 'z', 'name' => 'Z', 'match_on' => 'ua', 'pattern' => 'z'],
        ];
    }

    private function provider(?\Throwable $dbError = null): RuleProvider
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturnCallback(function () use ($dbError): array {
            $this->queries++;
            if ($dbError !== null) {
                throw $dbError;
            }
            return $this->rows();
        });

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $id) => $this->cacheStore[$id] ?? false);
        $cache->method('save')->willReturnCallback(
            function (string $data, string $id, array $tags = [], $lifetime = null): bool {
                $this->cacheStore[$id] = $data;
                $this->cacheSaves[] = [$id, $tags, $lifetime];
                return true;
            }
        );

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });

        return new RuleProvider($resource, $cache, new Json(), $logger);
    }

    public function testRulesAreLoadedNormalisedAndSplitByType(): void
    {
        $provider = $this->provider();

        $this->assertSame(
            [
                [
                    'rule_id' => 1, 'type' => 'bot', 'code' => 'example_bot', 'name' => 'ExampleBot',
                    'match_on' => 'ua', 'pattern' => 'ExampleBot/', 'category' => 'search', 'company' => 'Example Inc',
                    'candidate_id' => 7, 'created_at' => '2026-01-02 03:04:05',
                ],
            ],
            $provider->botRules()
        );
        $this->assertSame(
            [
                [
                    'rule_id' => 2, 'type' => 'source', 'code' => 'shop_chat', 'name' => 'Shop Chat',
                    'match_on' => 'host', 'pattern' => 'chat.shop.test', 'category' => null, 'company' => null,
                    'candidate_id' => null, 'created_at' => '2026-01-03 00:00:00',
                ],
                [
                    'rule_id' => 3, 'type' => 'source', 'code' => 'shop_utm', 'name' => 'shop_utm',
                    'match_on' => 'utm', 'pattern' => 'shop-ai', 'category' => null, 'company' => null,
                    'candidate_id' => null, 'created_at' => '2026-01-04 00:00:00',
                ],
            ],
            $provider->sourceRules()
        );
        $this->assertSame(1, $this->queries, 'One query for both rule types');
    }

    public function testRulesAreCachedWithTagAndReusedByANewInstance(): void
    {
        $this->provider()->botRules();

        $this->assertCount(1, $this->cacheSaves);
        [$id, $tags, $lifetime] = $this->cacheSaves[0];
        $this->assertSame(RuleProvider::CACHE_KEY, $id);
        $this->assertSame([RuleProvider::CACHE_TAG, 'CONFIG'], $tags);
        $this->assertSame(RuleProvider::CACHE_LIFETIME, $lifetime);
        $this->assertSame('PANTH_AIV_RULES', RuleProvider::CACHE_TAG);

        $second = $this->provider();
        $this->assertCount(1, $second->botRules());
        $this->assertCount(2, $second->sourceRules());
        $this->assertSame(1, $this->queries, 'Second instance reads the application cache');
    }

    public function testInMemoryCopyUntilReset(): void
    {
        $provider = $this->provider();
        $provider->botRules();
        $this->cacheStore = [];

        $provider->sourceRules();
        $this->assertSame(1, $this->queries);

        $provider->reset();
        $provider->botRules();
        $this->assertSame(2, $this->queries, 'reset() forces a reload when the cache was cleaned');
    }

    public function testEmptyResultIsCachedToo(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects($this->once())->method('save')
            ->with('{"bot":[],"source":[]}', RuleProvider::CACHE_KEY, [RuleProvider::CACHE_TAG, 'CONFIG']);

        $provider = new RuleProvider($resource, $cache, new Json(), $this->createStub(LoggerInterface::class));
        $this->assertSame([], $provider->botRules());
        $this->assertSame([], $provider->sourceRules());
    }

    public function testCachedEmptyRuleSetMeansNoQueryOnLaterRequests(): void
    {
        $queries = 0;
        $store = [];
        $make = function () use (&$queries, &$store): RuleProvider {
            $select = $this->createStub(Select::class);
            $select->method('from')->willReturnSelf();
            $select->method('order')->willReturnSelf();
            $connection = $this->createStub(AdapterInterface::class);
            $connection->method('select')->willReturn($select);
            $connection->method('fetchAll')->willReturnCallback(function () use (&$queries): array {
                $queries++;
                return [];
            });
            $resource = $this->createStub(ResourceConnection::class);
            $resource->method('getConnection')->willReturn($connection);
            $resource->method('getTableName')->willReturnArgument(0);
            $cache = $this->createStub(CacheInterface::class);
            $cache->method('load')->willReturnCallback(static fn (string $id) => $store[$id] ?? false);
            $cache->method('save')->willReturnCallback(
                static function (string $data, string $id) use (&$store): bool {
                    $store[$id] = $data;
                    return true;
                }
            );
            return new RuleProvider($resource, $cache, new Json(), $this->createStub(LoggerInterface::class));
        };

        // First request after a cache clean: one query, the empty set is stored.
        $first = new BotClassifier($this->createStub(Config::class), $make());
        $this->assertNull($first->classify('Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36'));
        $this->assertSame(1, $queries);

        // Every later human request (new objects, same cache): no database access at all.
        for ($i = 0; $i < 5; $i++) {
            $classifier = new BotClassifier($this->createStub(Config::class), $make());
            $this->assertNull($classifier->classify('Mozilla/5.0 (Macintosh) Safari/605.1.15 #' . $i));
            $this->assertFalse($classifier->isDiscoveryCandidate('Mozilla/5.0 (Macintosh) Safari/605.1.15'));
        }
        $this->assertSame(1, $queries);
        $this->assertSame('{"bot":[],"source":[]}', $store[RuleProvider::CACHE_KEY]);
    }

    public function testCorruptCacheEntryFallsBackToTheDatabase(): void
    {
        $this->cacheStore[RuleProvider::CACHE_KEY] = '{"bot":"broken"';
        $this->assertCount(1, $this->provider()->botRules());
        $this->assertSame(1, $this->queries);
    }

    public function testDatabaseErrorReturnsNoRulesAndLogsAWarning(): void
    {
        $provider = $this->provider(new \RuntimeException('Table does not exist'));

        $this->assertSame([], $provider->botRules());
        $this->assertSame([], $provider->sourceRules());
        $this->assertSame(1, $this->queries, 'The failure is remembered for the request');
        $this->assertSame([], $this->cacheSaves, 'A failure is not cached');
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('Table does not exist', $this->warnings[0]);
    }
}
