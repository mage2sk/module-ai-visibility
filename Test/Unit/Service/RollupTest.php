<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Rollup;
use PHPUnit\Framework\TestCase;

class RollupTest extends TestCase
{
    /**
     * @var array<string, array<int, array<string, mixed>>> Rows served per query kind
     */
    private array $data = [];

    /**
     * @var array<int, array{0: string, 1: string, 2: array}> Recorded calls [method, sql/table, bind/data]
     */
    private array $calls = [];

    /**
     * @var array<string, mixed> fetchOne result per raw table for "SELECT 1" probes
     */
    private array $rawExists = [];

    private ?\Throwable $insertFailure = null;

    /**
     * @var array<int, string> URL store code per store id ('' = no code in URLs)
     */
    private array $storeCodes = [];

    protected function setUp(): void
    {
        $this->data = [
            'paths' => [],
            'crawl' => [],
            'overflow' => [],
            'path' => [],
            'visit' => [],
            'rewrite' => [],
        ];
        $this->calls = [];
        $this->rawExists = [];
        $this->insertFailure = null;
        $this->storeCodes = [];
    }

    /**
     * @dataProvider invalidDays
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDays')]
    public function testInvalidDayIsRejected(string $day): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rollup()->rollupDay($day);
    }

    public static function invalidDays(): array
    {
        return [
            'not a date' => ['abc'],
            'short year' => ['26-01-01'],
            'no padding' => ['2026-1-01'],
            'impossible day' => ['2026-02-30'],
            'with time' => ['2026-01-01 00:00:00'],
        ];
    }

    public function testCutoffDayUsesWholeUtcDays(): void
    {
        $now = gmmktime(0, 30, 0, 10, 30, 2026);
        $this->assertSame('2026-10-16', Rollup::cutoffDay(14, $now));
        $this->assertSame('2026-10-30', Rollup::cutoffDay(0, $now));
    }

    public function testCrawlRowsAreFoldedByPageTypeAndIncludeOverflow(): void
    {
        $day = gmdate('Y-m-d');
        $this->data['paths'] = [
            ['store_id' => '1', 'path_hash' => 'h-home', 'path' => '/'],
            ['store_id' => '1', 'path_hash' => 'h-prod', 'path' => '/bag.html'],
            ['store_id' => '1', 'path_hash' => 'h-miss', 'path' => '/missing.html'],
            ['store_id' => '1', 'path_hash' => 'h-cat', 'path' => '/Gear.html'],
        ];
        $this->data['rewrite'] = [
            ['request_path' => 'bag.html', 'entity_type' => 'product', 'entity_id' => '14', 'redirect_type' => '0'],
            ['request_path' => 'gear.html', 'entity_type' => 'category', 'entity_id' => '3', 'redirect_type' => '301'],
        ];
        $this->data['crawl'] = [
            $this->crawlGroup('1', '0', 'gptbot', 'training', 'h-prod', 3, [2, 0, 1, 0], 600, 300),
            $this->crawlGroup('1', '0', 'gptbot', 'training', 'h-miss', 1, [0, 0, 0, 1], 90, 90),
            $this->crawlGroup('1', '0', 'gptbot', 'training', 'h-cat', 2, [0, 2, 0, 0], 20, 15),
            $this->crawlGroup('1', '0', 'gptbot', 'training', 'h-home', 1, [1, 0, 0, 0], 5, 5),
            $this->crawlGroup('1', '1', 'gptbot', 'training', 'h-prod', 2, [2, 0, 0, 0], 10, 6),
        ];
        $this->data['overflow'] = [
            ['store_id' => '1', 'is_simulated' => '0', 'bot_code' => 'gptbot', 'category' => 'training',
                'status_class' => '4', 'hits' => '10'],
            ['store_id' => '1', 'is_simulated' => '0', 'bot_code' => 'gptbot', 'category' => 'training',
                'status_class' => '0', 'hits' => '2'],
        ];
        $this->data['visit'] = [
            ['store_id' => '1', 'is_simulated' => '0', 'source_code' => 'chatgpt', 'path_hash' => 'h-prod',
                'visits' => '2'],
            ['store_id' => '1', 'is_simulated' => '0', 'source_code' => 'chatgpt', 'path_hash' => 'h-unknown',
                'visits' => '1'],
            ['store_id' => '1', 'is_simulated' => '0', 'source_code' => 'chatgpt', 'path_hash' => 'h-miss',
                'visits' => '4'],
        ];

        $this->rollup()->rollupDay($day);

        $crawl = $this->indexed($this->inserted('panth_aiv_crawl_daily'), ['is_simulated', 'bot_code', 'page_type']);
        $this->assertSame(['0|gptbot|category', '0|gptbot|home', '0|gptbot|other', '0|gptbot|product',
            '1|gptbot|product'], array_keys($crawl));
        $this->assertSame(
            [3, 2, 0, 1, 0, 600, 300],
            $this->counts($crawl['0|gptbot|product'])
        );
        $this->assertSame([2, 0, 2, 0, 0, 20, 15], $this->counts($crawl['0|gptbot|category']));
        // unresolved path (1 hit, 5xx) + overflow 4xx (10) + overflow unknown status (2)
        $this->assertSame([13, 0, 0, 10, 1, 90, 90], $this->counts($crawl['0|gptbot|other']));
        $this->assertSame([2, 2, 0, 0, 0, 10, 6], $this->counts($crawl['1|gptbot|product']));
        $this->assertSame($day, $crawl['0|gptbot|other']['day']);
        $this->assertSame('training', $crawl['0|gptbot|other']['category']);

        $visits = $this->indexed($this->inserted('panth_aiv_visit_daily'), ['source_code', 'page_type']);
        $this->assertSame(['chatgpt|other' => 5, 'chatgpt|product' => 2], array_map(
            static fn (array $row): int => $row['visits'],
            $visits
        ));
    }

    public function testCategoryConflictWithinADayUsesTheMaximumLikeSql(): void
    {
        $this->data['paths'] = [
            ['store_id' => '1', 'path_hash' => 'a', 'path' => '/a.html'],
            ['store_id' => '1', 'path_hash' => 'b', 'path' => '/b.html'],
        ];
        $this->data['crawl'] = [
            $this->crawlGroup('1', '0', 'disc_bot', 'live', 'a', 1, [1, 0, 0, 0], 1, 1),
            $this->crawlGroup('1', '0', 'disc_bot', 'search', 'b', 1, [1, 0, 0, 0], 1, 1),
        ];
        $this->rollup()->rollupDay(gmdate('Y-m-d'));

        $rows = $this->inserted('panth_aiv_crawl_daily');
        $this->assertCount(1, $rows);
        $this->assertSame('search', $rows[0]['category']);
        $this->assertSame(2, $rows[0]['hits']);
    }

    public function testPathDailyKeepsTheTop500PerGroupAndResolvesEntities(): void
    {
        $this->data['paths'] = [['store_id' => '1', 'path_hash' => 'p0', 'path' => '/bag.html']];
        $this->data['rewrite'] = [
            ['request_path' => 'bag.html', 'entity_type' => 'product', 'entity_id' => '14', 'redirect_type' => '0'],
        ];
        for ($i = 0; $i < 502; $i++) {
            $this->data['path'][] = ['store_id' => '1', 'is_simulated' => '0', 'category' => 'search',
                'path_hash' => 'p' . $i, 'path' => $i === 0 ? '/bag.html' : '/p' . $i, 'hits' => (string)(600 - $i)];
        }
        for ($i = 0; $i < 3; $i++) {
            $this->data['path'][] = ['store_id' => '1', 'is_simulated' => '1', 'category' => 'search',
                'path_hash' => 's' . $i, 'path' => '/s' . $i, 'hits' => '1'];
        }

        $this->rollup()->rollupDay(gmdate('Y-m-d'));

        $rows = $this->inserted('panth_aiv_path_daily');
        $this->assertCount(503, $rows);
        $real = array_values(array_filter($rows, static fn (array $row): bool => $row['is_simulated'] === 0));
        $this->assertCount(Rollup::PATHS_PER_GROUP, $real);
        $this->assertSame('/p499', $real[499]['path']);
        $this->assertSame(['product', 14], [$real[0]['entity_type'], $real[0]['entity_id']]);
        $this->assertNull($real[1]['entity_type']);
        $this->assertNull($real[1]['entity_id']);
        $inserts = array_filter(
            $this->calls,
            static fn (array $call): bool => $call[0] === 'insertMultiple' && $call[1] === 'panth_aiv_path_daily'
        );
        $this->assertCount(2, $inserts, 'rows are inserted in chunks of ' . Rollup::INSERT_CHUNK);
    }

    public function testUrlRewriteLookupsAreBatchedAndBoundAsStrings(): void
    {
        for ($i = 0; $i < 1201; $i++) {
            $this->data['paths'][] = ['store_id' => '1', 'path_hash' => 'h' . $i, 'path' => '/' . $i];
        }
        $this->data['paths'][] = ['store_id' => '2', 'path_hash' => 'x', 'path' => '/caf%C3%A9.html'];

        $this->rollup()->rollupDay(gmdate('Y-m-d'));

        $lookups = array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call[0] === 'fetchAll' && str_contains($call[1], 'url_rewrite')
        ));
        $this->assertCount(4, $lookups);
        $entityTypes = ['product', 'category', 'cms-page'];
        foreach ($lookups as $lookup) {
            $values = array_slice($lookup[2], 1 + count($entityTypes));
            $this->assertLessThanOrEqual(Rollup::LOOKUP_CHUNK, count($values));
            $this->assertSame($entityTypes, array_slice($lookup[2], 1, 3));
            foreach ($values as $value) {
                $this->assertIsString($value);
            }
            $this->assertSame(substr_count($lookup[1], '?'), count($lookup[2]));
        }
        $this->assertSame(2, $lookups[3][2][0]);
        $this->assertSame(['caf%c3%a9.html', 'café.html'], array_slice($lookups[3][2], 4));
    }

    /**
     * Path, store code, expected path after stripping
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function storeCodePaths(): array
    {
        return [
            'no code configured' => ['/en/bag.html', '', '/en/bag.html'],
            'page below the code' => ['/en/bag.html', 'en', '/bag.html'],
            'code with slash is home' => ['/en/', 'en', '/'],
            'bare code is home' => ['/en', 'en', '/'],
            'case-insensitive code' => ['/EN/gear/bag.html', 'en', '/gear/bag.html'],
            'longer segment kept' => ['/english.html', 'en', '/english.html'],
            'other store code kept' => ['/fr/bag.html', 'en', '/fr/bag.html'],
            'root kept' => ['/', 'en', '/'],
            'code later in path kept' => ['/gear/en/bag.html', 'en', '/gear/en/bag.html'],
        ];
    }

    /**
     * @dataProvider storeCodePaths
     * @param string $path
     * @param string $code
     * @param string $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storeCodePaths')]
    public function testStripStoreCode(string $path, string $code, string $expected): void
    {
        $this->assertSame($expected, Rollup::stripStoreCode($path, $code));
    }

    public function testStoreCodeUrlsResolveThroughUrlRewriteAndHome(): void
    {
        $this->storeCodes = [2 => 'en'];
        $this->data['paths'] = [
            ['store_id' => '2', 'path_hash' => 'h-prod', 'path' => '/en/bag.html'],
            ['store_id' => '2', 'path_hash' => 'h-home', 'path' => '/en/'],
            ['store_id' => '2', 'path_hash' => 'h-bare', 'path' => '/en'],
            ['store_id' => '1', 'path_hash' => 'h-other', 'path' => '/en/bag.html'],
        ];
        $this->data['rewrite'] = [
            ['request_path' => 'bag.html', 'entity_type' => 'product', 'entity_id' => '14', 'redirect_type' => '0'],
        ];
        $this->data['crawl'] = [
            $this->crawlGroup('2', '0', 'gptbot', 'training', 'h-prod', 2, [2, 0, 0, 0], 10, 5),
            $this->crawlGroup('2', '0', 'gptbot', 'training', 'h-home', 1, [1, 0, 0, 0], 5, 5),
            $this->crawlGroup('2', '0', 'gptbot', 'training', 'h-bare', 1, [1, 0, 0, 0], 5, 5),
            $this->crawlGroup('1', '0', 'gptbot', 'training', 'h-other', 1, [1, 0, 0, 0], 5, 5),
        ];
        $this->data['path'] = [
            ['store_id' => '2', 'is_simulated' => '0', 'category' => 'training', 'path_hash' => 'h-prod',
                'path' => '/en/bag.html', 'hits' => '2'],
        ];

        $this->rollup()->rollupDay(gmdate('Y-m-d'));

        $crawl = $this->indexed($this->inserted('panth_aiv_crawl_daily'), ['store_id', 'page_type']);
        $this->assertSame(['1|other', '2|home', '2|product'], array_keys($crawl));
        $this->assertSame(2, $crawl['2|home']['hits']);
        $paths = $this->inserted('panth_aiv_path_daily');
        $this->assertSame('/en/bag.html', $paths[0]['path'], 'the stored path keeps the store code');
        $this->assertSame(['product', 14], [$paths[0]['entity_type'], $paths[0]['entity_id']]);
        $lookups = array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call[0] === 'fetchAll' && str_contains($call[1], 'url_rewrite')
        ));
        $bound = [];
        foreach ($lookups as $lookup) {
            $bound[$lookup[2][0]] = array_slice($lookup[2], 4);
        }
        $this->assertSame(['bag.html'], $bound[2]);
        $this->assertSame(['en/bag.html'], $bound[1]);
    }

    public function testDayIsReplacedInOneTransaction(): void
    {
        $day = gmdate('Y-m-d');
        $this->rollup()->rollupDay($day);

        $names = array_column($this->calls, 0);
        $begin = array_search('beginTransaction', $names, true);
        $commit = array_search('commit', $names, true);
        $this->assertNotFalse($begin);
        $this->assertNotFalse($commit);
        $deletes = array_filter($this->calls, static fn (array $call): bool => $call[0] === 'delete');
        $this->assertSame(
            ['panth_aiv_crawl_daily', 'panth_aiv_path_daily', 'panth_aiv_visit_daily'],
            array_values(array_column($deletes, 1))
        );
        foreach (array_keys($deletes) as $index) {
            $this->assertGreaterThan($begin, $index);
            $this->assertLessThan($commit, $index);
            $this->assertSame(['day = ?' => $day], $this->calls[$index][2]);
        }
        $this->assertNotContains('rollBack', $names);
    }

    public function testFailureRollsBackAndRethrows(): void
    {
        $this->data['paths'] = [['store_id' => '1', 'path_hash' => 'a', 'path' => '/']];
        $this->data['crawl'] = [$this->crawlGroup('1', '0', 'gptbot', 'training', 'a', 1, [1, 0, 0, 0], 1, 1)];
        $this->insertFailure = new \RuntimeException('duplicate');

        try {
            $this->rollup()->rollupDay(gmdate('Y-m-d'));
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('duplicate', $e->getMessage());
        }
        $names = array_column($this->calls, 0);
        $this->assertContains('rollBack', $names);
        $this->assertNotContains('commit', $names);
    }

    public function testStoreFilterIsAppliedToEveryReadAndDelete(): void
    {
        $day = gmdate('Y-m-d');
        $this->rollup()->rollupDay($day, 2);

        foreach ($this->calls as [$method, $sql, $bind]) {
            if ($method === 'query' || ($method === 'fetchAll' && !str_contains($sql, 'url_rewrite'))) {
                $this->assertStringContainsString('store_id = ?', $sql);
                $this->assertContains(2, $bind);
            }
            if ($method === 'delete') {
                $this->assertSame(['day = ?' => $day, 'store_id = ?' => 2], $bind);
            }
        }
    }

    public function testQueriesUseHalfOpenUtcDayAndExcludeCandidates(): void
    {
        $this->rollup()->rollupDay('2026-02-28');

        $queries = array_filter($this->calls, static fn (array $call): bool => $call[0] === 'query');
        $this->assertNotEmpty($queries);
        foreach ($queries as [, $sql, $bind]) {
            $this->assertStringContainsString('created_at >= ? AND created_at < ?', $sql);
            $this->assertSame('2026-02-28 00:00:00', $bind[0]);
            $this->assertSame('2026-03-01 00:00:00', $bind[1]);
            $this->assertTrue(
                in_array('unknown', $bind, true) || in_array('cand:%', $bind, true),
                'candidate rows are excluded'
            );
        }
    }

    public function testPurgedDayBeforeTheRetentionWindowKeepsItsHistory(): void
    {
        $day = gmdate('Y-m-d', time() - 40 * 86400);
        $this->rawExists = ['panth_aiv_hit' => false, 'panth_aiv_visit' => false];

        $this->rollup()->rollupDay($day);

        $names = array_column($this->calls, 0);
        $this->assertNotContains('beginTransaction', $names);
        $this->assertNotContains('delete', $names);
    }

    public function testOnlyThePartWithRawRowsLeftIsRebuilt(): void
    {
        $day = gmdate('Y-m-d', time() - 20 * 86400);
        $this->rawExists = ['panth_aiv_hit' => false, 'panth_aiv_visit' => '1'];

        $this->rollup()->rollupDay($day);

        $deletes = array_filter($this->calls, static fn (array $call): bool => $call[0] === 'delete');
        $this->assertSame(['panth_aiv_visit_daily'], array_values(array_column($deletes, 1)));
        $crawlQueries = array_filter(
            $this->calls,
            static fn (array $call): bool => $call[0] === 'query' && str_contains($call[1], 'SUM(CASE')
        );
        $this->assertSame([], $crawlQueries);
    }

    /**
     * Build the service with a scripted connection
     *
     * @return Rollup
     */
    private function rollup(): Rollup
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($name): string => '`' . $name . '`');
        $connection->method('query')->willReturnCallback(function (string $sql, array $bind = []) {
            $this->calls[] = ['query', $sql, $bind];
            if (str_contains($sql, 'SUM(CASE')) {
                return $this->statement($this->data['crawl']);
            }
            if (str_contains($sql, 'AS visits')) {
                return $this->statement($this->data['visit']);
            }
            if (str_contains($sql, 'ORDER BY')) {
                return $this->statement($this->data['path']);
            }
            return $this->statement($this->data['paths']);
        });
        $connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind = []) {
            $this->calls[] = ['fetchAll', $sql, $bind];
            if (str_contains($sql, 'url_rewrite')) {
                $values = array_map('strtolower', array_slice($bind, 4));
                return array_values(array_filter(
                    $this->data['rewrite'],
                    static fn (array $row): bool => in_array(strtolower($row['request_path']), $values, true)
                ));
            }
            return $this->data['overflow'];
        });
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $bind = []) {
            $this->calls[] = ['fetchOne', $sql, $bind];
            foreach ($this->rawExists as $table => $result) {
                if (str_contains($sql, '`' . $table . '`')) {
                    return $result;
                }
            }
            return '1';
        });
        $connection->method('delete')->willReturnCallback(function (string $table, array $where) {
            $this->calls[] = ['delete', $table, $where];
            return 1;
        });
        $connection->method('insertMultiple')->willReturnCallback(function (string $table, array $rows) {
            if ($this->insertFailure !== null) {
                throw $this->insertFailure;
            }
            $this->calls[] = ['insertMultiple', $table, $rows];
            return count($rows);
        });
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $connection->method($method)->willReturnCallback(function () use ($method, $connection) {
                $this->calls[] = [$method, '', []];
                return $connection;
            });
        }

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $resolver = $this->createStub(PageTypeResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static fn (string $path): string => match ($path) {
            '/' => PageTypeResolverInterface::TYPE_HOME,
            '/llms.txt' => PageTypeResolverInterface::TYPE_LLMS,
            default => PageTypeResolverInterface::TYPE_PAGE,
        });

        $config = $this->createStub(Config::class);
        $config->method('hitRetentionDays')->willReturn(14);
        $config->method('rawVisitRetentionDays')->willReturn(30);
        $config->method('urlStoreCode')->willReturnCallback(fn (int $store): string => $this->storeCodes[$store] ?? '');

        return new Rollup($resource, $resolver, $config);
    }

    /**
     * Statement stub returning the rows one by one
     *
     * @param array $rows
     * @return \Zend_Db_Statement_Interface
     */
    private function statement(array $rows): \Zend_Db_Statement_Interface
    {
        $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
        $statement->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
        return $statement;
    }

    /**
     * Grouped crawl row as returned by MySQL (strings)
     *
     * @param string $store
     * @param string $sim
     * @param string $bot
     * @param string $category
     * @param string $hash
     * @param int $hits
     * @param int[] $classes
     * @param int $totalMs
     * @param int $maxMs
     * @return array
     */
    private function crawlGroup(
        string $store,
        string $sim,
        string $bot,
        string $category,
        string $hash,
        int $hits,
        array $classes,
        int $totalMs,
        int $maxMs
    ): array {
        return [
            'store_id' => $store, 'is_simulated' => $sim, 'bot_code' => $bot, 'category' => $category,
            'path_hash' => $hash, 'hits' => (string)$hits, 's2xx' => (string)$classes[0],
            's3xx' => (string)$classes[1], 's4xx' => (string)$classes[2], 's5xx' => (string)$classes[3],
            'total_ms' => (string)$totalMs, 'max_ms' => (string)$maxMs,
        ];
    }

    /**
     * All rows inserted into a table
     *
     * @param string $table
     * @return array
     */
    private function inserted(string $table): array
    {
        $rows = [];
        foreach ($this->calls as [$method, $name, $data]) {
            if ($method === 'insertMultiple' && $name === $table) {
                array_push($rows, ...$data);
            }
        }
        return $rows;
    }

    /**
     * Rows keyed by the given fields, sorted by key
     *
     * @param array $rows
     * @param string[] $fields
     * @return array
     */
    private function indexed(array $rows, array $fields): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[implode('|', array_map(static fn (string $field): string => (string)$row[$field], $fields))] = $row;
        }
        ksort($result);
        return $result;
    }

    /**
     * Counters of a crawl row in a fixed order
     *
     * @param array $row
     * @return int[]
     */
    private function counts(array $row): array
    {
        return [$row['hits'], $row['s2xx'], $row['s3xx'], $row['s4xx'], $row['s5xx'], $row['total_ms'], $row['max_ms']];
    }
}
