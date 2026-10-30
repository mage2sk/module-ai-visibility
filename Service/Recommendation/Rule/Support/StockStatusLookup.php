<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation\Rule\Support;

use Magento\Store\Model\StoreManagerInterface;

/**
 * Out-of-stock check for a bounded list of products, without depending on the inventory modules.
 *
 * Multi-Source Inventory with a custom stock assigned to the store's website: the stock index table
 * inventory_stock_<id> (is_salable, joined by SKU). Everything else (single source, MSI default stock
 * or MSI not installed): cataloginventory_stock_status (stock_status), which is also what the MSI
 * default stock view reads. Products missing from the index are "unknown" and never reported.
 * inStockCondition() gives the same decision as a SQL condition for catalogue-wide counts.
 */
class StockStatusLookup
{
    public const MODE_LEGACY = 'legacy';
    public const MODE_MSI = 'msi';

    private const LEGACY_TABLE = 'cataloginventory_stock_status';
    private const CHANNEL_TABLE = 'inventory_stock_sales_channel';
    private const WEBSITE_TABLE = 'store_website';
    private const ENTITY_TABLE = 'catalog_product_entity';
    private const MSI_INDEX_PREFIX = 'inventory_stock_';
    private const CHANNEL_TYPE_WEBSITE = 'website';
    private const DEFAULT_STOCK_ID = 1;
    private const CHUNK = 500;

    /**
     * @var array<int, array{mode: string, stock_id: int, website_id: int}>
     */
    private array $modes = [];

    /**
     * @param RuleSupport $support
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly RuleSupport $support,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Which stock source applies to a store view
     *
     * @param int $storeId 0 = default scope (legacy stock status, website 0)
     * @return array{mode: string, stock_id: int, website_id: int}
     */
    public function mode(int $storeId): array
    {
        if (isset($this->modes[$storeId])) {
            return $this->modes[$storeId];
        }
        $mode = ['mode' => self::MODE_LEGACY, 'stock_id' => self::DEFAULT_STOCK_ID, 'website_id' => 0];
        if ($storeId > 0) {
            $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
            $mode['website_id'] = $websiteId;
            $connection = $this->support->connection();
            if ($connection->isTableExists($this->support->tableName(self::CHANNEL_TABLE))) {
                $stockId = (int)$connection->fetchOne(
                    'SELECT c.stock_id FROM ' . $this->support->table(self::CHANNEL_TABLE) . ' AS c'
                    . ' INNER JOIN ' . $this->support->table(self::WEBSITE_TABLE) . ' AS w ON w.code = c.code'
                    . ' WHERE c.type = ? AND w.website_id = ?',
                    [self::CHANNEL_TYPE_WEBSITE, $websiteId]
                );
                if ($stockId > 0
                    && $stockId !== self::DEFAULT_STOCK_ID
                    && $connection->isTableExists($this->support->tableName(self::MSI_INDEX_PREFIX . $stockId))
                ) {
                    $mode['mode'] = self::MODE_MSI;
                    $mode['stock_id'] = $stockId;
                }
            }
        }
        return $this->modes[$storeId] = $mode;
    }

    /**
     * SQL condition "this product is in stock (salable) for the store view", from the same stock source
     * as outOfStock(); products missing from the stock index do not match
     *
     * @param int $storeId
     * @param string $alias Alias of catalog_product_entity in the outer query (letters / underscore)
     * @return string
     */
    public function inStockCondition(int $storeId, string $alias = 'e'): string
    {
        if (!preg_match('/^[a-z_]+$/', $alias)) {
            throw new \InvalidArgumentException('Invalid table alias.');
        }
        $mode = $this->mode($storeId);
        if ($mode['mode'] === self::MODE_MSI) {
            return 'EXISTS (SELECT 1 FROM ' . $this->support->table(self::MSI_INDEX_PREFIX . (int)$mode['stock_id'])
                . ' AS stock_i WHERE stock_i.sku = ' . $alias . '.sku AND stock_i.is_salable = 1)';
        }
        $table = $this->support->table(self::LEGACY_TABLE);
        $status = static fn (string $name, int $websiteId): string => '(SELECT MAX(' . $name . '.stock_status) FROM '
            . $table . ' AS ' . $name . ' WHERE ' . $name . '.product_id = ' . $alias . '.entity_id AND ' . $name
            . '.website_id = ' . $websiteId . ')';
        // The website row wins over the website 0 row, like legacyStatus()
        return 'COALESCE(' . $status('stock_w', (int)$mode['website_id']) . ', ' . $status('stock_d', 0) . ', 0) = 1';
    }

    /**
     * Products of the list that are out of stock (not salable) for the store view
     *
     * @param int[] $productIds
     * @param int $storeId
     * @return int[] Out-of-stock product IDs (input order kept)
     */
    public function outOfStock(array $productIds, int $storeId): array
    {
        $ids = [];
        foreach ($productIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $mode = $this->mode($storeId);
        $status = $mode['mode'] === self::MODE_MSI
            ? $this->msiSalable(array_values($ids), $mode['stock_id'])
            : $this->legacyStatus(array_values($ids), $mode['website_id']);
        $result = [];
        foreach ($ids as $id) {
            if (isset($status[$id]) && $status[$id] === 0) {
                $result[] = $id;
            }
        }
        return $result;
    }

    /**
     * Salable flag per product from the MSI stock index (any salable row wins)
     *
     * @param int[] $ids
     * @param int $stockId
     * @return array<int, int>
     */
    private function msiSalable(array $ids, int $stockId): array
    {
        $connection = $this->support->connection();
        $result = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $sql = 'SELECT e.entity_id, MAX(i.is_salable) AS salable'
                . ' FROM ' . $this->support->table(self::ENTITY_TABLE) . ' AS e'
                . ' INNER JOIN ' . $this->support->table(self::MSI_INDEX_PREFIX . $stockId) . ' AS i ON i.sku = e.sku'
                . ' WHERE e.entity_id IN (' . RuleSupport::placeholders(count($chunk)) . ')'
                . ' GROUP BY e.entity_id';
            foreach ($connection->fetchPairs($sql, $chunk) as $id => $salable) {
                $result[(int)$id] = (int)$salable > 0 ? 1 : 0;
            }
        }
        return $result;
    }

    /**
     * Stock status per product from cataloginventory_stock_status (website row over website 0)
     *
     * @param int[] $ids
     * @param int $websiteId
     * @return array<int, int>
     */
    private function legacyStatus(array $ids, int $websiteId): array
    {
        $connection = $this->support->connection();
        $result = [];
        $exact = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $sql = 'SELECT s.product_id, s.website_id, MAX(s.stock_status) AS stock_status'
                . ' FROM ' . $this->support->table(self::LEGACY_TABLE) . ' AS s'
                . ' WHERE s.product_id IN (' . RuleSupport::placeholders(count($chunk)) . ')'
                . ' AND s.website_id IN (' . RuleSupport::placeholders(2) . ')'
                . ' GROUP BY s.product_id, s.website_id';
            $rows = $connection->fetchAll($sql, array_merge($chunk, [0, $websiteId]));
            foreach ($rows as $row) {
                $id = (int)$row['product_id'];
                $isWebsite = $websiteId > 0 && (int)$row['website_id'] === $websiteId;
                if (isset($exact[$id]) && !$isWebsite) {
                    continue;
                }
                $result[$id] = (int)$row['stock_status'] > 0 ? 1 : 0;
                if ($isWebsite) {
                    $exact[$id] = true;
                }
            }
        }
        return $result;
    }
}
