<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Service\Relabeler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RelabelerTest extends TestCase
{
    /**
     * @var array<int, array{0: string, 1: mixed, 2: mixed, 3?: mixed}>
     */
    private array $calls = [];

    /**
     * @var string[]
     */
    private array $rolled = [];

    protected function setUp(): void
    {
        $this->calls = [];
        $this->rolled = [];
    }

    public function testBotCandidateHitsAndOverflowAreMovedAndDaysReRolled(): void
    {
        $relabeler = $this->relabeler(
            ['2026-10-02', '2026-10-01'],
            ['2026-10-02', '2026-10-03'],
            [['day' => '2026-10-03', 'store_id' => '1', 'status_class' => '2', 'is_simulated' => '0', 'hits' => '50']]
        );

        $this->assertSame(7, $relabeler->relabelCandidate(12, 'bot', 'disc_newbot', 'live'));

        $update = $this->call('update');
        $this->assertSame('panth_aiv_hit', $update[1]);
        $this->assertSame(['bot_code' => 'disc_newbot', 'category' => 'live'], $update[2]);
        $this->assertSame(['bot_code = ?' => 'cand:12'], $update[3]);

        $upsert = $this->call('insertOnDuplicate');
        $this->assertSame('panth_aiv_overflow_daily', $upsert[1]);
        $this->assertSame([[
            'day' => '2026-10-03', 'store_id' => 1, 'bot_code' => 'disc_newbot', 'category' => 'live',
            'status_class' => 2, 'hits' => 50, 'is_simulated' => 0,
        ]], $upsert[2]);
        $this->assertSame('category', $upsert[3][0]);
        $this->assertSame('hits + VALUES(hits)', (string)$upsert[3]['hits']);

        $delete = $this->call('delete');
        $this->assertSame(['panth_aiv_overflow_daily', ['bot_code = ?' => 'cand:12']], [$delete[1], $delete[2]]);
        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], $this->rolled);

        $names = array_column($this->calls, 0);
        $this->assertLessThan(array_search('commit', $names, true), array_search('update', $names, true));
    }

    public function testBotCategoryDefaultsToOther(): void
    {
        $relabeler = $this->relabeler(['2026-10-01'], [], []);
        $relabeler->relabelCandidate(3, 'bot', 'disc_x');

        $this->assertSame('other', $this->call('update')[2]['category']);
        $this->assertNull($this->call('insertOnDuplicate'));
    }

    public function testSourceCandidateVisitsAreMoved(): void
    {
        $relabeler = $this->relabeler(['2026-10-05 00:00:00'], [], []);

        $this->assertSame(7, $relabeler->relabelCandidate(4, 'source', 'disc_assistant', 'ignored'));

        $update = $this->call('update');
        $this->assertSame('panth_aiv_visit', $update[1]);
        $this->assertSame(['source_code' => 'disc_assistant'], $update[2]);
        $this->assertSame(['source_code = ?' => 'cand:4'], $update[3]);
        $this->assertNull($this->call('insertOnDuplicate'));
        $this->assertSame(['2026-10-05'], $this->rolled);
    }

    public function testNothingToMoveReturnsZeroWithoutWrites(): void
    {
        $relabeler = $this->relabeler([], [], []);

        $this->assertSame(0, $relabeler->relabelCandidate(5, 'bot', 'disc_x', 'search'));
        $this->assertNull($this->call('beginTransaction'));
        $this->assertSame([], $this->rolled);
    }

    public function testFailedUpdateRollsBack(): void
    {
        $relabeler = $this->relabeler(['2026-10-01'], [], [], new \RuntimeException('lock wait'));

        try {
            $relabeler->relabelCandidate(5, 'source', 'disc_x');
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('lock wait', $e->getMessage());
        }
        $this->assertNotNull($this->call('rollBack'));
        $this->assertNull($this->call('commit'));
        $this->assertSame([], $this->rolled);
    }

    public function testReRollFailureIsLoggedAndOtherDaysContinue(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('2026-10-01'));
        $relabeler = $this->relabeler(['2026-10-01', '2026-10-02'], [], [], null, $logger, '2026-10-01');

        $this->assertSame(7, $relabeler->relabelCandidate(5, 'source', 'disc_x'));
        $this->assertSame(['2026-10-02'], $this->rolled);
    }

    /**
     * @dataProvider invalidArguments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidArguments')]
    public function testInvalidArgumentsAreRejected(int $id, string $type, string $code, ?string $category): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->relabeler(['2026-10-01'], [], [])->relabelCandidate($id, $type, $code, $category);
    }

    public static function invalidArguments(): array
    {
        return [
            'zero id' => [0, 'bot', 'disc_x', 'live'],
            'unknown type' => [1, 'robot', 'disc_x', 'live'],
            'empty code' => [1, 'bot', '  ', 'live'],
            'candidate code' => [1, 'source', 'cand:9', null],
            'long code' => [1, 'source', str_repeat('a', 65), null],
            'bad category' => [1, 'bot', 'disc_x', 'unknown'],
        ];
    }

    /**
     * @param array $rawDays DATE(created_at) values of hit (bot) or visit (source) rows
     * @param array $overflowDays
     * @param array $overflowRows
     * @param \Throwable|null $updateFailure
     * @param LoggerInterface|null $logger
     * @param string|null $failingDay
     * @return Relabeler
     */
    private function relabeler(
        array $rawDays,
        array $overflowDays,
        array $overflowRows,
        ?\Throwable $updateFailure = null,
        ?LoggerInterface $logger = null,
        ?string $failingDay = null
    ): Relabeler {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($name): string => '`' . $name . '`');
        $connection->method('fetchCol')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'overflow') ? $overflowDays : $rawDays
        );
        $connection->method('fetchAll')->willReturn($overflowRows);
        $connection->method('update')->willReturnCallback(
            function ($table, $data, $where) use ($updateFailure) {
                if ($updateFailure !== null) {
                    throw $updateFailure;
                }
                $this->calls[] = ['update', $table, $data, $where];
                return 7;
            }
        );
        $connection->method('insertOnDuplicate')->willReturnCallback(function ($table, $data, $fields) {
            $this->calls[] = ['insertOnDuplicate', $table, $data, $fields];
            return count($data);
        });
        $connection->method('delete')->willReturnCallback(function ($table, $where) {
            $this->calls[] = ['delete', $table, $where];
            return 1;
        });
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $connection->method($method)->willReturnCallback(function () use ($method, $connection) {
                $this->calls[] = [$method, null, null];
                return $connection;
            });
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $rollup = $this->createStub(RollupInterface::class);
        $rollup->method('rollupDay')->willReturnCallback(function (string $day) use ($failingDay): void {
            if ($day === $failingDay) {
                throw new \RuntimeException('deadlock');
            }
            $this->rolled[] = $day;
        });

        return new Relabeler($resource, $rollup, $logger ?? $this->createStub(LoggerInterface::class));
    }

    /**
     * First recorded call of a method
     *
     * @param string $method
     * @return array|null
     */
    private function call(string $method): ?array
    {
        foreach ($this->calls as $call) {
            if ($call[0] === $method) {
                return $call;
            }
        }
        return null;
    }
}
