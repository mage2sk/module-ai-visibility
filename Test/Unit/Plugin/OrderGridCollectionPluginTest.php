<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Plugin;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Plugin\OrderGridCollectionPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderGridCollectionPluginTest extends TestCase
{
    /**
     * @var array<int, array>
     */
    private array $joins = [];

    /**
     * @var array<string, string>
     */
    private array $map = [];

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

    /**
     * @var array
     */
    private array $fromPart = ['main_table' => ['tableName' => 'sales_order_grid']];

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $joinError = null;

    private function plugin(string $model = 'first'): OrderGridCollectionPlugin
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name) => 'pfx_' . $name);
        $connection = $this->createStub(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $resource->method('getConnection')->willReturn($connection);

        $config = $this->createStub(Config::class);
        $config->method('attributionModel')->willReturn($model);

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });

        return new OrderGridCollectionPlugin($resource, $config, $logger);
    }

    private function collection(): AbstractDb
    {
        $select = $this->createStub(Select::class);
        $select->method('getPart')->willReturnCallback(fn ($part) => $part === Select::FROM ? $this->fromPart : []);
        $select->method('joinLeft')->willReturnCallback(function ($name, $cond, $cols = '*') use ($select) {
            if ($this->joinError !== null) {
                throw $this->joinError;
            }
            $this->joins[] = [$name, $cond, $cols];
            return $select;
        });

        $collection = $this->createStub(AbstractDb::class);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('addFilterToMap')->willReturnCallback(
            function ($filter, $alias, $group = 'fields') use ($collection) {
                $this->map[$filter] = $alias;
                return $collection;
            }
        );
        return $collection;
    }

    private function factory(): CollectionFactory
    {
        return $this->createStub(CollectionFactory::class);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function modelProvider(): array
    {
        return [
            'first touch' => ['first', 'panth_aiv_ord.first_source'],
            'last touch' => ['last', 'panth_aiv_ord.last_source'],
        ];
    }

    /**
     * @dataProvider modelProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelProvider')]
    public function testJoinAndFilterMapFollowTheAttributionModel(string $model, string $column): void
    {
        $collection = $this->collection();
        $result = $this->plugin($model)->afterGetReport(
            $this->factory(),
            $collection,
            'sales_order_grid_data_source'
        );

        $this->assertSame($collection, $result);
        $this->assertCount(1, $this->joins);
        [$name, $condition, $columns] = $this->joins[0];
        $this->assertSame(['panth_aiv_ord' => 'pfx_panth_aiv_order'], $name);
        $this->assertSame(
            'panth_aiv_ord.order_id = main_table.entity_id AND panth_aiv_ord.is_simulated = 0',
            $condition
        );
        $this->assertSame(['ai_source' => $column], $columns);
        $this->assertSame(['ai_source' => $column], $this->map);
        $this->assertSame([], $this->warnings);
    }

    public function testOtherDataSourcesAreUntouched(): void
    {
        $collection = $this->collection();
        $result = $this->plugin()->afterGetReport(
            $this->factory(),
            $collection,
            'sales_order_invoice_grid_data_source'
        );
        $this->assertSame($collection, $result);
        $this->assertSame([], $this->joins);
        $this->assertSame([], $this->map);
    }

    public function testNonDbResultIsReturnedAsIs(): void
    {
        $result = new \stdClass();
        $this->assertSame(
            $result,
            $this->plugin()->afterGetReport($this->factory(), $result, 'sales_order_grid_data_source')
        );
    }

    public function testAliasIsNotJoinedTwice(): void
    {
        $this->fromPart['panth_aiv_ord'] = ['tableName' => 'pfx_panth_aiv_order'];
        $this->plugin()->afterGetReport($this->factory(), $this->collection(), 'sales_order_grid_data_source');
        $this->assertSame([], $this->joins);
    }

    public function testAliasDoesNotCollideWithOtherGridJoins(): void
    {
        // Other order grid extensions join under short aliases (e.g. ppo / ppr / ppu); ours is module-specific.
        $this->fromPart += ['ppo' => [], 'ppr' => [], 'ppu' => [], 'so' => []];
        $this->plugin()->afterGetReport($this->factory(), $this->collection(), 'sales_order_grid_data_source');
        $this->assertCount(1, $this->joins);
        $this->assertSame('panth_aiv_ord', OrderGridCollectionPlugin::ALIAS);
    }

    public function testErrorsNeverEscape(): void
    {
        $this->joinError = new \RuntimeException('duplicate alias');
        $collection = $this->collection();
        $result = $this->plugin()->afterGetReport($this->factory(), $collection, 'sales_order_grid_data_source');
        $this->assertSame($collection, $result);
        $this->assertSame([], $this->map);
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('duplicate alias', $this->warnings[0]);
    }
}
