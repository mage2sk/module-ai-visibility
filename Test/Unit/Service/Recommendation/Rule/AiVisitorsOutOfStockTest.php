<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\Rule\AiVisitorsOutOfStock;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\PathProductResolver;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\StockStatusLookup;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use PHPUnit\Framework\TestCase;

class AiVisitorsOutOfStockTest extends TestCase
{
    use RuleDbTrait;

    private function rule(int $minVolume = 20, string $storeCode = ''): AiVisitorsOutOfStock
    {
        $support = $this->support($minVolume, $storeCode);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $catalog = $this->createStub(CatalogLookup::class);
        $catalog->method('entityNames')->willReturn(['product:21' => 'Rain Jacket']);
        return new AiVisitorsOutOfStock(
            $support,
            $this->logger(),
            new PathProductResolver($support),
            new StockStatusLookup($support, $storeManager),
            $catalog
        );
    }

    private function seedVisits(): void
    {
        $this->route('fetchAll', 'FROM `panth_aiv_visit`', [
            ['store_id' => 1, 'path_hash' => 'h1', 'path' => '/rain-jacket.html', 'visits' => 18],
            ['store_id' => 1, 'path_hash' => 'h2', 'path' => '/index.php/rain-jacket.html', 'visits' => 3],
            ['store_id' => 1, 'path_hash' => 'h3', 'path' => '/boots.html', 'visits' => 6],
            ['store_id' => 1, 'path_hash' => 'h4', 'path' => '/tent.html', 'visits' => 40],
            ['store_id' => 1, 'path_hash' => 'h5', 'path' => '/about-us', 'visits' => 70],
        ]);
        $this->route('fetchAll', 'FROM `url_rewrite`', [
            ['request_path' => 'rain-jacket.html', 'entity_id' => 21, 'redirect_type' => 0],
            ['request_path' => 'boots.html', 'entity_id' => 22, 'redirect_type' => 301],
            ['request_path' => 'tent.html', 'entity_id' => 23, 'redirect_type' => 0],
        ]);
    }

    public function testLegacyStockStatusFlagsOutOfStockProducts(): void
    {
        $this->seedVisits();
        $this->route('fetchAll', 'FROM `cataloginventory_stock_status`', [
            ['product_id' => 21, 'website_id' => 0, 'stock_status' => 0],
            ['product_id' => 22, 'website_id' => 0, 'stock_status' => 1],
            ['product_id' => 22, 'website_id' => 1, 'stock_status' => 0],
            ['product_id' => 23, 'website_id' => 0, 'stock_status' => 1],
        ]);
        $findings = $this->rule()->evaluate(1, self::today());

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame(Finding::SUBJECT_PRODUCT, $finding->subjectType);
        $this->assertSame(MetricProviderInterface::METRIC_AI_VISITS, $finding->metric);
        $this->assertSame(['21', '22'], $finding->subjects);
        $this->assertSame(27, $finding->affectedCount, '18 + 3 (index.php path) + 6');
        $this->assertSame('legacy', $finding->evidence['stock_source']);
        $this->assertSame('Rain Jacket', $finding->evidence['examples'][0]['name']);
        $this->assertSame(21, $finding->evidence['examples'][0]['ai_visits']);
        $this->assertStringContainsString('27 shoppers', $finding->title);
        $this->assertStringContainsString('2 products', $finding->title);

        $stock = $this->queriesWith('cataloginventory_stock_status')[0];
        $this->assertSame([21, 22, 23, 0, 1], $stock['bind']);
        $rewrite = $this->queriesWith('url_rewrite')[0];
        $this->assertSame(1, $rewrite['bind'][0]);
        $this->assertSame('product', $rewrite['bind'][1]);
        $this->assertNotContains('index.php/rain-jacket.html', $rewrite['bind']);
    }

    public function testMsiCustomStockUsesTheStockIndexBySku(): void
    {
        $this->existingTables = ['inventory_stock_sales_channel', 'inventory_stock_3'];
        $this->seedVisits();
        $this->route('fetchOne', 'FROM `inventory_stock_sales_channel`', '3');
        $this->route('fetchPairs', 'FROM `catalog_product_entity` AS e INNER JOIN `inventory_stock_3`', [
            21 => 1,
            22 => 0,
            23 => 0,
        ]);
        $findings = $this->rule()->evaluate(1, self::today());

        $this->assertSame(['23', '22'], $findings[0]->subjects);
        $this->assertSame(46, $findings[0]->affectedCount);
        $this->assertSame('msi', $findings[0]->evidence['stock_source']);
        $this->assertSame([], $this->queriesWith('cataloginventory_stock_status'));
        $channel = $this->queriesWith('inventory_stock_sales_channel')[0];
        $this->assertSame(['website', 1], $channel['bind']);
    }

    public function testMsiDefaultStockReadsTheLegacyTable(): void
    {
        $this->existingTables = ['inventory_stock_sales_channel', 'inventory_stock_1'];
        $this->seedVisits();
        $this->route('fetchOne', 'FROM `inventory_stock_sales_channel`', '1');
        $this->route('fetchAll', 'FROM `cataloginventory_stock_status`', [
            ['product_id' => 23, 'website_id' => 0, 'stock_status' => 0],
        ]);
        $findings = $this->rule()->evaluate(1, self::today());

        $this->assertSame(['23'], $findings[0]->subjects);
        $this->assertSame('legacy', $findings[0]->evidence['stock_source']);
        $this->assertSame([], $this->queriesWith('inventory_stock_1'));
    }

