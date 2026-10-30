<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AiVisibility\Service\Recommendation\Repository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase
{
    /**
     * @var AdapterInterface&\PHPUnit\Framework\MockObject\Stub
     */
    private $adapter;

    protected function setUp(): void
    {
        $this->adapter = $this->createStub(AdapterInterface::class);
    }

    public function testListOpenRanksByFeedbackAdjustedImpactAndSkipsSimulatedRows(): void
    {
        $captured = [];
        $this->adapter->method('fetchAll')->willReturnCallback(function (string $sql, array $bind) use (&$captured) {
            $captured = [$sql, $bind];
            return [[
                'rec_id' => '4', 'store_id' => '1', 'affected_count' => '30', 'impact_score' => '60',
                'is_simulated' => '0', 'feedback' => null, 'rank_score' => '30.0000',
                'evidence' => '{"top_paths":[{"path":"/a"}],"subjects":["/a"]}',
            ]];
        });

        $rows = $this->repository()->listOpen(1, 9999);

        [$sql, $bind] = $captured;
        $this->assertStringContainsString('r.status = :status AND r.is_simulated = :simulated', $sql);
        $this->assertSame(0, $bind['simulated'], 'real rows only by default');
        $this->assertStringContainsString('r.store_id = :store', $sql);
        $this->assertStringContainsString('f.feedback = -1', $sql);
        $this->assertStringContainsString('THEN 0.5 ELSE 1 END', $sql);
        $this->assertStringContainsString('ORDER BY rank_score DESC', $sql);
        $this->assertStringEndsWith('LIMIT ' . Repository::MAX_LIST, $sql);
        $this->assertSame('open', $bind['status']);
        $this->assertSame(1, $bind['store']);
        $this->assertSame(4, $rows[0]['rec_id']);
        $this->assertSame(30.0, $rows[0]['rank_score']);
        $this->assertNull($rows[0]['feedback']);
        $this->assertSame(['/a'], $rows[0]['evidence']['subjects']);
    }

    public function testListOpenForAllStoresHasNoStoreFilter(): void
    {
        $captured = '';
        $this->adapter->method('fetchAll')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [];
        });
        $this->assertSame([], $this->repository()->listOpen(null, 0));
        $this->assertStringNotContainsString('r.store_id = :store', $captured);
        $this->assertStringEndsWith('LIMIT 1', $captured);
    }

    public function testListOpenCanReturnSimulatedRowsForPreviews(): void
    {
        $bind = [];
        $this->adapter->method('fetchAll')->willReturnCallback(function (string $sql, array $b) use (&$bind) {
            $bind = $b;
            return [];
        });
        $this->repository()->listOpen(null, 5, true);
        $this->assertSame(1, $bind['simulated']);
    }

    public function testGetDecodesEvidenceAndToleratesBrokenJson(): void
    {
        $this->adapter->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['rec_id' => '2', 'feedback' => '-1', 'evidence' => 'not json'],
            false
        );
        $row = $this->repository()->get(2);
        $this->assertSame([], $row['evidence']);
        $this->assertSame(-1, $row['feedback']);
        $this->assertNull($this->repository()->get(3));
    }

    public function testSetStatusValidatesAndStampsTheChange(): void
    {
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->adapter->method('fetchOne')->willReturn('5');
        $this->adapter->expects($this->once())->method('update')->with(
            'panth_aiv_recommendation',
            $this->callback(static fn (array $data): bool => $data['status'] === 'dismissed'
                && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $data['status_changed_at']) === 1),
            ['rec_id = ?' => 5]
        );
        $this->repository()->setStatus(5, 'dismissed');
    }

    public function testSetStatusRejectsUnknownStatus(): void
    {
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->adapter->expects($this->never())->method('update');
        $this->expectException(LocalizedException::class);
        $this->repository()->setStatus(5, 'done');
    }

    public function testSetStatusRejectsMissingRecommendation(): void
    {
        $this->adapter->method('fetchOne')->willReturn(false);
        $this->expectException(LocalizedException::class);
        $this->repository()->setStatus(99, 'applied');
    }

    public function testSetFeedback(): void
    {
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->adapter->method('fetchOne')->willReturn('5');
        $written = [];
        $this->adapter->expects($this->once())->method('update')->willReturnCallback(
            function (string $table, array $data, array $where) use (&$written): int {
                $written = [$table, $data, $where];
                return 1;
            }
        );
        $before = gmdate('Y-m-d H:i:s');
        $this->repository()->setFeedback(5, -1);

        $this->assertSame('panth_aiv_recommendation', $written[0]);
        $this->assertSame(-1, $written[1]['feedback']);
        $this->assertGreaterThanOrEqual($before, $written[1]['feedback_at'], 'feedback time starts the window');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $written[1]['feedback_at']);
        $this->assertSame(['rec_id = ?' => 5], $written[2]);

        $this->expectException(LocalizedException::class);
        $this->repository()->setFeedback(5, 2);
    }

    public function testCountsEveryStatusOfRealRows(): void
    {
        $captured = '';
        $this->adapter->method('fetchPairs')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return ['open' => '3', 'applied' => '1', 'bogus' => '9'];
        });
        $this->assertSame(
            ['open' => 3, 'applied' => 1, 'dismissed' => 0, 'resolved' => 0],
            $this->repository()->counts()
        );
        $this->assertStringContainsString('is_simulated = 0', $captured);
    }

    public function testByFingerprintsBindsEveryValue(): void
    {
        $captured = [];
        $this->adapter->method('fetchAll')->willReturnCallback(function (string $sql, array $bind) use (&$captured) {
            $captured = [$sql, $bind];
            return [['rec_id' => '3', 'fingerprint' => 'aa', 'status' => 'open', 'status_changed_at' => null,
                'is_simulated' => '0', 'feedback' => null]];
        });
        $rows = $this->repository()->byFingerprints(['aa', 'bb', 'aa']);
        $this->assertStringContainsString('fingerprint IN (:f0, :f1)', $captured[0]);
        $this->assertSame(['f0' => 'aa', 'f1' => 'bb'], $captured[1]);
        $this->assertSame(3, $rows['aa']['rec_id']);
        $this->assertSame([], $this->repository()->byFingerprints([]));
    }

    public function testOpenRowsAndPenalisedRulesFilterStoreStatusAndSimulation(): void
    {
        $queries = [];
        $this->adapter->method('fetchAll')->willReturnCallback(function (string $sql, array $bind) use (&$queries) {
            $queries[] = [$sql, $bind];
            return [['rec_id' => '1', 'fingerprint' => 'f', 'rule_code' => 'bot_404', 'title' => 'T',
                'impact_score' => '40', 'affected_count' => '22']];
        });
        $this->adapter->method('fetchCol')->willReturnCallback(function (string $sql, array $bind) use (&$queries) {
            $queries[] = [$sql, $bind];
            return ['bot_404'];
        });
        $repository = $this->repository();

        $this->assertSame(40, $repository->openRows(2)[0]['impact_score']);
        $this->assertSame(['bot_404'], $repository->penalisedRules(2, 1773100800));
        $this->assertSame(['store' => 2, 'status' => 'open'], $queries[0][1]);
        $this->assertStringContainsString('is_simulated = 0', $queries[0][0]);
        $this->assertSame('2026-02-08 00:00:00', $queries[1][1]['since']);
        $this->assertSame(-1, $queries[1][1]['feedback']);
        $this->assertStringContainsString('COALESCE(feedback_at, status_changed_at, last_seen_at) >= :since', $queries[1][0]);
    }

    public function testSaveSerialisesEvidenceAndUpsertsInChunks(): void
    {
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->adapter->expects($this->once())->method('insertOnDuplicate')->with(
            'panth_aiv_recommendation',
            $this->callback(static fn (array $rows): bool => json_decode($rows[0]['evidence'], true) === ['subjects' => ['/a']]
                && array_keys($rows[0]) === Repository::WRITE_COLUMNS),
            ['title']
        );
        $written = $this->repository()->save([['fingerprint' => 'f', 'evidence' => ['subjects' => ['/a']]]], ['title']);
        $this->assertSame(1, $written);
        $this->assertSame(0, $this->repository()->save([], ['title']));
    }

    public function testResolveOnlyTouchesOpenRows(): void
    {
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->adapter->expects($this->once())->method('update')->with(
            'panth_aiv_recommendation',
            ['status' => 'resolved', 'status_changed_at' => '2026-03-10 00:00:00'],
            ['rec_id IN (?)' => [3, 4], 'status = ?' => 'open']
        );
        $this->repository()->resolve([3, 4, 3], '2026-03-10 00:00:00');
        $this->repository()->resolve([], '2026-03-10 00:00:00');
    }

    public function testTransactionalCommitsOrRollsBack(): void
    {
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->adapter->expects($this->exactly(2))->method('beginTransaction');
        $this->adapter->expects($this->once())->method('commit');
        $this->adapter->expects($this->once())->method('rollBack');
        $repository = $this->repository();

        $this->assertSame(7, $repository->transactional(static fn () => 7));
        $this->expectException(\RuntimeException::class);
        $repository->transactional(static function (): void {
            throw new \RuntimeException('fail');
        });
    }

    /**
     * Repository under test
     *
     * @return Repository
     */
    private function repository(): Repository
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->adapter);
        $resource->method('getTableName')->willReturnArgument(0);
        return new Repository($resource, new Json());
    }
}
