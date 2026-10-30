<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Framework\App\PageCache\Version;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Session\SessionStartChecker;
use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Api\HitWriterInterface;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Api\RelabelerInterface;
use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Model\BotMatch;
use Panth\AiVisibility\Model\Catalogue\BrowserUserAgents;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Model\RuleWriter;
use Panth\AiVisibility\Model\TextFilter;
use Panth\AiVisibility\Observer\TrackRequest;
use Panth\AiVisibility\Plugin\CollectorContentVersion;
use Panth\AiVisibility\Plugin\CollectorSessionChecker;
use Panth\AiVisibility\Plugin\OrderGridCollectionPlugin;
use Panth\AiVisibility\Service\BotNames;
use Panth\AiVisibility\Service\Discovery\CandidateClassifier;
use Panth\AiVisibility\Service\Discovery\CandidateRepository;
use Panth\AiVisibility\Service\LogImport\CombinedLogParser;
use Panth\AiVisibility\Service\LogImport\FileTailer;
use Panth\AiVisibility\Service\LogImport\LogImporter;
use Panth\AiVisibility\Service\Relabeler;
use Panth\AiVisibility\Service\Retention;
use Panth\AiVisibility\Service\Rollup;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for the fixes made after the deep test (one group per finding id).
 */
class FixRegressionTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/130.0.0.0 Safari/537.36';

    /**
     * @var array<int, array{0: string, 1: string, 2: mixed}>
     */
    private array $calls = [];

    /**
     * @var string[]
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            if (is_link($file) || is_file($file)) {
                unlink($file);
            }
        }
    }

    // ------------------------------------------------------------------ D6-1 / D9-1 browsers in Discovery

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function userAgents(): array
    {
        return [
            'chrome' => [self::CHROME, true],
            'iphone safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 '
                . '(KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', true],
            'firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', true],
            'bot in browser ua' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ChatGPT-User/1.0; '
                . '+https://openai.com/bot)', false],
            'curl' => ['curl/8.4.0', false],
            'own product' => ['ExampleCrawler/2.1 (+https://example.invalid/bot)', false],
        ];
    }

    /**
     * @dataProvider userAgents
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('userAgents')]
    public function testPlainBrowserDetection(string $userAgent, bool $browser): void
    {
        $this->assertSame($browser, BrowserUserAgents::isPlainBrowser($userAgent));
        $this->assertSame(!$browser, TrackRequest::isLlmsCandidate($userAgent, PageTypeResolverInterface::TYPE_LLMS));
    }

    public function testRealBotTokensAreNotGeneric(): void
    {
        foreach (['ExampleCrawler', 'SimAgent', 'NewsAgent/2', 'AcmeFetcher'] as $token) {
            $this->assertFalse(BrowserUserAgents::sharesBrowserText($token), $token);
        }
        foreach (['like', 'Intel', 'CPU', 'Gecko', 'Pixel', 'Mobile/15E148', 'Version/17.5', 'x86_64'] as $token) {
            $this->assertTrue(BrowserUserAgents::sharesBrowserText($token), $token);
        }
    }

    public function testBrowserFetchingLlmsTxtIsNotImportedAsCandidate(): void
    {
        $path = $this->logFile(
            $this->line('/llms.txt', self::CHROME) . $this->line('/llms.txt', 'curl/8.4.0')
        );
        [$importer, $writes] = $this->importer($path);
        $file = $importer->run()['files'][0];
        $this->assertSame(1, $file['candidates']);
        $this->assertSame('curl/8.4.0', $writes->rows[0][1]);
    }

    // ------------------------------------------------------------------ D1-1 / D1-2 request text

    public function testInvalidBytesArePercentEncodedNotReplaced(): void
    {
        $this->assertSame('/a%FFb', TextFilter::encodeInvalidUtf8("/a\xffb"));
        $this->assertSame('/café', TextFilter::encodeInvalidUtf8('/café'));
        [$path] = TrackRequest::splitUri('/x%3Fy%23z%2Fw');
        $this->assertSame('/x%3Fy%23z/w', $path);
        $this->assertSame('GPTBot/1.1 x', TextFilter::userAgent("GPTBot/1.1\x7f\x00x"));
    }

    // ------------------------------------------------------------------ D7-4 web API paths

    public function testWebApiPathsAreSkipped(): void
    {
        foreach (['/rest/V1/x', '/default/rest/V1/x', '/index.php/rest/V1/x', '/graphql', '/soap/default'] as $path) {
            $this->assertTrue(TrackRequest::isSkippedPath($path), $path);
        }
        foreach (['/restaurant.html', '/graphql-guide', '/gear/rest.html'] as $path) {
            $this->assertFalse(TrackRequest::isSkippedPath($path), $path);
        }
    }

    // ------------------------------------------------------------------ D2-1 collector session

    public function testCollectorRouteHasNoSessionAndKeepsTheContentVersion(): void
    {
        $collector = $this->createStub(HttpRequest::class);
        $collector->method('getRequestUri')->willReturn('/luma/panth_aiv/collect/index');
        $page = $this->createStub(HttpRequest::class);
        $page->method('getRequestUri')->willReturn('/gear.html');
        $checker = $this->createStub(SessionStartChecker::class);

        $this->assertFalse((new CollectorSessionChecker($collector))->afterCheck($checker, true));
        $this->assertTrue((new CollectorSessionChecker($page))->afterCheck($checker, true));
        $this->assertFalse((new CollectorSessionChecker($page))->afterCheck($checker, false));

        $called = 0;
        $proceed = static function () use (&$called) {
            $called++;
            return null;
        };
        $version = $this->createStub(Version::class);
        (new CollectorContentVersion($collector))->aroundProcess($version, $proceed);
        $this->assertSame(0, $called, 'private_content_version is not renewed for a beacon');
        (new CollectorContentVersion($page))->aroundProcess($version, $proceed);
        $this->assertSame(1, $called);
    }

    // ------------------------------------------------------------------ D4-1 rollup per slice and raw floor

    public function testDayBeforeTheRawFloorWithDailyRowsIsNeverDeleted(): void
    {
        // Raw rows exist (for example imported later), the day is before the floor and has daily rows.
        $this->rollup(['floor' => '20261020', 'raw' => true, 'daily' => true])->rollupDay('2026-10-10');
        $this->assertSame([], $this->calls('delete'));
        $this->assertSame([], $this->calls('insertMultiple'));
    }

    public function testDayBeforeTheRawFloorWithoutDailyRowsIsFilledWithoutDelete(): void
    {
        $this->rollup(['floor' => '20261020', 'raw' => true, 'daily' => false])->rollupDay('2026-10-10');
        $this->assertSame([], $this->calls('delete'));
        $this->assertNotSame([], $this->calls('insertMultiple'));
    }

    public function testCompleteDayIsRebuiltWithOneDeletePerTable(): void
    {
        $this->rollup(['floor' => '20261001', 'raw' => true, 'daily' => true])->rollupDay('2026-10-10');
        $deletes = $this->calls('delete');
        $this->assertCount(3, $deletes);
        foreach ($deletes as [, , $where]) {
            $this->assertSame(['day = ?' => '2026-10-10'], $where);
        }
    }

    public function testRawFloorNeverMovesBack(): void
    {
        $this->rollup(['floor' => '20261020'])->raiseRawFloor(RollupInterface::KIND_HITS, '2026-10-01');
        $upsert = $this->calls('insertOnDuplicate')[0][2];
        $this->assertSame(20261020, $upsert['value']);
        $this->assertSame('raw_floor_hits', $upsert['counter_key']);
        $this->assertSame(gmdate('Y-m-d'), $upsert['day'], 'kept fresh, so counter pruning never removes it');
    }

    // ------------------------------------------------------------------ D4-2 retention per slice

    public function testRetentionRollsUpADayWhoseStoreSliceIsMissingAndRecordsTheFloorFirst(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($n): string => '`' . $n . '`');
        $connection->method('fetchAll')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'DATE(created_at)') && str_contains($sql, '`panth_aiv_hit`')) {
                return [
                    ['day' => '2026-10-10', 'store_id' => '1', 'is_simulated' => '0'],
                    ['day' => '2026-10-10', 'store_id' => '2', 'is_simulated' => '0'],
                ];
            }
            if (str_contains($sql, '`panth_aiv_crawl_daily`')) {
                return [['day' => '2026-10-10', 'store_id' => '1', 'is_simulated' => '0']];
            }
            return [];
        });
        $order = [];
        $connection->method('query')->willReturnCallback(function (string $sql) use (&$order) {
            if (str_starts_with($sql, 'DELETE FROM `panth_aiv_hit`')) {
                $order[] = 'delete hits';
            }
            return $this->createStub(\Zend_Db_Statement_Interface::class);
        });
        $deletes = [];
        $connection->method('delete')->willReturnCallback(function ($table, $where) use (&$deletes) {
            $deletes[$table] = $where;
            return 0;
        });
        $rollup = $this->createStub(RollupInterface::class);
        $rollup->method('rollupDay')->willReturnCallback(static function (string $day) use (&$order): void {
            $order[] = 'rollup ' . $day;
        });
        $rollup->method('raiseRawFloor')->willReturnCallback(static function (string $kind, string $day) use (&$order) {
            $order[] = 'floor ' . $kind . ' ' . $day;
        });
        $config = $this->createStub(Config::class);
        $config->method('hitRetentionDays')->willReturn(14);
        $config->method('rawVisitRetentionDays')->willReturn(30);
        $config->method('landingRetentionDays')->willReturn(30);
        $config->method('dailyRetentionMonths')->willReturn(13);

        (new Retention($this->resource($connection), $config, $rollup, $this->createStub(LoggerInterface::class)))
            ->run(gmmktime(3, 10, 0, 10, 30, 2026));

        $this->assertSame(
            ['rollup 2026-10-10', 'floor hits 2026-10-16', 'floor visits 2026-09-30', 'delete hits'],
            $order
        );
        $this->assertSame(
            ['status <> ?' => 'confirmed', 'last_seen < ?' => '2026-08-01 00:00:00'],
            $deletes['panth_aiv_candidate'],
            'D6-4: stale candidates that are not confirmed are pruned'
        );
        $this->assertSame(
            ['day < ?' => '2026-10-27', 'counter_key NOT LIKE ?' => 'raw_floor_%'],
            $deletes['panth_aiv_counter'],
            'the raw floor rows are never pruned with the old counters'
        );
    }

    // ------------------------------------------------------------------ D4-3 overflow on pruned days

    public function testMergedOverflowOfAPrunedDayIsAddedToTheDailyRows(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($n): string => '`' . $n . '`');
        $connection->method('fetchCol')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'overflow') ? ['2026-09-01'] : []
        );
        $connection->method('fetchAll')->willReturn([
            ['day' => '2026-09-01', 'store_id' => '1', 'status_class' => '2', 'is_simulated' => '0', 'hits' => '7'],
        ]);
        $connection->method('fetchOne')->willReturnCallback(
            // Raw hits of the day are gone; the daily slice exists.
            static fn (string $sql) => str_contains($sql, '`panth_aiv_crawl_daily`') ? '1' : false
        );
        $connection->method('update')->willReturn(0);
        $upserts = [];
        $connection->method('insertOnDuplicate')->willReturnCallback(function ($table, $rows) use (&$upserts) {
            $upserts[$table] = $rows;
            return count($rows);
        });
        $rollup = $this->createStub(RollupInterface::class);
        $rollup->method('isRawComplete')->willReturn(true);

        (new Relabeler($this->resource($connection), $rollup, $this->createStub(LoggerInterface::class)))
            ->relabelCandidate(82, 'bot', 'disc_simagent', 'training');

        $daily = $upserts['panth_aiv_crawl_daily'][0] ?? [];
        $this->assertSame(['disc_simagent', 'other', 7, 7], [
            $daily['bot_code'] ?? null, $daily['page_type'] ?? null, $daily['hits'] ?? null, $daily['s2xx'] ?? null,
        ]);
    }

    // ------------------------------------------------------------------ D7-2 / D7-7 log import

    public function testImportRollsUpEveryDayItTouchedIncludingOldDays(): void
    {
        $old = gmdate('d/M/Y:H:i:s', time() - 9 * 86400) . ' +0000';
        $path = $this->logFile($this->line('/a', 'GPTBot/1.2', $old) . $this->line('/b', 'GPTBot/1.2'));
        $rolled = [];
        $rollup = $this->createStub(RollupInterface::class);
        $rollup->method('rollupDay')->willReturnCallback(static function (string $day, ?int $store) use (&$rolled) {
            $rolled[] = [$day, $store];
        });
        [$importer] = $this->importer($path, $rollup);
        $result = $importer->run();
        $this->assertSame([[gmdate('Y-m-d', time() - 9 * 86400), 1], [gmdate('Y-m-d'), 1]], $rolled);
        $this->assertSame(2, $result['rolled_up']);
    }

    public function testGzipFileIsReportedAndSymlinkIsImportedOnce(): void
    {
        $gz = $this->logFile("\x1f\x8b\x08\x00compressed");
        [$importer] = $this->importer($gz);
        $this->assertStringContainsString('gzip-compressed', $importer->run()['files'][0]['error']);

        $real = $this->logFile($this->line('/a', 'GPTBot/1.2'));
        $link = $real . '.link';
        if (!function_exists('symlink') || !symlink($real, $link)) {
            $this->markTestSkipped('symlinks not available');
        }
        $this->files[] = $link;
        [$importer, $writes] = $this->importer([$real, $link]);
        $this->assertCount(1, $importer->run()['files']);
        $this->assertCount(1, $writes->rows);
    }

    public function testFileTruncatedAndRefilledPastTheOffsetIsReadFromTheStart(): void
    {
        $first = str_repeat($this->line('/a', 'GPTBot/1.2'), 4);
        $path = $this->logFile($first);
        [$importer, , $state] = $this->importer($path);
        $importer->run();
        $saved = $state->rows[sha1($path)];
        $this->assertNotNull($saved['head_hash']);

        file_put_contents($path, str_repeat($this->line('/zz', 'GPTBot/1.2'), 6));
        clearstatcache();
        [$importer2, $writes] = $this->importer($path, null, $state);
        $file = $importer2->run()['files'][0];
        $this->assertSame(LogImporter::RESET_TRUNCATED, $file['reset']);
        $this->assertCount(6, $writes->rows);
    }

    // ------------------------------------------------------------------ D7-1 utf8mb4

    public function testEveryTableIsDeclaredUtf8mb4(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/db_schema.xml');
        $tables = $xml->xpath('//table') ?: [];
        $this->assertCount(14, $tables);
        foreach ($tables as $table) {
            $this->assertSame('utf8mb4', (string)$table['charset'], (string)$table['name']);
            $this->assertSame('utf8mb4_general_ci', (string)$table['collation'], (string)$table['name']);
        }
    }

    // ------------------------------------------------------------------ D3-2 / D9-3

    public function testOrderGridWorksWithoutTheAttributionTable(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(false);
        $select = $this->createMock(Select::class);
        $select->method('getPart')->willReturn([]);
        $select->expects($this->never())->method('joinLeft');
        $collection = $this->createStub(AbstractDb::class);
        $collection->method('getSelect')->willReturn($select);
        $plugin = new OrderGridCollectionPlugin(
            $this->resource($connection),
            $this->createStub(Config::class),
            $this->createStub(LoggerInterface::class)
        );
        $this->assertSame(
            $collection,
            $plugin->afterGetReport($this->createStub(CollectionFactory::class), $collection, 'sales_order_grid_data_source')
        );
    }

    public function testDeletingARuleMovesItsRowsBackToTheCandidate(): void
    {
        $rules = $this->createStub(RuleProvider::class);
        $rules->method('botRules')->willReturn([[
            'rule_id' => 3, 'type' => 'bot', 'code' => 'disc_x', 'name' => 'X', 'candidate_id' => 82,
        ]]);
        $rules->method('sourceRules')->willReturn([]);
        $candidates = $this->createStub(CandidateRepository::class);
        $candidates->method('get')->willReturn(['candidate_id' => 82, 'type' => 'bot', 'status' => 'confirmed']);
        $relabeler = $this->createMock(RelabelerInterface::class);
        $relabeler->expects($this->once())->method('restoreCandidate')->with(82, 'bot', 'disc_x')->willReturn(4);
        $classifier = new CandidateClassifier(
            $candidates,
            $rules,
            $this->createStub(RuleWriter::class),
            $relabeler,
            $this->createStub(BotClassifierInterface::class),
            $this->createStub(\Panth\AiVisibility\Api\SourceClassifierInterface::class),
            $this->createStub(BotNames::class)
        );
        $this->assertStringContainsString('moved it back', (string)$classifier->deleteRule(3));
    }

    // ------------------------------------------------------------------ re-test round: old browser candidates, restore counter

    public function testDiscoveryCronMarksOldBrowserCandidatesAsNotAi(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchPairs')->willReturn([
            '6' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/17.0 Safari/605.1.15',
            '7' => 'ExampleCrawler/2.1 (+https://example.invalid/bot)',
            '8' => self::CHROME,
        ]);
        $updates = [];
        $connection->method('update')->willReturnCallback(function ($table, $data, $where) use (&$updates) {
            $updates[] = [$table, $data, $where];
            return 2;
        });
        $config = $this->createStub(Config::class);
        $config->method('discoveryEnabled')->willReturn(true);
        $cron = new \Panth\AiVisibility\Cron\Discovery(
            $config,
            $this->createStub(\Panth\AiVisibility\Service\SourceEvidence::class),
            $this->createStub(LoggerInterface::class),
            new CandidateRepository($this->resource($connection))
        );
        $cron->execute();

        $this->assertSame([[
            'panth_aiv_candidate',
            ['status' => 'not_ai'],
            ['candidate_id IN (?)' => [6, 8], 'status = ?' => 'new'],
        ]], $updates, 'browser candidates are marked not AI (never deleted), the real bot stays new');
    }

    public function testRestoringACandidateRaisesItsHitCounterToTheRestoredRows(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($n): string => '`' . $n . '`');
        $connection->method('fetchCol')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'overflow') ? [] : [gmdate('Y-m-d')]
        );
        $connection->method('fetchAll')->willReturn([]);
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $bind = []) {
            if (str_contains($sql, 'COUNT(*)') && str_contains($sql, '`panth_aiv_hit`') && $bind === ['cand:82']) {
                return '2';
            }
            return str_contains($sql, 'SUM(hits)') ? '0' : false;
        });
        $updates = [];
        $connection->method('update')->willReturnCallback(function ($table, $data, $where) use (&$updates) {
            $updates[$table] = [$data, $where];
            return 2;
        });

        (new Relabeler($this->resource($connection), $this->createStub(RollupInterface::class), $this->createStub(LoggerInterface::class)))
            ->restoreCandidate(82, 'bot', 'disc_x');

        $this->assertSame(['bot_code' => 'cand:82', 'category' => 'unknown'], $updates['panth_aiv_hit'][0]);
        [$data, $where] = $updates['panth_aiv_candidate'];
        $this->assertSame('GREATEST(hits, 2)', (string)$data['hits']);
        $this->assertSame(['candidate_id = ?' => 82], $where);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Rollup on a scripted connection
     *
     * @param array{floor?: string, raw?: bool, daily?: bool} $state
     * @return Rollup
     */
    private function rollup(array $state): Rollup
    {
        $this->calls = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($n): string => '`' . $n . '`');
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql) use ($state) {
            if (str_contains($sql, 'MAX(value)')) {
                return $state['floor'] ?? false;
            }
            if (str_contains($sql, '_daily`')) {
                return !empty($state['daily']) ? '1' : false;
            }
            return !empty($state['raw']) ? '1' : false;
        });
        $connection->method('query')->willReturnCallback(function (string $sql) {
            $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
            $rows = str_contains($sql, 'SUM(CASE') ? [[
                'store_id' => '1', 'is_simulated' => '0', 'bot_code' => 'gptbot', 'category' => 'training',
                'path_hash' => 'h', 'hits' => '2', 's2xx' => '2', 's3xx' => '0', 's4xx' => '0', 's5xx' => '0',
                'total_ms' => '4', 'max_ms' => '3',
            ]] : [];
            $statement->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
            return $statement;
        });
        $connection->method('fetchAll')->willReturn([]);
        foreach (['delete', 'insertMultiple', 'insertOnDuplicate'] as $method) {
            $connection->method($method)->willReturnCallback(function ($table, $data) use ($method) {
                $this->calls[] = [$method, (string)$table, $data];
                return 1;
            });
        }
        $resolver = $this->createStub(PageTypeResolverInterface::class);
        $resolver->method('resolve')->willReturn(PageTypeResolverInterface::TYPE_OTHER);
        $config = $this->createStub(Config::class);
        $config->method('urlStoreCode')->willReturn('');
        return new Rollup($this->resource($connection), $resolver, $config);
    }

    /**
     * Recorded calls of one method
     *
     * @param string $method
     * @return array
     */
    private function calls(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === $method));
    }

    /**
     * @param AdapterInterface $connection
     * @return ResourceConnection
     */
    private function resource(AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    /**
     * Importer for the given files (store "default" = 1); returns [importer, writes, state]
     *
     * @param string|string[] $paths
     * @param RollupInterface|null $rollup
     * @param \stdClass|null $state
     * @return array{0: LogImporter, 1: \stdClass, 2: \stdClass}
     */
    private function importer($paths, ?RollupInterface $rollup = null, ?\stdClass $state = null): array
    {
        $files = array_map(static fn (string $p): array => ['path' => $p, 'store_code' => 'default'], (array)$paths);
        $config = $this->createStub(Config::class);
        $config->method('logImportEnabled')->willReturn(true);
        $config->method('logImportMaxLines')->willReturn(1000);
        $config->method('isEnabled')->willReturn(true);
        $config->method('trackCrawlers')->willReturn(true);
        $config->method('discoveryEnabled')->willReturn(true);
        $config->method('logImportFiles')->willReturn($files);

        $writes = new \stdClass();
        $writes->rows = [];
        $writer = $this->createStub(HitWriterInterface::class);
        $writer->method('recordHit')->willReturnCallback(static function (...$a) use ($writes): void {
            $writes->rows[] = ['hit', $a[2]];
        });
        $writer->method('recordCandidateHit')->willReturnCallback(static function (...$a) use ($writes): void {
            $writes->rows[] = ['candidate', $a[1]];
        });
        $classifier = $this->createStub(BotClassifierInterface::class);
        $classifier->method('classify')->willReturnCallback(static fn (string $ua): ?BotMatch => stripos($ua, 'GPTBot')
            !== false ? new BotMatch('gptbot', 'GPTBot', 'OpenAI', 'training') : null);
        $classifier->method('isDiscoveryCandidate')->willReturn(false);
        $resolver = $this->createStub(PageTypeResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static fn (string $p): string => $p === '/llms.txt'
            ? PageTypeResolverInterface::TYPE_LLMS : PageTypeResolverInterface::TYPE_PAGE);

        $state ??= new \stdClass();
        $state->rows ??= [];
        $key = '';
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select, &$key) {
            $key = (string)$value;
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturnCallback(static function () use (&$key, $state) {
            return $state->rows[$key] ?? false;
        });
        $connection->method('insertOnDuplicate')->willReturnCallback(static function ($t, array $row) use ($state) {
            $state->rows[$row['file_key']] = $row;
            return 1;
        });
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $lock = $this->createStub(LockManagerInterface::class);
        $lock->method('lock')->willReturn(true);

        $importer = new LogImporter(
            $config,
            $classifier,
            $writer,
            $resolver,
            new CombinedLogParser(),
            new FileTailer(new File()),
            $this->resource($connection),
            $storeManager,
            $lock,
            $this->createStub(LoggerInterface::class),
            $rollup
        );
        return [$importer, $writes, $state];
    }

    /**
     * @param string $content
     * @return string
     */
    private function logFile(string $content): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'aivfix');
        file_put_contents($path, $content);
        $this->files[] = $path;
        return $path;
    }

    /**
     * @param string $uri
     * @param string $ua
     * @param string|null $time
     * @return string
     */
    private function line(string $uri, string $ua, ?string $time = null): string
    {
        $time ??= gmdate('d/M/Y:H:i:s') . ' +0000';
        return '203.0.113.9 - - [' . $time . '] "GET ' . $uri . ' HTTP/1.1" 200 512 "-" "' . $ua . "\"\n";
    }
}
