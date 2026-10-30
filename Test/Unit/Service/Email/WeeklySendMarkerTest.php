<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Email;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\AiVisibility\Service\Email\WeeklySendMarker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Shared mocks get expectations only in the tests that need them.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class WeeklySendMarkerTest extends TestCase
{
    /**
     * @var AdapterInterface&MockObject
     */
    private $connection;

    /**
     * @var array<int, mixed>
     */
    private array $fetchOne = [];

    protected function setUp(): void
    {
        $this->fetchOne = [];
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('quoteIdentifier')->willReturnCallback(static fn ($name) => '`' . $name . '`');
        $this->connection->method('fetchOne')->willReturnCallback(function () {
            return array_shift($this->fetchOne) ?? false;
        });
    }

    public function testLastWeekIsNullWhenNeverSent(): void
    {
        $this->fetchOne = [false];

        $this->assertNull($this->marker()->lastWeek());
    }

    public function testLastWeekReadsTheStoredWeek(): void
    {
        $this->fetchOne = ['202644'];

        $this->assertSame(202644, $this->marker()->lastWeek());
    }

    public function testFirstClaimInsertsTheRow(): void
    {
        $this->fetchOne = [false, false, '0'];
        $this->connection->expects($this->never())->method('delete');
        $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
        $statement->method('rowCount')->willReturn(1);
        $this->connection->expects($this->once())->method('query')
            ->with(
                $this->stringContains('INSERT IGNORE INTO `panth_aiv_counter`'),
                ['email_weekly_sent', '2026-10-26', 202644]
            )
            ->willReturn($statement);

        $this->assertSame(0, $this->marker()->claim(202644, '2026-10-26'));
    }

    public function testClaimUpdatesAnOlderWeek(): void
    {
        $this->fetchOne = ['202643', '2026-10-20', '1'];
        $this->connection->expects($this->once())->method('update')
            ->with(
                'panth_aiv_counter',
                ['value' => 202644, 'day' => '2026-10-27'],
                ['counter_key = ?' => 'email_weekly_sent', 'value <> ?' => 202644]
            )->willReturn(1);

        $this->assertSame(202643, $this->marker()->claim(202644, '2026-10-27'));
    }

    public function testClaimLostToAnotherRun(): void
    {
        $this->fetchOne = ['202643', '2026-10-20', '1'];
        $this->connection->method('update')->willReturn(0);

        $this->assertNull($this->marker()->claim(202644, '2026-10-27'));
    }

    public function testClaimOfTheSentWeekIsRefused(): void
    {
        $this->fetchOne = ['202644'];
        $this->connection->expects($this->never())->method('update');
        $this->connection->expects($this->never())->method('query');

        $this->assertNull($this->marker()->claim(202644, '2026-10-27'));
    }

    public function testReleaseRestoresThePreviousWeek(): void
    {
        $this->connection->expects($this->once())->method('update')->with(
            'panth_aiv_counter',
            ['value' => 202643],
            ['counter_key = ?' => 'email_weekly_sent', 'value = ?' => 202644]
        );

        $this->marker()->release(202644, 202643);
    }

    public function testTouchMovesTheRowToToday(): void
    {
        $this->fetchOne = ['2026-10-27'];
        $this->connection->expects($this->once())->method('update')->with(
            'panth_aiv_counter',
            ['day' => '2026-10-28'],
            ['counter_key = ?' => 'email_weekly_sent', 'day <> ?' => '2026-10-28']
        );

        $this->marker()->touch('2026-10-28');
    }

    /**
     * Two rows (first claims on both sides of midnight) must not make touch() or claim() fail: the older rows
     * are deleted first, so the following update cannot hit the (counter_key, day) primary key.
     */
    public function testTouchAndClaimDropOlderExtraRowsFirst(): void
    {
        $calls = [];
        $this->connection->method('delete')->willReturnCallback(function ($table, $where) use (&$calls) {
            $calls[] = ['delete', $table, $where];
            return 1;
        });
        $this->connection->method('update')->willReturnCallback(function ($table, $bind, $where) use (&$calls) {
            $calls[] = ['update', $table, $bind, $where];
            return 1;
        });

        $this->fetchOne = ['2026-10-28'];
        $this->marker()->touch('2026-10-28');
        $this->assertSame(
            ['delete', 'panth_aiv_counter', ['counter_key = ?' => 'email_weekly_sent', 'day <> ?' => '2026-10-28']],
            $calls[0]
        );
        $this->assertSame('update', $calls[1][0]);

        $calls = [];
        $this->fetchOne = ['202643', '2026-10-28', '1'];
        $this->assertSame(202643, $this->marker()->claim(202644, '2026-10-29'));
        $this->assertSame('delete', $calls[0][0]);
        $this->assertSame(['value' => 202644, 'day' => '2026-10-29'], $calls[1][2]);
    }

    /**
     * @return WeeklySendMarker
     */
    private function marker(): WeeklySendMarker
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new WeeklySendMarker($resource);
    }
}
