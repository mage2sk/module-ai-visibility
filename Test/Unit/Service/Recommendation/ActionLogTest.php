<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Recommendation\ActionLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActionLogTest extends TestCase
{
    /**
     * @var array
     */
    private array $inserts = [];

    /**
     * @var array
     */
    private array $updates = [];

    /**
     * @var array
     */
    private array $statusCalls = [];

    /**
     * @var array
     */
    private array $transactions = [];

    /**
     * Action log over stubs
     *
     * @param array|null $rec Recommendation returned by get()
     * @param array $fetchRow Row returned for the action lookup
     * @param array $fetchAll Callback answers for fetchAll (in call order)
     * @param \Throwable|null $setStatusError
     * @param int $updateResult Rows the connection update() reports
     * @return ActionLog
     */
    private function log(
        ?array $rec,
        array $fetchRow = [],
        array $fetchAll = [],
        ?\Throwable $setStatusError = null,
        int $updateResult = 1
    ): ActionLog {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'joinLeft', 'order', 'limit'] as $method) {
            $select->method($method)->willReturn($select);
        }
        $connection = $this->createStub(Mysql::class);
        $connection->method('select')->willReturn($select);
        $connection->method('insert')->willReturnCallback(function (string $table, array $row): int {
            $this->inserts[] = [$table, $row];
            return 1;
        });
        $connection->method('update')->willReturnCallback(
            function (string $table, array $data, $where) use ($updateResult): int {
                $this->updates[] = [$table, $data, $where];
                return $updateResult;
            }
        );
        $connection->method('lastInsertId')->willReturn('15');
        $connection->method('fetchRow')->willReturn($fetchRow);
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(...($fetchAll ?: [[]]));
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $connection->method($method)->willReturnCallback(function () use ($method, $connection) {
                $this->transactions[] = $method;
                return $connection;
            });
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $n): string => $n);

        $repo = $this->createStub(RecommendationRepositoryInterface::class);
        $repo->method('get')->willReturn($rec);
        $repo->method('setStatus')->willReturnCallback(function (int $id, string $status) use ($setStatusError): void {
            if ($setStatusError) {
                throw $setStatusError;
            }
            $this->statusCalls[] = [$id, $status];
        });
        $config = $this->createStub(Config::class);
        $config->method('verifyWindowDays')->willReturn(14);
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(gmmktime(23, 30, 0, 3, 1, 2026));

        return new ActionLog($resource, $repo, $config, $clock, new Json(), $this->createStub(LoggerInterface::class));
    }

    /**
     * @param array $overrides
     * @return array
     */
    private static function rec(array $overrides = []): array
    {
        return $overrides + [
            'rec_id' => 7,
            'rule_code' => 'bot_404',
            'store_id' => 2,
            'subject_type' => 'path',
            'subject_ref' => '/old',
            'metric' => 'bot_404',
            'status' => 'open',
            'evidence' => ['subjects' => ['/old', '/older', '/old', ''], 'top' => []],
            'is_simulated' => 0,
        ];
    }

    public function testApplySnapshotsTheRecommendation(): void
    {
        $id = $this->log(self::rec())->apply(7, '  Added redirects  ', 'admin.user');

        $this->assertSame(15, $id);
        [$table, $row] = $this->inserts[0];
        $this->assertSame('panth_aiv_action', $table);
        $this->assertSame(7, $row['rec_id']);
        $this->assertSame('bot_404', $row['rule_code']);
        $this->assertSame(2, $row['store_id']);
        $this->assertSame('bot_404', $row['metric']);
        $this->assertSame('["\/old","\/older"]', $row['subjects']);
        $this->assertSame('Added redirects', $row['note']);
        $this->assertSame('admin.user', $row['applied_by']);
        $this->assertSame('2026-03-01 23:30:00', $row['applied_at']);
        // Applied day 2026-03-01 + 14 days window + 1.
        $this->assertSame('2026-03-16', $row['verify_after']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, $row['is_simulated']);
        // The recommendation is claimed with a conditional update (open -> applied) in the transaction.
        [$table, $data, $where] = $this->updates[0];
        $this->assertSame('panth_aiv_recommendation', $table);
        $this->assertSame('applied', $data['status']);
        $this->assertSame('2026-03-01 23:30:00', $data['status_changed_at']);
        $this->assertSame(['rec_id = ?' => 7, 'status = ?' => 'open'], $where);
        $this->assertSame([], $this->statusCalls);
        $this->assertSame(['beginTransaction', 'commit'], $this->transactions);
    }

    public function testApplyRefusesARecommendationThatIsNotOpen(): void
    {
        foreach (['dismissed', 'resolved'] as $status) {
            try {
                $this->log(self::rec(['status' => $status]))->apply(7, null, null);
                $this->fail($status . ' recommendation applied');
            } catch (LocalizedException $e) {
                $this->assertStringContainsString('not open', $e->getMessage());
            }
        }
        $this->assertSame([], $this->inserts);
    }

    public function testApplyFallsBackToTheSubjectRefAndCliHasNoUser(): void
    {
        $this->log(self::rec(['evidence' => '{"top":[]}', 'subject_ref' => 'site', 'is_simulated' => 1]))
            ->apply(7, null, null);

        $row = $this->inserts[0][1];
        $this->assertSame('["site"]', $row['subjects']);
        $this->assertNull($row['note']);
        $this->assertNull($row['applied_by']);
        $this->assertSame(1, $row['is_simulated']);
    }

    public function testApplyCutsLongNotes(): void
    {
        $this->log(self::rec())->apply(7, str_repeat('n', 600), str_repeat('u', 80));

        $this->assertSame(512, mb_strlen($this->inserts[0][1]['note']));
        $this->assertSame(64, mb_strlen($this->inserts[0][1]['applied_by']));
    }

    public function testApplyRejectsUnknownOrAlreadyAppliedRecommendations(): void
    {
        try {
            $this->log(null)->apply(99, null, null);
            $this->fail('Unknown recommendation accepted');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('99', $e->getMessage());
        }
        $this->expectException(LocalizedException::class);
        $this->log(self::rec(['status' => 'applied']))->apply(7, null, null);
    }

    public function testApplyRollsBackWhenAnotherRequestClaimedTheRecommendation(): void
    {
        $log = $this->log(self::rec(), [], [], null, 0);
        try {
            $log->apply(7, null, null);
            $this->fail('Exception expected');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('already applied', $e->getMessage());
            $this->assertSame(['beginTransaction', 'rollBack'], $this->transactions);
            $this->assertSame([], $this->inserts, 'no action row without the claim');
        }
    }

    public function testUndoMarksTheActionAndReopensTheRecommendation(): void
    {
        $this->log(self::rec(['status' => 'applied']), ['action_id' => 15, 'rec_id' => 7, 'status' => 'pending', 'undone_at' => null])
            ->undo(15);

        [$table, $data, $where] = $this->updates[0];
        $this->assertSame('panth_aiv_action', $table);
        $this->assertSame('undone', $data['status']);
        $this->assertSame('2026-03-01 23:30:00', $data['undone_at']);
        $this->assertSame(['action_id = ?' => 15, 'status = ?' => 'pending'], $where);
        $this->assertSame([[7, 'open']], $this->statusCalls);
    }

    public function testUndoRefusesVerifiedActionsAndALostRace(): void
    {
        foreach (['verified', 'insufficient'] as $status) {
            try {
                $this->log(self::rec(['status' => 'applied']), ['action_id' => 15, 'rec_id' => 7, 'status' => $status])
                    ->undo(15);
                $this->fail($status . ' action undone');
            } catch (LocalizedException $e) {
                $this->assertStringContainsString('cannot be undone', $e->getMessage());
            }
        }
        $this->assertSame([], $this->updates);
        // Verified between the read and the conditional update: refused, recommendation stays applied.
        try {
            $this->log(self::rec(['status' => 'applied']), ['action_id' => 15, 'rec_id' => 7, 'status' => 'pending'], [], null, 0)
                ->undo(15);
            $this->fail('lost race undone');
        } catch (LocalizedException $e) {
            $this->assertSame([], $this->statusCalls);
        }
    }

    public function testUndoIsIdempotentAndRejectsUnknownActions(): void
    {
        $this->log(null, ['action_id' => 15, 'rec_id' => null, 'status' => 'undone', 'undone_at' => '2026-03-02 00:00:00'])
            ->undo(15);
        $this->assertSame([], $this->updates);

        $this->expectException(LocalizedException::class);
        $this->log(null, [])->undo(16);
    }

    public function testListRecentAttachesImpactRows(): void
    {
        $actions = [
            ['action_id' => '16', 'subjects' => '["\/a","\/b"]', 'title' => 'T', 'status' => 'pending'],
            ['action_id' => '15', 'subjects' => '["site"]', 'title' => null, 'status' => 'verified'],
        ];
        $impacts = [['action_id' => '15', 'verdict' => 'helped', 'details' => '{"reason":null,"seed":15}']];

        $rows = $this->log(null, [], [$actions, $impacts])->listRecent(10);

        $this->assertCount(2, $rows);
        $this->assertSame(['/a', '/b'], $rows[0]['subjects']);
        $this->assertNull($rows[0]['impact']);
        $this->assertSame('helped', $rows[1]['impact']['verdict']);
        $this->assertSame(['reason' => null, 'seed' => 15], $rows[1]['impact']['details']);
        $this->assertSame('T', $rows[0]['title'], 'the recommendation title comes with the action rows');
    }

    public function testListRecentWithNoActions(): void
    {
        $this->assertSame([], $this->log(null, [], [[]])->listRecent(0));
    }
}
