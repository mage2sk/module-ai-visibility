<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Api\HitWriterInterface;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Model\BotMatch;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Observer\TrackRequest;
use Panth\AiVisibility\Service\Health\LogImportCheck;
use Panth\AiVisibility\Service\HitWriter;
use Panth\AiVisibility\Service\LogImport\CombinedLogParser;
use Panth\AiVisibility\Service\LogImport\FileTailer;
use Panth\AiVisibility\Service\LogImport\LogImporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep test D7 (access log importer).
 *
 * Real parser, tailer, health check and temp files; the database, classifier and hit writer are stubs.
 * Tests whose name ends in "Fixed" prove the fix of a defect found in the deep test (they failed on the first build).
 */
class LogImportDeepTest extends TestCase
{
    private const GPT_UA = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://example.invalid/bot)';
    private const HUMAN_UA = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0';

    /**
     * import_state rows by file_key, as the stubbed table holds them
     *
     * @var array<string, array<string, mixed>>
     */
    private array $state = [];

    /**
     * Hit writer calls: [method, path, user agent or bot code, timestamp]
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: int|null}>
     */
    private array $writes = [];

    /**
     * @var array<int, array{path: string, store_code: string}>
     */
    private array $configuredFiles = [];

    /**
     * Temp files and folders to remove
     *
     * @var string[]
     */
    private array $cleanup = [];

