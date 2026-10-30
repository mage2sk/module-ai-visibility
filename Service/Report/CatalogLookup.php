<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Report;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Catalog lookups for the dashboard: entity names for the top pages table and the
 * product coverage numbers. Plain batch queries (no models, no collections), so a page
 * with many rows costs one query per entity type. Works with entity_id (Open Source) and
 * row_id (staging) attribute links.
 */
class CatalogLookup
{
    private const VISIBILITY_IN_CATALOG_OR_SEARCH = [2, 3, 4];
    private const STATUS_ENABLED = 1;

    /**
     * @var array<string, int|null>
     */
    private array $attributeIds = [];

    /**
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Display names for product, category and CMS page references
     *
     * @param array<int, array{entity_type: ?string, entity_id: ?int}> $refs
     * @param int $storeId 0 = default values
     * @return array<string, string> "type:id" => name
     */
    public function entityNames(array $refs, int $storeId): array
    {
        $ids = ['product' => [], 'category' => [], 'cms' => []];
        foreach ($refs as $ref) {
            $type = (string)($ref['entity_type'] ?? '');
            $id = (int)($ref['entity_id'] ?? 0);
            if ($id > 0 && isset($ids[$type])) {
                $ids[$type][$id] = $id;
            }
        }
        $names = [];
        try {
            foreach ($this->eavNames('catalog_product', 'catalog_product_entity', $ids['product'], $storeId) as $id => $n) {
                $names['product:' . $id] = $n;
            }
            foreach ($this->eavNames('catalog_category', 'catalog_category_entity', $ids['category'], $storeId) as $id => $n) {
                $names['category:' . $id] = $n;
            }
            if ($ids['cms'] !== []) {
                $connection = $this->connection();
                $select = $connection->select()
                    ->from($this->resource->getTableName('cms_page'), ['page_id', 'title'])
                    ->where('page_id IN (?)', array_values($ids['cms']));
                foreach ($connection->fetchPairs($select) as $id => $title) {
                    $names['cms:' . $id] = (string)$title;
                }
            }
        } catch (\Throwable $e) {
            return $names;
        }
        return $names;
    }

    /**
     * Enabled, visible products of the store's website and how many of them never appeared
     * as a product page in panth_aiv_path_daily since $sinceDay
     *
     * @param int $storeId 0 = all websites, default attribute values
     * @param string $sinceDay 'Y-m-d'
     * @param bool $includeSim
     * @return array{eligible: int, never: int}
     */
    public function productCoverage(int $storeId, string $sinceDay, bool $includeSim): array
    {
        $connection = $this->connection();
        $select = $this->eligibleProducts($storeId);
        if ($select === null) {
            return ['eligible' => 0, 'never' => 0];
        }
        $count = clone $select;
        $count->columns(['total' => new Expression('COUNT(DISTINCT e.entity_id)')]);
        $eligible = (int)$connection->fetchOne($count);

        $seen = $connection->select()
            ->from(['p' => $this->resource->getTableName(ReportRepository::PATH_TABLE)], [new Expression('1')])
            ->where("p.entity_type = 'product'")
            ->where('p.entity_id = e.entity_id')
            ->where('p.day >= ?', $sinceDay);
        if ($storeId > 0) {
            $seen->where('p.store_id = ?', $storeId);
        }
        if (!$includeSim) {
            $seen->where('p.is_simulated = 0');
        }
        $never = clone $select;
        $never->columns(['total' => new Expression('COUNT(DISTINCT e.entity_id)')])
            ->where('NOT EXISTS (?)', new Expression($seen->assemble()));
        return ['eligible' => $eligible, 'never' => (int)$connection->fetchOne($never)];
    }

