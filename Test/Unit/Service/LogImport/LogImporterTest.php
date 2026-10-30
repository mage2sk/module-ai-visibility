<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\LogImport;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Phrase;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Api\HitWriterInterface;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Model\BotMatch;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\LogImport\CombinedLogParser;
use Panth\AiVisibility\Service\LogImport\FileTailer;
use Panth\AiVisibility\Service\LogImport\LogImporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Uses the real parser and tailer on generated temp files; database, classifier and writer are mocks.
 */
class LogImporterTest extends TestCase
{
    private const BOT_UA = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://example.invalid/bot)';
    private const CANDIDATE_UA = 'NewCrawler/0.1 (+https://example.invalid/crawler)';
    private const HUMAN_UA = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0';

    /**
     * @var Config&Stub
     */
    private $config;

    /**
     * @var HitWriterInterface&Stub
     */
    private $hitWriter;

    /**
     * @var AdapterInterface&Stub
     */
    private $connection;

    /**
     * @var StoreManagerInterface&Stub
     */
    private $storeManager;

    /**
     * @var LockManagerInterface&Stub
     */
    private $lockManager;

    /**
     * @var LoggerInterface&Stub
     */
    private $logger;

    /**
     * Saved state rows by file_key, as the mocked table would hold them
     *
     * @var array<string, array<string, mixed>>
     */
    private array $state = [];

    /**
     * Rows passed to insertOnDuplicate
     *
     * @var array<int, array<string, mixed>>
     */
    private array $saved = [];

    /**
     * Calls of the hit writer: [method, args]
     *
     * @var array<int, array{0: string, 1: array<int, mixed>}>
     */
    private array $writes = [];

    /**
     * @var array<int, string>
     */
    private array $files = [];

    /**
     * @var array<int, array{path: string, store_code: string}>
     */
    private array $configuredFiles = [];

    /**
     * @var bool
     */
    private bool $discovery = true;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('logImportEnabled')->willReturn(true);
        $this->config->method('logImportMaxLines')->willReturn(1000);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('trackCrawlers')->willReturn(true);
        $this->config->method('discoveryEnabled')->willReturnCallback(fn (): bool => $this->discovery);
        $this->config->method('logImportFiles')->willReturnCallback(fn (): array => $this->configuredFiles);

        $this->hitWriter = $this->createStub(HitWriterInterface::class);
        $this->hitWriter->method('recordHit')->willReturnCallback(function (...$args): void {
            $this->writes[] = ['hit', $args];
        });
        $this->hitWriter->method('recordCandidateHit')->willReturnCallback(function (...$args): void {
            $this->writes[] = ['candidate', $args];
        });

