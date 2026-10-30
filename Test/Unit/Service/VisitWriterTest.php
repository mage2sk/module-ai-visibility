<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AiVisibility\Service\VisitWriter;
use PHPUnit\Framework\TestCase;

class VisitWriterTest extends TestCase
{
    private const NOW = 1790000000;

    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $inserts = [];

    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $queries = [];

    /**
     * @var array<int, array>
     */
    private array $selectWheres = [];

    private function writer($candidateRow = false): VisitWriter
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value) use (&$select) {
            $this->selectWheres[] = [$cond, $value];
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insert')->willReturnCallback(function (string $table, array $row) {
            $this->inserts[] = [$table, $row];
            return 1;
        });
        $connection->method('quoteIdentifier')->willReturnCallback(fn ($v) => '`' . $v . '`');
        $connection->method('query')->willReturnCallback(function (string $sql, array $bind) {
            $this->queries[] = [$sql, $bind];
            return $this->createStub(\Zend_Db_Statement_Interface::class);
        });
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($candidateRow);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(fn ($t) => 'pfx_' . $t);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);
        return new VisitWriter($resource, $dateTime);
    }

    public function testInsertVisit(): void
    {
        $this->writer()->insertVisit(2, 'chatgpt', 'referrer', 'chatgpt.com', '/p.html');
        $this->assertSame([[
            'pfx_panth_aiv_visit',
            [
                'created_at' => gmdate('Y-m-d H:i:s', self::NOW),
                'store_id' => 2,
                'source_code' => 'chatgpt',
                'medium' => 'referrer',
                'referrer_host' => 'chatgpt.com',
                'landing_path' => '/p.html',
                'path_hash' => sha1('/p.html'),
            ],
        ]], $this->inserts);
    }

    public function testInsertVisitNormalisesMediumAndEmptyHost(): void
    {
        $writer = $this->writer();
        $writer->insertVisit(1, 'claude', 'utm', '', '/');
        $writer->insertVisit(1, 'claude', 'bogus', null, '/');
        $this->assertSame('utm', $this->inserts[0][1]['medium']);
        $this->assertNull($this->inserts[0][1]['referrer_host']);
        $this->assertSame('referrer', $this->inserts[1][1]['medium']);
    }

    public function testInsertLanding(): void
    {
        $this->writer()->insertLanding(1, '/deep/page.html');
        $this->assertSame([[
            'pfx_panth_aiv_landing',
            [
                'created_at' => gmdate('Y-m-d H:i:s', self::NOW),
                'store_id' => 1,
                'path' => '/deep/page.html',
                'path_hash' => sha1('/deep/page.html'),
            ],
        ]], $this->inserts);
    }

    public function testUpsertCandidateReturnsId(): void
    {
        $id = $this->writer(['candidate_id' => '42', 'status' => 'new'])->upsertSourceCandidate('host:assistant.shop.test');
        $this->assertSame(42, $id);
        [$sql, $bind] = $this->queries[0];
        $this->assertStringContainsString('INSERT INTO `pfx_panth_aiv_candidate`', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE hits = hits + 1', $sql);
        $now = gmdate('Y-m-d H:i:s', self::NOW);
        $this->assertSame(
            ['source', sha1('host:assistant.shop.test'), 'host:assistant.shop.test', $now, $now, $now],
            $bind
        );
        $this->assertSame([['type = ?', 'source'], ['key_hash = ?', sha1('host:assistant.shop.test')]], $this->selectWheres);
    }

    public function testCandidateKeysAreCaseAndWwwInsensitive(): void
    {
        $this->assertSame('host:assistant.shop.test', VisitWriter::hostCandidateKey(' WWW.Assistant.Shop.Test '));
        $this->assertSame('host:assistant.shop.test', VisitWriter::hostCandidateKey('assistant.shop.test'));
        $this->assertSame('host:wwwai.shop.test', VisitWriter::hostCandidateKey('wwwai.shop.test'));
        $this->assertSame('utm:gpt_helper', VisitWriter::utmCandidateKey('GPT_Helper'));
        $this->assertSame(sha1('host:assistant.shop.test'), VisitWriter::candidateKeyHash('HOST:Assistant.Shop.Test'));
    }

    public function testUpsertStoresTheNormalisedKeyAndHash(): void
    {
        $this->writer(['candidate_id' => '5', 'status' => 'new'])->upsertSourceCandidate('host:Assistant.Shop.Test');
        [, $bind] = $this->queries[0];
        $this->assertSame(VisitWriter::candidateKeyHash(VisitWriter::hostCandidateKey('www.assistant.shop.test')), $bind[1]);
        $this->assertSame('host:assistant.shop.test', $bind[2]);
    }

    public function testConfirmedCandidateStillReturnsId(): void
    {
        $this->assertSame(7, $this->writer(['candidate_id' => 7, 'status' => 'confirmed'])->upsertSourceCandidate('utm:gpt'));
    }

    public function testIgnoredOrNotAiCandidateIsSkipped(): void
    {
        $this->assertNull($this->writer(['candidate_id' => 7, 'status' => 'not_ai'])->upsertSourceCandidate('utm:gpt'));
        $this->assertNull($this->writer(['candidate_id' => 7, 'status' => 'ignored'])->upsertSourceCandidate('utm:gpt'));
        $this->assertNull($this->writer(false)->upsertSourceCandidate('utm:gpt'));
    }
}