    /**
     * Products with status enabled and visibility catalog/search/both (store value over default)
     *
     * @param int $storeId
     * @return Select|null
     */
    private function eligibleProducts(int $storeId): ?Select
    {
        $statusId = $this->attributeId('catalog_product', 'status');
        $visibilityId = $this->attributeId('catalog_product', 'visibility');
        if ($statusId === null || $visibilityId === null) {
            return null;
        }
        $connection = $this->connection();
        $entityTable = $this->resource->getTableName('catalog_product_entity');
        $intTable = $this->resource->getTableName('catalog_product_entity_int');
        $link = $this->linkField($entityTable);

        $select = $connection->select()->from(['e' => $entityTable], []);
        $status = 'sd.value';
        $visibility = 'vd.value';
        $select->join(
            ['sd' => $intTable],
            $connection->quoteInto("sd.$link = e.$link AND sd.store_id = 0 AND sd.attribute_id = ?", $statusId),
            []
        )->join(
            ['vd' => $intTable],
            $connection->quoteInto("vd.$link = e.$link AND vd.store_id = 0 AND vd.attribute_id = ?", $visibilityId),
            []
        );
        if ($storeId > 0) {
            $select->joinLeft(
                ['ss' => $intTable],
                $connection->quoteInto("ss.$link = e.$link AND ss.attribute_id = $statusId AND ss.store_id = ?", $storeId),
                []
            )->joinLeft(
                ['vs' => $intTable],
                $connection->quoteInto("vs.$link = e.$link AND vs.attribute_id = $visibilityId AND vs.store_id = ?", $storeId),
                []
            );
            $status = 'COALESCE(ss.value, sd.value)';
            $visibility = 'COALESCE(vs.value, vd.value)';
            $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
            $select->join(
                ['w' => $this->resource->getTableName('catalog_product_website')],
                $connection->quoteInto('w.product_id = e.entity_id AND w.website_id = ?', $websiteId),
                []
            );
        }
        $select->where("$status = ?", self::STATUS_ENABLED)
            ->where("$visibility IN (?)", self::VISIBILITY_IN_CATALOG_OR_SEARCH);
        return $select;
    }

    /**
     * Names from the EAV "name" attribute: store value over default value
     *
     * @param string $entityTypeCode
     * @param string $entityTable
     * @param int[] $ids
     * @param int $storeId
     * @return array<int, string>
     */
    private function eavNames(string $entityTypeCode, string $entityTable, array $ids, int $storeId): array
    {
        if ($ids === []) {
            return [];
        }
        $attributeId = $this->attributeId($entityTypeCode, 'name');
        if ($attributeId === null) {
            return [];
        }
        $connection = $this->connection();
        $table = $this->resource->getTableName($entityTable);
        $link = $this->linkField($table);
        $select = $connection->select()
            ->from(['e' => $table], ['entity_id'])
            ->join(['v' => $table . '_varchar'], "v.$link = e.$link", ['value', 'store_id'])
            ->where('e.entity_id IN (?)', array_values($ids))
            ->where('v.attribute_id = ?', $attributeId)
            ->where('v.store_id IN (?)', array_unique([0, max(0, $storeId)]))
            ->order('v.store_id ASC');
        $names = [];
        foreach ($connection->fetchAll($select) as $row) {
            if ((string)$row['value'] !== '') {
                $names[(int)$row['entity_id']] = (string)$row['value'];
            }
        }
        return $names;
    }

    /**
     * EAV attribute id, cached per request
     *
     * @param string $entityTypeCode
     * @param string $attributeCode
     * @return int|null
     */
    private function attributeId(string $entityTypeCode, string $attributeCode): ?int
    {
        $key = $entityTypeCode . '/' . $attributeCode;
        if (!array_key_exists($key, $this->attributeIds)) {
            $connection = $this->connection();
            $select = $connection->select()
                ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_id'])
                ->join(
                    ['t' => $this->resource->getTableName('eav_entity_type')],
                    't.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('t.entity_type_code = ?', $entityTypeCode)
                ->where('a.attribute_code = ?', $attributeCode);
            $id = (int)$connection->fetchOne($select);
            $this->attributeIds[$key] = $id > 0 ? $id : null;
        }
        return $this->attributeIds[$key];
    }

    /**
     * Column that links EAV values to the entity: row_id with staging, else entity_id
     *
     * @param string $entityTable
     * @return string
     */
    private function linkField(string $entityTable): string
    {
        return $this->connection()->tableColumnExists($entityTable, 'row_id') ? 'row_id' : 'entity_id';
    }

    /**
     * Default connection
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