        $select = $this->createStub(Select::class);
        $key = '';
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value) use ($select, &$key) {
            $key = (string)$value;
            return $select;
        });
        $this->connection = $this->createStub(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturnCallback(function () use (&$key) {
            return $this->state[$key] ?? false;
        });
        $this->connection->method('insertOnDuplicate')->willReturnCallback(function ($table, array $row) {
            $this->saved[] = $row;
            $this->state[$row['file_key']] = $row;
            return 1;
        });

        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturnCallback(function ($code) {
            if ($code === 'missing') {
                throw new NoSuchEntityException(new Phrase('no store'));
            }
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($code === 'second' ? 2 : 1);
            return $store;
        });

        $this->lockManager = $this->createStub(LockManagerInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Build the importer with a classifier that knows GPTBot and treats "Crawler" UAs as candidates
     *
     * @return LogImporter
     */
    private function importer(): LogImporter
    {
        $classifier = $this->createStub(BotClassifierInterface::class);
        $classifier->method('classify')->willReturnCallback(static function (string $ua): ?BotMatch {
            return stripos($ua, 'GPTBot') !== false ? new BotMatch('gptbot', 'GPTBot', 'OpenAI', 'training') : null;
        });
        $classifier->method('isDiscoveryCandidate')->willReturnCallback(static function (string $ua): bool {
            return stripos($ua, 'crawler') !== false;
        });
        $resolver = $this->createStub(PageTypeResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static function (string $path): string {
            return $path === '/llms.txt' ? PageTypeResolverInterface::TYPE_LLMS : PageTypeResolverInterface::TYPE_PAGE;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new LogImporter(
            $this->config,
            $classifier,
            $this->hitWriter,
            $resolver,
            new CombinedLogParser(),
            new FileTailer(new File()),
            $resource,
            $this->storeManager,
            $this->lockManager,
            $this->logger
        );
    }

    /**
     * Create a temp log file and register it in the configuration
     *
     * @param string $content
     * @param string $storeCode
     * @return string
     */
    private function logFile(string $content, string $storeCode = 'default'): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'aivlog');
        file_put_contents($path, $content);
        $this->files[] = $path;
        $this->configuredFiles[] = ['path' => $path, 'store_code' => $storeCode];
        return $path;
    }

    /**
     * One nginx combined line
     *
     * @param string $path
     * @param string $ua
     * @param int $status
     * @param string $time
     * @return string
     */
    private static function line(
        string $path,
        string $ua,
        int $status = 200,
        string $time = '29/Oct/2026:12:00:00 +0200'
    ): string {
        return '203.0.113.9 - - [' . $time . '] "GET ' . $path . ' HTTP/1.1" ' . $status . ' 512 "-" "' . $ua . "\"\n";
    }

    private function lockFree(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
    }

    public function testImportsHitsCandidatesAndCountsEverything(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockFree();
        $this->lockManager->expects($this->once())->method('unlock')->with(LogImporter::LOCK_NAME);
        $content = self::line('/gear/tent.html?color=red', self::BOT_UA)
            . self::line('/gear/tent.html', self::HUMAN_UA)
            . self::line('/about', self::CANDIDATE_UA, 404)
            . self::line('/llms.txt', 'curl/8.0')
            . self::line('/static/version1/frontend/a.css', self::BOT_UA)
            . self::line('/media/catalog/product/a.jpg', self::BOT_UA)
            . self::line('/media/sitemap.xml', self::BOT_UA)
            . "garbage line\n"
            . '2001:db8::5 - - [29/Oct/2026:10:00:01 +0000] "GET http://shop.test/p.html HTTP/1.1" 301 0 "-" "'
            . self::BOT_UA . "\"\n";
        $path = $this->logFile($content);

        $result = $this->importer()->run();

        $this->assertSame(LogImporter::STATUS_OK, $result['status']);
        $file = $result['files'][0];
        $this->assertSame(9, $file['lines']);
        $this->assertSame(3, $file['hits']);
        $this->assertSame(2, $file['candidates']);
        $this->assertSame(2, $file['skipped']);
        $this->assertSame(1, $file['malformed']);
        $this->assertSame('', $file['error']);
        $this->assertSame(strlen($content), $file['end']);
        $this->assertFalse($file['more']);

        $this->assertSame('hit', $this->writes[0][0]);
        [$storeId, $bot, $hitPath, $hasQuery, $status, $duration, $origin, $ts] = $this->writes[0][1];
        $this->assertSame(1, $storeId);
        $this->assertSame('gptbot', $bot->code);
        $this->assertSame('/gear/tent.html', $hitPath);
        $this->assertTrue($hasQuery);
        $this->assertSame(200, $status);
        $this->assertSame(0, $duration);
        $this->assertSame(HitWriterInterface::ORIGIN_LOG, $origin);
        $this->assertSame(gmmktime(10, 0, 0, 10, 29, 2026), $ts);

        $this->assertSame(['candidate', [1, self::CANDIDATE_UA, '/about', false, 404, 0, 'log',
            gmmktime(10, 0, 0, 10, 29, 2026)]], $this->writes[1]);
        $this->assertSame('candidate', $this->writes[2][0]);
        $this->assertSame('/llms.txt', $this->writes[2][1][2]);
        $this->assertSame('/media/sitemap.xml', $this->writes[3][1][2]);
        $this->assertSame('/p.html', $this->writes[4][1][2]);
        $this->assertSame(301, $this->writes[4][1][4]);

        $saved = end($this->saved);
        $this->assertSame(sha1($path), $saved['file_key']);
        $this->assertSame($path, $saved['path']);
        $this->assertSame(strlen($content), $saved['byte_offset']);
        $this->assertSame((int)fileinode($path), $saved['inode']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $saved['updated_at']);
    }

    public function testResumesFromSavedOffset(): void
    {
        $this->lockFree();
        $first = self::line('/a', self::BOT_UA);
        $path = $this->logFile($first . self::line('/b', self::BOT_UA));
        $this->state[sha1($path)] = ['inode' => (int)fileinode($path), 'byte_offset' => strlen($first)];

        $file = $this->importer()->run()['files'][0];

        $this->assertSame(1, $file['lines']);
        $this->assertSame(strlen($first), $file['start']);
        $this->assertSame('', $file['reset']);
        $this->assertCount(1, $this->writes);
        $this->assertSame('/b', $this->writes[0][1][2]);
    }

    public function testRotationRestartsAtZero(): void
    {
        $this->lockFree();
        $path = $this->logFile(self::line('/new', self::BOT_UA));
        $this->state[sha1($path)] = ['inode' => (int)fileinode($path) + 1, 'byte_offset' => 5];

        $file = $this->importer()->run()['files'][0];

        $this->assertSame(LogImporter::RESET_ROTATED, $file['reset']);
        $this->assertSame(0, $file['start']);
        $this->assertSame(1, $file['hits']);
    }

    public function testTruncationRestartsAtZero(): void
    {
        $this->lockFree();
        $path = $this->logFile(self::line('/x', self::BOT_UA));
        $this->state[sha1($path)] = ['inode' => (int)fileinode($path), 'byte_offset' => 999999];

        $file = $this->importer()->run()['files'][0];

        $this->assertSame(LogImporter::RESET_TRUNCATED, $file['reset']);
        $this->assertSame(1, $file['hits']);
    }

    public function testFromStartIgnoresSavedOffset(): void
    {
        $this->lockFree();
        $content = self::line('/x', self::BOT_UA);
        $path = $this->logFile($content);
        $this->state[sha1($path)] = ['inode' => (int)fileinode($path), 'byte_offset' => strlen($content)];

        $file = $this->importer()->run(null, false, true)['files'][0];

        $this->assertSame(LogImporter::RESET_FROM_START, $file['reset']);
        $this->assertSame(1, $file['hits']);
    }

    public function testPartialLastLineIsLeftForNextRun(): void
    {
        $this->lockFree();
        $complete = self::line('/a', self::BOT_UA);
        $partial = substr(self::line('/b', self::BOT_UA), 0, 30);
        $path = $this->logFile($complete . $partial);

        $file = $this->importer()->run()['files'][0];
        $this->assertSame(1, $file['lines']);
        $this->assertSame(strlen($complete), $file['end']);
        $this->assertSame(0, $file['malformed']);

        // The web server finishes the line.
        file_put_contents($path, substr(self::line('/b', self::BOT_UA), 30), FILE_APPEND);
        $second = $this->importer()->run()['files'][0];
        $this->assertSame(1, $second['lines']);
        $this->assertSame(1, $second['hits']);
        $this->assertSame('/b', $this->writes[1][1][2]);
    }

    public function testMaxLinesPerRunAcrossFiles(): void
    {
        $this->lockFree();
        $config = $this->createStub(Config::class);
        $config->method('logImportEnabled')->willReturn(true);
        $config->method('logImportMaxLines')->willReturn(3);
        $config->method('isEnabled')->willReturn(true);
        $config->method('trackCrawlers')->willReturn(true);
        $config->method('logImportFiles')->willReturnCallback(fn (): array => $this->configuredFiles);
        $this->config = $config;

        $this->logFile(str_repeat(self::line('/a', self::BOT_UA), 2));
        $this->logFile(str_repeat(self::line('/b', self::BOT_UA), 2), 'second');

        $result = $this->importer()->run();

        $this->assertSame(2, $result['files'][0]['lines']);
        $this->assertFalse($result['files'][0]['more']);
        $this->assertSame(1, $result['files'][1]['lines']);
        $this->assertTrue($result['files'][1]['more']);
        $this->assertSame(strlen(self::line('/b', self::BOT_UA)), $result['files'][1]['end']);
        $this->assertSame(2, $this->writes[2][1][0]);
    }

    public function testLockedRunDoesNothing(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturn(false);
        $this->lockManager->expects($this->never())->method('unlock');
        $this->logFile(self::line('/a', self::BOT_UA));

        $result = $this->importer()->run();

        $this->assertSame(LogImporter::STATUS_LOCKED, $result['status']);
        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->saved);
    }

    public function testDryRunWritesNothingAndTakesNoLock(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->expects($this->never())->method('lock');
        $this->logFile(self::line('/a', self::BOT_UA) . self::line('/b', self::CANDIDATE_UA));

        $result = $this->importer()->run(null, true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['files'][0]['hits']);
        $this->assertSame(1, $result['files'][0]['candidates']);
        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->saved);
    }

    public function testDisabledReturnsWithoutReading(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('logImportEnabled')->willReturn(false);
        $config->expects($this->never())->method('logImportFiles');
        $this->config = $config;

        $this->assertSame(LogImporter::STATUS_DISABLED, $this->importer()->run()['status']);
    }

    public function testNoFilesAndUnknownFile(): void
    {
        $this->lockFree();
        $this->assertSame(LogImporter::STATUS_NO_FILES, $this->importer()->run()['status']);
        $this->logFile('');
        $this->assertSame(LogImporter::STATUS_UNKNOWN_FILE, $this->importer()->run('/nope.log')['status']);
    }

    public function testOnlyFileRestrictsTheRun(): void
    {
        $this->lockFree();
        $this->logFile(self::line('/a', self::BOT_UA));
        $second = $this->logFile(self::line('/b', self::BOT_UA));

        $result = $this->importer()->run($second);

        $this->assertCount(1, $result['files']);
        $this->assertSame($second, $result['files'][0]['path']);
    }

    public function testMissingFileReportsErrorAndOtherFilesContinue(): void
    {
        $this->lockFree();
        $this->configuredFiles[] = ['path' => '/nonexistent/dir/access.log', 'store_code' => 'default'];
        $this->logFile(self::line('/a', self::BOT_UA));

        $result = $this->importer()->run();

        $this->assertStringContainsString('does not exist', $result['files'][0]['error']);
        $this->assertSame('', $result['files'][1]['error']);
        $this->assertSame(1, $result['files'][1]['hits']);
        // The missing file only gets its error saved (for the health check); the other file its position.
        $this->assertCount(2, $this->saved);
        $this->assertStringContainsString('does not exist', (string)$this->saved[0]['last_error']);
        $this->assertSame(0, $this->saved[0]['byte_offset']);
        $this->assertNull($this->saved[1]['last_error']);
    }

    public function testUnknownStoreCodeIsReported(): void
    {
        $this->lockFree();
        $this->logFile(self::line('/a', self::BOT_UA), 'missing');

        $file = $this->importer()->run()['files'][0];

        $this->assertStringContainsString('Unknown store code "missing"', $file['error']);
        $this->assertSame([], $this->writes);
        // Only the error is saved, so the health check can name the cause; no position is stored.
        $this->assertCount(1, $this->saved);
        $this->assertStringContainsString('Unknown store code "missing"', (string)$this->saved[0]['last_error']);
        $this->assertSame(0, $this->saved[0]['byte_offset']);
    }

    public function testEmptyFileSavesPosition(): void
    {
        $this->lockFree();
        $this->logFile('');

        $file = $this->importer()->run()['files'][0];

        $this->assertSame(0, $file['lines']);
        $this->assertSame(0, $file['end']);
        $this->assertCount(1, $this->saved);
    }

    public function testWriterFailureKeepsPositionOfLastHandledLine(): void
    {
        $this->lockFree();
        $hitWriter = $this->createStub(HitWriterInterface::class);
        $calls = 0;
        $hitWriter->method('recordHit')->willReturnCallback(function () use (&$calls): void {
            if (++$calls === 2) {
                throw new \RuntimeException('database gone');
            }
        });
        $this->hitWriter = $hitWriter;
        $first = self::line('/a', self::BOT_UA);
        $this->logFile($first . self::line('/b', self::BOT_UA) . self::line('/c', self::BOT_UA));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning');

        $file = $this->importer()->run()['files'][0];

        $this->assertStringContainsString('database gone', $file['error']);
        $this->assertSame(strlen($first), $file['end']);
        $this->assertSame(strlen($first), end($this->saved)['byte_offset']);
    }

    public function testDiscoveryDisabledRecordsNoCandidates(): void
    {
        $this->lockFree();
        $this->discovery = false;
        $this->logFile(self::line('/a', self::CANDIDATE_UA) . self::line('/llms.txt', 'curl/8.0'));

        $file = $this->importer()->run()['files'][0];

        $this->assertSame(0, $file['candidates']);
        $this->assertSame([], $this->writes);
    }

    public function testFutureAndOversizedLinesAreMalformed(): void
    {
        $this->lockFree();
        $future = gmdate('d/M/Y:H:i:s', time() + 5 * 86400) . ' +0000';
        $this->logFile(
            self::line('/a', self::BOT_UA, 200, $future)
            . self::line('/b', str_repeat('q', FileTailer::MAX_LINE_BYTES + 10))
            . self::line('/c', self::BOT_UA)
        );

        $file = $this->importer()->run()['files'][0];

        $this->assertSame(3, $file['lines']);
        $this->assertSame(2, $file['malformed']);
        $this->assertSame(1, $file['hits']);
    }

    /**
     * Paths and whether they are skipped
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function skipPaths(): array
    {
        return [
            'static' => ['/static/version1/frontend/a.js', true],
            'pub static' => ['/pub/static/a.css', true],
            'media image' => ['/media/catalog/a.jpg', true],
            'media sitemap' => ['/media/sitemap.xml', false],
            'media nested sitemap' => ['/media/sitemap/sitemap-1-2.xml', false],
            'media sitemap gz' => ['/media/Sitemap_index.xml.gz', false],
            'sitemap name under static' => ['/static/sitemap.xml', false],
            'nested collector' => ['/en/panth_aiv/collect', true],
            'collector' => ['/panth_aiv/collect/index', true],
            'page' => ['/gear/tent.html', false],
            'root sitemap' => ['/sitemap.xml', false],
            'staticpage lookalike' => ['/staticpage', false],
        ];
    }

    /**
     * @dataProvider skipPaths
     * @param string $path
     * @param bool $skipped
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('skipPaths')]
    public function testIsSkippedPath(string $path, bool $skipped): void
    {
        $this->assertSame($skipped, $this->importer()->isSkippedPath($path));
    }
}