    public function testInStockConditionUsesTheLegacyStatusWithTheWebsiteRowFirst(): void
    {
        $sql = $this->lookup()->inStockCondition(1, 'e');

        $this->assertSame(
            'COALESCE((SELECT MAX(stock_w.stock_status) FROM `cataloginventory_stock_status` AS stock_w'
            . ' WHERE stock_w.product_id = e.entity_id AND stock_w.website_id = 1),'
            . ' (SELECT MAX(stock_d.stock_status) FROM `cataloginventory_stock_status` AS stock_d'
            . ' WHERE stock_d.product_id = e.entity_id AND stock_d.website_id = 0), 0) = 1',
            $sql
        );
    }

    public function testInStockConditionUsesTheMsiIndexForACustomStock(): void
    {
        $this->existingTables = ['inventory_stock_sales_channel', 'inventory_stock_3'];
        $this->route('fetchOne', 'FROM `inventory_stock_sales_channel`', '3');

        $this->assertSame(
            'EXISTS (SELECT 1 FROM `inventory_stock_3` AS stock_i WHERE stock_i.sku = p.sku AND stock_i.is_salable = 1)',
            $this->lookup()->inStockCondition(1, 'p')
        );
    }

    public function testInStockConditionRejectsAnUnsafeAlias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lookup()->inStockCondition(1, 'e; DROP');
    }

    private function lookup(): StockStatusLookup
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return new StockStatusLookup($this->support(), $storeManager);
    }

    public function testProductsMissingFromTheIndexAreNotReported(): void
    {
        $this->seedVisits();
        $this->assertSame([], $this->rule()->evaluate(1, self::today()));
    }

    public function testBelowMinimumVolumeFindsNothing(): void
    {
        $this->seedVisits();
        $this->route('fetchAll', 'FROM `cataloginventory_stock_status`', [
            ['product_id' => 22, 'website_id' => 0, 'stock_status' => 0],
        ]);
        $this->assertSame([], $this->rule(7)->evaluate(1, self::today()));
    }

    public function testEmptyDataFindsNothing(): void
    {
        $this->assertSame([], $this->rule()->evaluate(1, self::today()));
        $this->assertCount(1, $this->queries);
    }

    public function testStoreFilterStoreCodeAndRealRowsOnly(): void
    {
        $this->route('fetchAll', 'FROM `panth_aiv_visit`', [
            ['store_id' => 2, 'path_hash' => 'h1', 'path' => '/luma/rain-jacket.html', 'visits' => 30],
        ]);
        $this->route('fetchAll', 'FROM `url_rewrite`', [
            ['request_path' => 'rain-jacket.html', 'entity_id' => 21, 'redirect_type' => 0],
        ]);
        $this->route('fetchAll', 'FROM `cataloginventory_stock_status`', [
            ['product_id' => 21, 'website_id' => 0, 'stock_status' => 0],
        ]);
        $findings = $this->rule(20, 'luma')->evaluate(2, self::today());

        $this->assertSame(['21'], $findings[0]->subjects);
        $this->assertRealRowsOnly(['panth_aiv_visit']);
        $visits = $this->queriesWith('panth_aiv_visit')[0];
        $this->assertStringContainsString('v.store_id = ?', $visits['sql']);
        $this->assertStringContainsString('v.source_code NOT LIKE ?', $visits['sql']);
        $this->assertSame(['2026-10-01 00:00:00', '2026-10-31 00:00:00', 'cand:%', 2], $visits['bind']);
        $this->assertSame([2, 'product', 'rain-jacket.html'], $this->queriesWith('url_rewrite')[0]['bind']);
    }

    public function testAllStoresResolvePerStoreView(): void
    {
        $this->route('fetchAll', 'FROM `panth_aiv_visit`', [
            ['store_id' => 1, 'path_hash' => 'h1', 'path' => '/rain-jacket.html', 'visits' => 12],
            ['store_id' => 2, 'path_hash' => 'h1', 'path' => '/rain-jacket.html', 'visits' => 9],
            ['store_id' => 0, 'path_hash' => 'h9', 'path' => '/x.html', 'visits' => 99],
        ]);
        $this->route('fetchAll', 'FROM `url_rewrite`', [
            ['request_path' => 'rain-jacket.html', 'entity_id' => 21, 'redirect_type' => 0],
        ]);
        $this->route('fetchAll', 'FROM `cataloginventory_stock_status`', [
            ['product_id' => 21, 'website_id' => 0, 'stock_status' => 0],
        ]);
        $findings = $this->rule()->evaluate(0, self::today());

        $this->assertSame(21, $findings[0]->affectedCount);
        $this->assertCount(2, $this->queriesWith('url_rewrite'));
        $this->assertStringNotContainsString('store_id = ?', $this->queries[0]['sql']);
    }
}