    /**
     * Called with the path before every write; may throw to simulate a database error
     *
     * @var \Closure|null
     */
    private ?\Closure $writerHook = null;

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $path) {
            if (is_dir($path)) {
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Importer over the stubbed database; GPTBot is a known bot, "crawler" UAs are candidates
     *
     * @return LogImporter
     */
    private function importer(): LogImporter
    {
        $config = $this->createStub(Config::class);
        $config->method('logImportEnabled')->willReturn(true);
        $config->method('logImportMaxLines')->willReturn(1000);
        $config->method('isEnabled')->willReturn(true);
        $config->method('trackCrawlers')->willReturn(true);
        $config->method('discoveryEnabled')->willReturn(true);
        $config->method('logImportFiles')->willReturnCallback(fn (): array => $this->configuredFiles);

        $hitWriter = $this->createStub(HitWriterInterface::class);
        $hitWriter->method('recordHit')->willReturnCallback(function (...$args): void {
            if ($this->writerHook !== null) {
                ($this->writerHook)($args[2]);
            }
            $this->writes[] = ['hit', $args[2], $args[1]->code, $args[7] ?? null];
        });
        $hitWriter->method('recordCandidateHit')->willReturnCallback(function (...$args): void {
            if ($this->writerHook !== null) {
                ($this->writerHook)($args[2]);
            }
            $this->writes[] = ['candidate', $args[2], $args[1], $args[7] ?? null];
        });

        $classifier = $this->createStub(BotClassifierInterface::class);
        $classifier->method('classify')->willReturnCallback(static function (string $ua): ?BotMatch {
            return stripos($ua, 'GPTBot') !== false ? new BotMatch('gptbot', 'GPTBot', 'OpenAI', 'training') : null;
        });
        $classifier->method('isDiscoveryCandidate')->willReturnCallback(static function (string $ua): bool {
            return stripos($ua, 'crawler') !== false;
        });
        $resolver = $this->createStub(PageTypeResolverInterface::class);
        $resolver->method('resolve')->willReturn(PageTypeResolverInterface::TYPE_PAGE);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function ($code) {
            if ($code !== 'default') {
                throw new NoSuchEntityException(new Phrase('The store that was requested wasn\'t found.'));
            }
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn(1);
            return $store;
        });
        $lock = $this->createStub(LockManagerInterface::class);
        $lock->method('lock')->willReturn(true);
        $lock->method('unlock')->willReturn(true);

        return new LogImporter(
            $config,
            $classifier,
            $hitWriter,
            $resolver,
            new CombinedLogParser(),
            new FileTailer(new File()),
            $this->resource(),
            $storeManager,
            $lock,
            $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * Health check that reads the same stubbed import_state table and the real file system
     *
     * @return LogImportCheck
     */
    private function healthCheck(): LogImportCheck
    {
        $config = $this->createStub(Config::class);
        $config->method('logImportEnabled')->willReturn(true);
        $config->method('logImportFiles')->willReturnCallback(fn (): array => $this->configuredFiles);
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturnCallback(static fn (): int => time());
        return new LogImportCheck($config, $this->resource(), new File(), $clock);
    }

    /**
     * Resource whose adapter keeps import_state rows in $this->state
     *
     * @return ResourceConnection
     */
    private function resource(): ResourceConnection
    {
        $key = '';
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select, &$key) {
            $key = is_array($value) ? '' : (string)$value;
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturnCallback(function () use (&$key) {
            return $this->state[$key] ?? false;
        });
        $connection->method('fetchPairs')->willReturnCallback(function (): array {
            return array_map(static fn (array $row): string => (string)$row['updated_at'], $this->state);
        });
        $connection->method('insertOnDuplicate')->willReturnCallback(function ($table, array $row): int {
            $this->state[$row['file_key']] = $row;
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    /**
     * Create a temp log file and configure it
     *
     * @param string $content
     * @param string $storeCode
     * @return string
     */
    private function logFile(string $content, string $storeCode = 'default'): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'aivd7');
        file_put_contents($path, $content);
        $this->cleanup[] = $path;
        $this->configuredFiles[] = ['path' => $path, 'store_code' => $storeCode];
        return $path;
    }

    /**
     * One combined log line with a line break
     *
     * @param string $uri
     * @param string $ua
     * @param string $time
     * @return string
     */
    private static function line(string $uri, string $ua = self::GPT_UA, string $time = '29/Oct/2026:10:00:00 +0000'): string
    {
        return '203.0.113.9 - - [' . $time . '] "GET ' . $uri . ' HTTP/1.1" 200 512 "-" "' . $ua . "\"\n";
    }

    /**
     * Paths passed to the hit writer
     *
     * @return string[]
     */
    private function writtenPaths(): array
    {
        return array_column($this->writes, 1);
    }

    /**
     * Fixed (major); on the first build one line whose write always fails (for example a 4-byte UTF-8 path or user agent on a
     * utf8mb3 table, the 2.4.7 default: "1366 Incorrect string value") stops every later run at the same
     * byte, so nothing after it is ever imported again.
     */
    public function testLineThatAlwaysFailsDoesNotStallTheImportForeverFixed(): void
    {
        $this->writerHook = static function (string $path): void {
            if ($path === '/poison') {
                throw new \RuntimeException('SQLSTATE[HY000]: General error: 1366 Incorrect string value');
            }
        };
        $this->logFile(self::line('/before') . self::line('/poison') . self::line('/after'));

        $errors = [];
        for ($run = 1; $run <= 3; $run++) {
            $errors[] = $this->importer()->run()['files'][0]['error'];
        }

        $this->assertStringContainsString('Incorrect string value', $errors[0]);
        $this->assertContains(
            '/after',
            $this->writtenPaths(),
            'After 3 runs the line after the failing one was never imported; errors: ' . implode(' | ', $errors)
        );
    }

    /**
     * Fixed (major); on the first build while every run stops with an error the health check stays OK, because a failed run
     * still refreshes import_state.updated_at and the check only looks at that time.
     */
    public function testHealthIsNotOkWhileEveryRunStopsWithAnErrorFixed(): void
    {
        $this->writerHook = static function (string $path): void {
            if ($path === '/poison') {
                throw new \RuntimeException('SQLSTATE[HY000]: General error: 1366 Incorrect string value');
            }
        };
        $this->logFile(self::line('/poison') . self::line('/after'));
        $this->importer()->run();
        $file = $this->importer()->run()['files'][0];
        $this->assertNotSame('', $file['error']);

        $result = $this->healthCheck()->run();

        $this->assertNotSame(
            HealthResult::STATUS_OK,
            $result->status,
            'Health says "' . $result->message . '" although the last run failed: ' . $file['error']
        );
    }

    /**
     * Fixed (minor); on the first build an unknown store code makes the importer skip the file, but the health check only says
     * "never read ... make sure Magento cron runs" and does not name the real cause.
     */
    public function testHealthNamesAnUnknownStoreCodeFixed(): void
    {
        $this->logFile(self::line('/a'), 'nosuchstore');
        $file = $this->importer()->run()['files'][0];
        $this->assertStringContainsString('Unknown store code "nosuchstore"', $file['error']);

        $result = $this->healthCheck()->run();

        $this->assertSame(HealthResult::STATUS_ERROR, $result->status);
        $this->assertStringContainsString('nosuchstore', $result->message, $result->message);
    }

    /**
     * Fixed (minor); on the first build a folder configured as a log file is reported as readable by the health check (the
     * importer rejects it as "not a regular file"), so the message points at cron instead of the path.
     */
    public function testHealthDoesNotCallAFolderReadableFixed(): void
    {
        $dir = sys_get_temp_dir() . '/aivd7dir' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->cleanup[] = $dir;
        $this->configuredFiles[] = ['path' => $dir, 'store_code' => 'default'];
        $file = $this->importer()->run()['files'][0];
        $this->assertStringContainsString('not a regular file', $file['error']);

        $result = $this->healthCheck()->run();

        $this->assertFalse($result->details['files'][0]['readable'], $result->message);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function webApiPaths(): array
    {
        return [
            'REST' => ['/rest/V1/products'],
            'REST with store code' => ['/rest/default/V1/products/sku'],
            'GraphQL' => ['/graphql'],
            'SOAP' => ['/soap/default'],
        ];
    }

    /**
     * Fixed (minor); on the first build PHP tracking never counts web API requests (they are not the frontend area), but the
     * log import counts them as page hits, so the two sources disagree for the same traffic.
     *
     * @dataProvider webApiPaths
     * @param string $path
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('webApiPaths')]
    public function testWebApiRequestsAreSkippedLikeInPhpTrackingFixed(string $path): void
    {
        $this->logFile(self::line($path));

        $file = $this->importer()->run()['files'][0];

        $this->assertSame(0, $file['hits'], $path . ' was imported as an AI crawler page hit');
    }

    public function testCrlfFileImportsExactlyLikeLfFile(): void
    {
        $lines = self::line('/a?x=1') . self::line('/b', 'NewCrawler/1.0') . self::line('/c', self::HUMAN_UA);
        $this->logFile(str_replace("\n", "\r\n", $lines));
        $crlf = $this->importer()->run()['files'][0];
        $crlfWrites = $this->writes;

        $this->writes = [];
        $this->configuredFiles = [];
        $this->logFile($lines);
        $lf = $this->importer()->run()['files'][0];

        $this->assertSame($this->writes, $crlfWrites);
        $this->assertSame([$lf['hits'], $lf['candidates'], $lf['malformed']], [$crlf['hits'], $crlf['candidates'], 0]);
        $this->assertSame('NewCrawler/1.0', $crlfWrites[1][2], 'no carriage return may stay in the user agent');
    }

    public function testClockSkewBoundaryIsOneDay(): void
    {
        $soon = gmdate('d/M/Y:H:i:s', time() + 86400 - 600) . ' +0000';
        $late = gmdate('d/M/Y:H:i:s', time() + 86400 + 600) . ' +0000';
        $this->logFile(self::line('/soon', self::GPT_UA, $soon) . self::line('/late', self::GPT_UA, $late));

        $file = $this->importer()->run()['files'][0];

        $this->assertSame(['/soon'], $this->writtenPaths());
        $this->assertSame(1, $file['malformed']);
    }

    public function testTimeZoneOffsetsAreConvertedToUtc(): void
    {
        $this->logFile(
            self::line('/plus', self::GPT_UA, '29/Oct/2026:15:45:30 +0530')
            . self::line('/minus', self::GPT_UA, '29/Oct/2026:03:00:00 -0700')
            . self::line('/leap', self::GPT_UA, '29/Oct/2026:10:20:60 +0000')
        );

        $this->importer()->run();

        $this->assertSame(
            [gmmktime(10, 15, 30, 10, 29, 2026), gmmktime(10, 0, 0, 10, 29, 2026), gmmktime(10, 20, 59, 10, 29, 2026)],
            array_column($this->writes, 3)
        );
    }

    public function testFileGrowingDuringTheRunLosesAndDuplicatesNothing(): void
    {
        $path = $this->logFile(self::line('/g0'));
        $tailer = new FileTailer(new File());
        $seen = [];
        $end = 0;
        foreach ($tailer->lines($path, 0) as $item) {
            $seen[] = $item['line'];
            $end = $item['end'];
            if (count($seen) === 1) {
                // The web server keeps writing while the import runs, the last line is still incomplete.
                file_put_contents($path, self::line('/g1') . substr(self::line('/g2'), 0, 20), FILE_APPEND);
            }
        }
        foreach ($tailer->lines($path, $end) as $item) {
            $seen[] = $item['line'];
            $end = $item['end'];
        }

        $this->assertSame([rtrim(self::line('/g0')), rtrim(self::line('/g1'))], $seen);
        $this->assertSame(strlen(self::line('/g0') . self::line('/g1')), $end);
    }

    public function testLineOfExactlyTheLimitIsKeptOneMoreByteIsSkipped(): void
    {
        $path = $this->logFile(str_repeat('a', 10) . "\n" . str_repeat('b', 11) . "\nc\n");

        $items = iterator_to_array((new FileTailer(new File()))->lines($path, 0, 10), false);

        $this->assertSame([str_repeat('a', 10), null, 'c'], array_column($items, 'line'));
        $this->assertSame([11, 23, 25], array_column($items, 'end'));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function edgeLines(): array
    {
        $ua = self::GPT_UA;
        $base = '203.0.113.9 - - [29/Oct/2026:10:00:00 +0000] ';
        return [
            'CRLF line' => [$base . '"GET /p HTTP/1.1" 200 1 "-" "' . $ua . "\"\r\n", ['path' => '/p', 'user_agent' => $ua]],
            'IPv6 client' => [
                '2001:db8::1 - - [29/Oct/2026:10:00:00 +0000] "GET /p HTTP/1.1" 200 1 "-" "' . $ua . '"',
                ['ip' => '2001:db8::1'],
            ],
            'HTTP/2 and status 499 with "-" bytes' => [
                $base . '"GET /p HTTP/2" 499 - "-" "' . $ua . '"',
                ['status' => 499, 'path' => '/p'],
            ],
            'upper-case month' => [
                '203.0.113.9 - - [29/OCT/2026:10:00:00 +0000] "GET /p HTTP/1.1" 200 1 "-" "' . $ua . '"',
                ['timestamp' => gmmktime(10, 0, 0, 10, 29, 2026)],
            ],
            'absolute URI (varnishncsa)' => [
                $base . '"GET https://shop.test/p?x=1 HTTP/1.1" 200 1 "-" "' . $ua . '"',
                ['path' => '/p', 'has_query' => true],
            ],
            'absolute URI without a path' => [
                $base . '"GET http://shop.test HTTP/1.1" 200 1 "-" "' . $ua . '"',
                ['path' => '/', 'has_query' => false],
            ],
            'nginx \x22 escape' => [
                $base . '"GET /p HTTP/1.1" 200 1 "-" "Agent \x22q\x22 crawler"',
                ['user_agent' => 'Agent "q" crawler'],
            ],
            'Apache \" escape' => [
                $base . '"GET /p HTTP/1.1" 200 1 "-" "Agent \"q\" crawler"',
                ['user_agent' => 'Agent "q" crawler'],
            ],
            'extra fields after the user agent' => [
                $base . '"GET /p HTTP/1.1" 200 1 "-" "' . $ua . '" 0.123 "-" rt=0.010',
                ['user_agent' => $ua, 'path' => '/p'],
            ],
            'percent-encoded UTF-8 and double slashes' => [
                $base . '"GET //caf%C3%A9//x HTTP/1.1" 200 1 "-" "' . $ua . '"',
                ['path' => '/café/x'],
            ],
            'HEAD request' => [
                $base . '"HEAD /p HTTP/1.1" 200 0 "-" "' . $ua . '"',
                ['method' => 'HEAD'],
            ],
        ];
    }

    /**
     * @dataProvider edgeLines
     * @param string $line
     * @param array<string, mixed> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('edgeLines')]
    public function testParserEdgeCases(string $line, array $expected): void
    {
        $entry = (new CombinedLogParser())->parse($line);

        $this->assertIsArray($entry);
        foreach ($expected as $field => $value) {
            $this->assertSame($value, $entry[$field], $field);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badLines(): array
    {
        $ua = '"-" "' . self::GPT_UA . '"';
        return [
            'lower-case method' => ['203.0.113.9 - - [29/Oct/2026:10:00:00 +0000] "get /p HTTP/1.1" 200 1 ' . $ua],
            'request "-" (nginx 400)' => ['203.0.113.9 - - [29/Oct/2026:10:00:00 +0000] "-" 400 0 "-" "-"'],
            'day 32' => ['203.0.113.9 - - [32/Oct/2026:10:00:00 +0000] "GET /p HTTP/1.1" 200 1 ' . $ua],
            'offset +1500' => ['203.0.113.9 - - [29/Oct/2026:10:00:00 +1500] "GET /p HTTP/1.1" 200 1 ' . $ua],
            'year 1989' => ['203.0.113.9 - - [29/Oct/1989:10:00:00 +0000] "GET /p HTTP/1.1" 200 1 ' . $ua],
            'relative URI' => ['203.0.113.9 - - [29/Oct/2026:10:00:00 +0000] "GET p HTTP/1.1" 200 1 ' . $ua],
            'only spaces' => ['    '],
            'unterminated request' => ['203.0.113.9 - - [29/Oct/2026:10:00:00 +0000] "GET /p HTTP/1.1 200 1 ' . $ua],
        ];
    }

    /**
     * @dataProvider badLines
     * @param string $line
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badLines')]
    public function testParserRejectsBadLines(string $line): void
    {
        $this->assertNull((new CombinedLogParser())->parse($line));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function parityUris(): array
    {
        return [
            'encoded UTF-8' => ['/caf%C3%A9.html', '/caf%C3%A9.html'],
            '4-byte UTF-8' => ['/emoji-%F0%9F%98%80', '/emoji-%F0%9F%98%80'],
            'invalid percent-escape kept' => ['/bad%FF%FE', '/bad%FF%FE'],
            'raw byte logged by nginx as \xFF' => ['/raw\xFF', "/raw\xFF"],
            'encoded space and query' => ['/sp%20ace?q=1', '/sp%20ace?q=1'],
            'double slashes' => ['//a//b/', '//a//b/'],
            'encoded slash' => ['/a%2Fb', '/a%2Fb'],
            'encoded NUL kept encoded' => ['/a%00b', '/a%00b'],
            'empty query' => ['/p?', '/p?'],
        ];
    }

    /**
     * @dataProvider parityUris
     * @param string $loggedUri The URI as it appears in the access log line
     * @param string $requestUri REQUEST_URI as PHP sees it
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parityUris')]
    public function testLogAndPhpGiveTheSameStoredPathAndHash(string $loggedUri, string $requestUri): void
    {
        $entry = (new CombinedLogParser())->parse(rtrim(self::line($loggedUri)));
        $this->assertIsArray($entry);
        [$phpPath, $phpQuery] = TrackRequest::splitUri($requestUri);

        $this->assertSame(sha1(HitWriter::cleanPath($phpPath)), sha1(HitWriter::cleanPath($entry['path'])));
        $this->assertSame($phpQuery, $entry['has_query']);
    }
}
