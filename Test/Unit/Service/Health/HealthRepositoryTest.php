<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Health;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Service\Health\HealthRepository;
use PHPUnit\Framework\TestCase;

class HealthRepositoryTest extends TestCase
{
    private array $inserts = [];

    private function repository(array $rows = []): HealthRepository
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturn($select);
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);
        $connection->method('insertOnDuplicate')->willReturnCallback(function (...$args): int {
            $this->inserts[] = $args;
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new HealthRepository($resource, new Json());
    }

    public function testSaveUpsertsRowWithJsonDetailsAndCutMessage(): void
    {
        $this->repository()->save('cron', new HealthResult('ok', str_repeat('x', 600), ['a' => 1]));

        $this->assertCount(1, $this->inserts);
        [$table, $row, $update] = $this->inserts[0];
        $this->assertSame('panth_aiv_health', $table);
        $this->assertSame('cron', $row['check_code']);
        $this->assertSame('ok', $row['status']);
        $this->assertSame(512, mb_strlen($row['message']));
        $this->assertSame('{"a":1}', $row['details']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['checked_at']);
        $this->assertSame(['status', 'message', 'details', 'checked_at'], $update);
    }

    public function testSaveNormalisesStatusAndSkipsEmptyCode(): void
    {
        $repository = $this->repository();
        $repository->save('x', new HealthResult('weird', 'm'));
        $repository->save('  ', new HealthResult('ok', 'm'));

        $this->assertCount(1, $this->inserts);
        $this->assertSame(HealthResult::STATUS_UNKNOWN, $this->inserts[0][1]['status']);
        $this->assertNull($this->inserts[0][1]['details']);
    }

    public function testLatestDecodesAndOrdersRows(): void
    {
        $rows = [
            ['check_code' => 'zzz_custom', 'status' => 'ok', 'message' => 'm1', 'details' => null,
                'checked_at' => '2026-01-01 00:00:00'],
            ['check_code' => 'table_size', 'status' => 'warn', 'message' => 'm2', 'details' => '{"total_bytes":5}',
                'checked_at' => '2026-01-01 00:00:00'],
            ['check_code' => 'tracking', 'status' => 'bogus', 'message' => 'm3', 'details' => 'not json',
                'checked_at' => '2026-01-01 00:00:00'],
        ];

        $latest = $this->repository($rows)->latest();

        $this->assertSame(['tracking', 'table_size', 'zzz_custom'], array_keys($latest));
        $this->assertSame('Tracking switches', $latest['tracking']['label']);
        $this->assertSame(HealthResult::STATUS_UNKNOWN, $latest['tracking']['status']);
        $this->assertSame([], $latest['tracking']['details']);
        $this->assertSame(['total_bytes' => 5], $latest['table_size']['details']);
        $this->assertSame('Zzz custom', $latest['zzz_custom']['label']);
    }
}
