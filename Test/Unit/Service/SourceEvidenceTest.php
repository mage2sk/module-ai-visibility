<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Service\SourceEvidence;
use PHPUnit\Framework\TestCase;

class SourceEvidenceTest extends TestCase
{
    public function testEvidenceIsComputedInOneJoinStatement(): void
    {
        $queries = [];
        $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
        $statement->method('rowCount')->willReturn(3);
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($name): string => '`' . $name . '`');
        $connection->method('query')->willReturnCallback(
            function (string $sql, array $bind) use (&$queries, $statement) {
                $queries[] = [$sql, $bind];
                return $statement;
            }
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $now = gmmktime(12, 0, 0, 10, 30, 2026);
        $this->assertSame(3, (new SourceEvidence($resource))->update($now));

        $this->assertCount(1, $queries);
        [$sql, $bind] = $queries[0];
        $this->assertStringStartsWith('UPDATE `panth_aiv_candidate` AS c LEFT JOIN', $sql);
        $this->assertStringContainsString('EXISTS (SELECT 1 FROM `panth_aiv_hit` AS h', $sql);
        $this->assertStringContainsString('h.store_id = v.store_id', $sql);
        $this->assertStringContainsString('h.is_simulated = v.is_simulated', $sql);
        $this->assertStringContainsString('h.created_at >= v.created_at - INTERVAL 10 MINUTE', $sql);
        $this->assertStringContainsString('h.created_at <= v.created_at', $sql);
        $this->assertStringContainsString('SET c.evidence = COALESCE(e.evidence, 0)', $sql);
        $this->assertSame(substr_count($sql, '?'), count($bind));
        $this->assertSame(['2026-10-23 12:00:00', 'cand:%', 'live', 'cand:', 'source', 'new'], $bind);
    }
}
