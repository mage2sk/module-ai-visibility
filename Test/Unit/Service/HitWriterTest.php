<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Model\BotMatch;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\HitWriter;
use Panth\AiVisibility\Service\PageTypeResolver;
use PHPUnit\Framework\TestCase;

class HitWriterTest extends TestCase
{
    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $queries = [];

    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $inserts = [];

    /**
     * @var int
     */
    private int $counter = 0;

    /**
     * @var array|false
     */
    private $candidateRow = ['candidate_id' => '42', 'status' => 'new'];

    private function writer(int $cap = 1000): HitWriter
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(fn ($name) => '`' . $name . '`');
        $connection->method('query')->willReturnCallback(function ($sql, $bind = []) {
            $this->queries[] = [(string)$sql, (array)$bind];
            if (str_contains((string)$sql, 'panth_aiv_counter')) {
                $this->counter++;
            }
            return null;
        });
        $connection->method('fetchOne')->willReturnCallback(fn () => (string)$this->counter);
        $connection->method('fetchRow')->willReturnCallback(function ($sql, $bind = []) {
            $this->queries[] = [(string)$sql, (array)$bind];
            return $this->candidateRow;
        });
        $connection->method('insert')->willReturnCallback(function ($table, array $data) {
            $this->inserts[] = [(string)$table, $data];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(fn ($name) => 'pfx_' . $name);
        $config = $this->createStub(Config::class);
        $config->method('rawDailyCap')->willReturn($cap);
        return new HitWriter($resource, $config, new PageTypeResolver());
    }

    private function bot(string $category = 'training'): BotMatch
    {
        return new BotMatch('gptbot', 'GPTBot', 'OpenAI', $category);
    }

    private function queriesOn(string $table): array
    {
        return array_values(array_filter($this->queries, fn ($q) => str_contains($q[0], '`pfx_' . $table . '`')));
    }

    public function testRecordHitWritesARawRow(): void
    {
        $ts = gmmktime(23, 59, 30, 3, 14, 2026);
        $this->writer()->recordHit(1, $this->bot(), '/joust-duffle-bag.html', true, 200, 123, 'php', $ts);

        $this->assertCount(1, $this->inserts);
        [$table, $row] = $this->inserts[0];
        $this->assertSame('pfx_panth_aiv_hit', $table);
        $this->assertSame([
            'created_at' => '2026-03-14 23:59:30',
            'store_id' => 1,
            'bot_code' => 'gptbot',
            'category' => 'training',
            'path' => '/joust-duffle-bag.html',
            'path_hash' => sha1('/joust-duffle-bag.html'),
            'has_query' => 1,
            'status' => 200,
            'duration_ms' => 123,
            'origin' => 'php',
            'is_simulated' => 0,
        ], $row);

        $counter = $this->queriesOn('panth_aiv_counter');
        $this->assertCount(1, $counter);
        $this->assertStringContainsString('LAST_INSERT_ID(value + 1)', $counter[0][0]);
        $this->assertSame(['raw_hits', '2026-03-14'], $counter[0][1], 'Counter is per UTC day');
        $this->assertSame([], $this->queriesOn('panth_aiv_overflow_daily'));
    }

    public function testValuesAreSanitised(): void
    {
        $writer = $this->writer();
        $writer->recordHit(70000, $this->bot('weird'), 'no-slash?x=1', false, 42, -5, 'bogus', 0);
        $row = $this->inserts[0][1];
        $this->assertSame(65535, $row['store_id']);
        $this->assertSame('other', $row['category']);
        $this->assertSame('/no-slash', $row['path']);
        $this->assertSame(0, $row['status'], 'Implausible status becomes 0');
        $this->assertSame(0, $row['duration_ms']);
        $this->assertSame('php', $row['origin']);
        $this->assertSame('1970-01-01 00:00:00', $row['created_at']);
    }

    public function testLongPathIsCutTo512Characters(): void
    {
        $this->writer()->recordHit(1, $this->bot(), '/' . str_repeat('é', 600), false, 200, 1, 'log', 100);
        $row = $this->inserts[0][1];
        $this->assertSame(512, mb_strlen($row['path'], 'UTF-8'));
        $this->assertSame(sha1($row['path']), $row['path_hash']);
        $this->assertSame('log', $row['origin']);
    }

    public function testSimulatedOriginFlagsTheRow(): void
    {
        $this->writer()->recordHit(1, $this->bot(), '/', false, 200, 1, 'sim', 100);
        $this->assertSame(1, $this->inserts[0][1]['is_simulated']);
        $this->assertSame('sim', $this->inserts[0][1]['origin']);
    }

    public function testHitsAboveTheCapGoToTheOverflowTable(): void
    {
        $writer = $this->writer(2);
        $ts = gmmktime(10, 0, 0, 5, 1, 2026);
        $writer->recordHit(1, $this->bot(), '/a', false, 200, 1, 'php', $ts);
        $writer->recordHit(1, $this->bot(), '/b', false, 200, 1, 'php', $ts);
        $writer->recordHit(1, $this->bot(), '/c', false, 404, 1, 'php', $ts);
        $writer->recordHit(1, $this->bot(), '/d', false, 0, 1, 'php', $ts);

        $this->assertCount(2, $this->inserts, 'Only the first two hits are raw rows');
        $overflow = $this->queriesOn('panth_aiv_overflow_daily');
        $this->assertCount(2, $overflow);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE hits = hits + 1', $overflow[0][0]);
        $this->assertSame(['2026-05-01', 1, 'gptbot', 'training', 4, 0, 'training'], $overflow[0][1]);
        $this->assertSame(0, $overflow[1][1][4], 'Unknown status has status class 0');
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function statusClassProvider(): array
    {
        return [
            'unknown' => [0, 0],
            'informational' => [101, 0],
            'ok' => [200, 2],
            'redirect' => [301, 3],
            'not found' => [404, 4],
            'error' => [503, 5],
            'out of range' => [600, 0],
            'negative' => [-200, 0],
        ];
    }

    /**
     * @dataProvider statusClassProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('statusClassProvider')]
    public function testStatusClass(int $status, int $class): void
    {
        $this->assertSame($class, HitWriter::statusClass($status));
    }

    public function testCandidateHitUpsertsTheCandidateAndWritesARawRow(): void
    {
        $ua = 'SimAgent/1.0 (+https://example.invalid/bot)';
        $ts = gmmktime(8, 0, 0, 6, 2, 2026);
        $this->writer()->recordCandidateHit(2, $ua, '/llms.txt', false, 200, 15, 'php', $ts);

        $upsert = $this->queriesOn('panth_aiv_candidate')[0];
        $this->assertStringContainsString('INSERT INTO `pfx_panth_aiv_candidate`', $upsert[0]);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE hits = hits + 1', $upsert[0]);
        $this->assertSame(
            ['bot', sha1(strtolower($ua)), $ua, '2026-06-02 08:00:00', '2026-06-02 08:00:00', 1, 0, 'new', 0,
                1, 0, '2026-06-02 08:00:00', '2026-06-02 08:00:00'],
            $upsert[1],
            'llms.txt fetch counts as an llms fetch'
        );
        $select = $this->queriesOn('panth_aiv_candidate')[1];
        $this->assertStringStartsWith('SELECT candidate_id, status', $select[0]);
        $this->assertSame(['bot', sha1(strtolower($ua))], $select[1]);

        $row = $this->inserts[0][1];
        $this->assertSame('cand:42', $row['bot_code']);
        $this->assertSame('unknown', $row['category']);
        $this->assertSame(2, $row['store_id']);
        $this->assertSame('/llms.txt', $row['path']);
    }

    public function testRobotsFetchIsCounted(): void
    {
        $this->writer()->recordCandidateHit(1, 'NewBot/1', '/robots.txt', false, 200, 1, 'php', 100);
        $bind = $this->queriesOn('panth_aiv_candidate')[0][1];
        $this->assertSame([0, 1], [$bind[5], $bind[6]]);
        $this->assertSame([0, 1], [$bind[9], $bind[10]]);
    }

    public function testCandidateKeyIsCaseInsensitiveAndCut(): void
    {
        $ua = 'NewBot/1 ' . str_repeat('Z', 700);
        $this->writer()->recordCandidateHit(1, $ua, '/', false, 200, 1, 'php', 100);
        $bind = $this->queriesOn('panth_aiv_candidate')[0][1];
        $this->assertSame(sha1(strtolower($ua)), $bind[1]);
        $this->assertSame(512, strlen($bind[2]));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function candidateStatusProvider(): array
    {
        return [
            'new' => ['new', true],
            'confirmed' => ['confirmed', true],
            'not ai' => ['not_ai', false],
            'ignored' => ['ignored', false],
        ];
    }

    /**
     * @dataProvider candidateStatusProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('candidateStatusProvider')]
    public function testCandidateStatusDecidesTheRawRow(string $status, bool $written): void
    {
        $this->candidateRow = ['candidate_id' => '7', 'status' => $status];
        $this->writer()->recordCandidateHit(1, 'NewBot/1', '/x', false, 200, 1, 'php', 100);
        $this->assertCount(2, $this->queriesOn('panth_aiv_candidate'), 'Candidate is always upserted and read');
        $this->assertCount($written ? 1 : 0, $this->inserts);
        $this->assertCount($written ? 1 : 0, $this->queriesOn('panth_aiv_counter'), 'Skipped hits use no cap');
    }

    public function testCandidateAboveTheCapGoesToOverflow(): void
    {
        $writer = $this->writer(1);
        $writer->recordCandidateHit(1, 'NewBot/1', '/x', false, 200, 1, 'php', 100);
        $writer->recordCandidateHit(1, 'NewBot/1', '/y', false, 500, 1, 'php', 100);
        $this->assertCount(1, $this->inserts);
        $overflow = $this->queriesOn('panth_aiv_overflow_daily');
        $this->assertCount(1, $overflow);
        // All candidates share one overflow row per day ("cand:other"), so unknown User-Agents cannot grow it.
        $this->assertSame(['1970-01-01', 1, 'cand:other', 'unknown', 5, 0, 'unknown'], $overflow[0][1]);
    }

    public function testMissingCandidateRowWritesNothing(): void
    {
        $this->candidateRow = false;
        $this->writer()->recordCandidateHit(1, 'NewBot/1', '/x', false, 200, 1);
        $this->assertSame([], $this->inserts);
    }

    public function testEmptyUserAgentIsIgnored(): void
    {
        $this->writer()->recordCandidateHit(1, '  ', '/x', false, 200, 1);
        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->inserts);
    }

    public function testDefaultTimestampIsNow(): void
    {
        $before = time();
        $this->writer()->recordHit(1, $this->bot(), '/', false, 200, 1);
        $written = strtotime($this->inserts[0][1]['created_at'] . ' UTC');
        $this->assertGreaterThanOrEqual($before, $written);
        $this->assertLessThanOrEqual(time(), $written);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cleanPathProvider(): array
    {
        return [
            'empty' => ['', '/'],
            'query' => ['/a?b=c', '/a'],
            'fragment' => ['/a#top', '/a'],
            'no slash' => ['a/b', '/a/b'],
            'control chars' => ["/a\x00b\nc", '/abc'],
            'invalid utf8 is percent-encoded' => ["/a\xC3(", '/a%C3('],
        ];
    }

    /**
     * @dataProvider cleanPathProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cleanPathProvider')]
    public function testCleanPath(string $path, string $expected): void
    {
        $this->assertSame($expected, HitWriter::cleanPath($path));
    }

    public function testDatabaseErrorsAreThrown(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->method('query')->willThrowException(new \RuntimeException('db down'));
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $writer = new HitWriter($resource, $this->createStub(Config::class), new PageTypeResolver());
        $this->expectException(\RuntimeException::class);
        $writer->recordHit(1, $this->bot(), '/', false, 200, 1);
    }
}
