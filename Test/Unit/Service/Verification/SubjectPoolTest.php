<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Verification;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Verification\SubjectPool;
use PHPUnit\Framework\TestCase;

class SubjectPoolTest extends TestCase
{
    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $rows = [];

    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $selectCalls = [];

    private function pool(array $result): SubjectPool
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '`' . $name . '`');
        $connection->method('fetchRow')->willReturnCallback(function (string $sql, array $bind) use ($result) {
            $this->rows[] = [$sql, $bind];
            return $result;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new SubjectPool($resource);
    }

    public function testCoverageBaselineCountsNeverFetchedEntitiesAndTheirFirstFetches(): void
    {
        $result = $this->pool(['n' => '420', 'x' => '21'])->coverageBaseline(
            'search_bot_hits',
            'product',
            2,
            [7, 9, 7],
            ['2026-02-01', '2026-02-14'],
            ['2026-02-15', '2026-02-28'],
            true
        );

        $this->assertSame(['n' => 420, 'x' => 21], $result);
        [$sql, $bind] = $this->rows[0];
        $this->assertSame(
            'SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM `panth_aiv_entity_daily` AS w'
            . ' WHERE w.entity_type = ? AND w.entity_id = b.entity_id AND w.day >= ? AND w.day <= ?'
            . ' AND w.is_simulated = ? AND w.category IN (?, ?) AND w.store_id = ?) THEN 1 ELSE 0 END), 0) AS x'
            . ' FROM (SELECT DISTINCT u.entity_id FROM `url_rewrite` AS u WHERE u.entity_type = ?'
            . ' AND u.redirect_type = 0 AND u.store_id = ? AND u.entity_id NOT IN (?, ?)'
            . ' AND NOT EXISTS (SELECT 1 FROM `panth_aiv_entity_daily` AS l WHERE l.entity_type = ?'
            . ' AND l.entity_id = u.entity_id AND l.day >= ? AND l.day <= ? AND l.is_simulated = ?'
            . ' AND l.category IN (?, ?) AND l.store_id = ?)) AS b',
            $sql
        );
        $this->assertSame(
            [
                'product', '2026-02-15', '2026-02-28', 1, 'search', 'live', 2,
                'product', 2, 7, 9,
                'product', '2026-02-01', '2026-02-14', 1, 'search', 'live', 2,
            ],
            $bind
        );
    }

    public function testCoverageBaselineForAllStoresWithoutExclusions(): void
    {
        $this->pool(['n' => '0', 'x' => null])->coverageBaseline(
            'bot_hits',
            'category',
            0,
            [],
            ['2026-02-01', '2026-02-14'],
            ['2026-02-15', '2026-02-28'],
            false
        );

        [$sql, $bind] = $this->rows[0];
        $this->assertStringNotContainsString('store_id', $sql);
        $this->assertStringNotContainsString('NOT IN', $sql);
        $this->assertSame('category', $bind[0]);
        $this->assertSame(0, $bind[3]);
    }

    public function testCoverageBaselineIsOnlyForProductsAndCategories(): void
    {
        $result = $this->pool(['n' => '5', 'x' => '5'])->coverageBaseline(
            'bot_hits',
            'path',
            1,
            [],
            ['2026-02-01', '2026-02-14'],
            ['2026-02-15', '2026-02-28'],
            false
        );

        $this->assertSame(['n' => 0, 'x' => 0], $result);
        $this->assertSame([], $this->rows);
    }

    /**
     * Pool whose select records its calls; fetchCol answers $keys
     *
     * @param array $keys
     * @return SubjectPool
     */
    private function candidatePool(array $keys): SubjectPool
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'group', 'having', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($select, $method) {
                while ($args !== [] && end($args) === null) {
                    array_pop($args);
                }
                $this->selectCalls[] = [$method, $args];
                return $select;
            });
        }
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn($keys);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new SubjectPool($resource);
    }

    /**
     * Arguments of the recorded calls of one select method
     *
     * @param string $method
     * @return array
     */
    private function selectArgs(string $method): array
    {
        $out = [];
        foreach ($this->selectCalls as [$name, $args]) {
            if ($name === $method) {
                $out[] = $args;
            }
        }
        return $out;
    }

    public function testErrorMetricCandidatesAreThePathsWithErrorsNotTheBusiestPaths(): void
    {
        $result = $this->candidatePool(['/gone', '', '/old'])
            ->candidates('bot_404', 'path', 1, '2026-02-01', '2026-02-14', false);

        $this->assertSame(['/gone', '/old'], $result);
        $this->assertSame([['p' => 'panth_aiv_path_daily']], [$this->selectArgs('from')[0][0]]);
        $this->assertSame([['SUM(p.s4xx) > 0']], $this->selectArgs('having'));
        $this->assertSame('SUM(p.s4xx) DESC', (string)$this->selectArgs('order')[0][0][0]);
        $this->assertSame([500], $this->selectArgs('limit')[0]);
    }

    public function testProductErrorAndSpeedCandidatesComeFromThePathStatusColumns(): void
    {
        $this->candidatePool(['42'])->candidates('bot_5xx', 'product', 1, '2026-02-01', '2026-02-14', false);
        $this->assertSame([['p' => 'panth_aiv_path_daily'], ['k' => 'p.entity_id']], $this->selectArgs('from')[0]);
        $this->assertSame([['SUM(p.s5xx) > 0']], $this->selectArgs('having'));
        $this->assertContains(['p.entity_type = ?', 'product'], $this->selectArgs('where'));
        $this->assertContains(['p.category IN (?)', Category::BOT_CATEGORIES], $this->selectArgs('where'));

        $this->selectCalls = [];
        $this->candidatePool([])->candidates('bot_avg_ms', 'category', 0, '2026-02-01', '2026-02-14', false);
        $this->assertSame([['SUM(p.timed_hits) > 0']], $this->selectArgs('having'));
    }

    public function testCrawlCountCandidatesFollowTheMetricBotCategories(): void
    {
        $this->candidatePool(['7'])->candidates('live_fetches', 'product', 1, '2026-02-01', '2026-02-14', true);

        $this->assertSame(['p' => 'panth_aiv_entity_daily'], $this->selectArgs('from')[0][0]);
        $this->assertContains(['p.category IN (?)', [Category::LIVE]], $this->selectArgs('where'));
        $this->assertContains(['p.is_simulated = ?', 1], $this->selectArgs('where'));
        $this->assertSame([], $this->selectArgs('having'));
    }

    public function testVisitCandidatesComeFromTheVisitPathDailyTable(): void
    {
        $this->candidatePool(['9'])->candidates('ai_visits', 'product', 1, '2026-02-01', '2026-02-14', false);
        $this->assertSame([['v' => 'panth_aiv_visit_path_daily'], ['k' => 'v.entity_id']], $this->selectArgs('from')[0]);
        $this->assertSame([['SUM(v.visits) > 0']], $this->selectArgs('having'));

        $this->selectCalls = [];
        $this->candidatePool(['/a'])->candidates('ai_conversion', 'path', 1, '2026-02-01', '2026-02-14', false);
        $this->assertSame(['v' => 'panth_aiv_visit_path_daily'], $this->selectArgs('from')[0][0]);
        $this->assertSame('MIN(v.path)', (string)$this->selectArgs('from')[0][1]['k']);
        $this->assertContains(['v.day <= ?', '2026-02-14'], $this->selectArgs('where'));
    }
}
