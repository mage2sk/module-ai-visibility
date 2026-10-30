<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation\Rule\Support;

/**
 * Content checks for a bounded list of products: description length (words) and gallery images
 * without a label (the image alt text). Store values win over default values. Works with
 * entity_id (Open Source) and row_id (staging) attribute links, like Report\CatalogLookup.
 */
class ProductContentLookup
{
    private const ENTITY_TYPE = 'catalog_product';
    private const ENTITY_TABLE = 'catalog_product_entity';
    private const TEXT_TABLE = 'catalog_product_entity_text';
    private const GALLERY_TABLE = 'catalog_product_entity_media_gallery';
    private const GALLERY_VALUE_TABLE = 'catalog_product_entity_media_gallery_value';
    private const GALLERY_ENTITY_TABLE = 'catalog_product_entity_media_gallery_value_to_entity';
    private const MEDIA_TYPE_IMAGE = 'image';
    private const CHUNK = 500;

    /**
     * @var array<string, int|null>
     */
    private array $attributeIds = [];

    /**
     * @var array<string, string>
     */
    private array $linkFields = [];

    /**
     * @param RuleSupport $support
     */
    public function __construct(
        private readonly RuleSupport $support
    ) {
    }

    /**
     * Words in the description of each product (store value over default, 0 when empty)
     *
     * Products that no longer exist are left out.
     *
     * @param int[] $productIds
     * @param int $storeId 0 = default values only
     * @return array<int, int> product id => words
     */
    public function descriptionWords(array $productIds, int $storeId): array
    {
        $ids = self::cleanIds($productIds);
        if ($ids === []) {
            return [];
        }
        $attributeId = $this->attributeId('description');
        if ($attributeId === null) {
            return [];
        }
        $connection = $this->support->connection();
        $link = $this->linkField(self::ENTITY_TABLE);
        $text = $this->support->table(self::TEXT_TABLE);
        $result = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $bind = [$attributeId];
            $sql = 'SELECT e.entity_id, d.value AS default_value'
                . ($storeId > 0 ? ', s.value AS store_value' : ', NULL AS store_value')
                . ' FROM ' . $this->support->table(self::ENTITY_TABLE) . ' AS e'
                . ' LEFT JOIN ' . $text . ' AS d ON d.' . $link . ' = e.' . $link
                . ' AND d.attribute_id = ? AND d.store_id = 0';
            if ($storeId > 0) {
                $sql .= ' LEFT JOIN ' . $text . ' AS s ON s.' . $link . ' = e.' . $link
                    . ' AND s.attribute_id = ? AND s.store_id = ?';
                array_push($bind, $attributeId, $storeId);
            }
            $sql .= ' WHERE e.entity_id IN (' . RuleSupport::placeholders(count($chunk)) . ')';
            $bind = array_merge($bind, $chunk);
            foreach ($connection->fetchAll($sql, $bind) as $row) {
                $value = $row['store_value'] ?? null;
                if ($value === null) {
                    $value = $row['default_value'] ?? null;
                }
                $id = (int)$row['entity_id'];
                $words = self::countWords(is_scalar($value) ? (string)$value : '');
                $result[$id] = max($result[$id] ?? 0, $words);
            }
        }
        return $result;
    }

    /**
     * Enabled gallery images per product and how many of them have no label (alt text)
     *
     * Products without images are left out.
     *
     * @param int[] $productIds
     * @param int $storeId 0 = default values only
     * @return array<int, array{images: int, missing: int}>
     */
    public function imageLabels(array $productIds, int $storeId): array
    {
        $ids = self::cleanIds($productIds);
        if ($ids === []) {
            return [];
        }
        $connection = $this->support->connection();
        $link = $this->linkField(self::ENTITY_TABLE);
        $valueTable = $this->support->table(self::GALLERY_VALUE_TABLE);
        $label = $storeId > 0
            ? "COALESCE(NULLIF(TRIM(s.label), ''), NULLIF(TRIM(d.label), ''))"
            : "NULLIF(TRIM(d.label), '')";
        $disabled = $storeId > 0 ? 'COALESCE(s.disabled, d.disabled, 0)' : 'COALESCE(d.disabled, 0)';
        $result = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $bind = [self::MEDIA_TYPE_IMAGE];
            $sql = 'SELECT e.entity_id, COUNT(DISTINCT g.value_id) AS images,'
                . ' COUNT(DISTINCT CASE WHEN ' . $label . ' IS NULL THEN g.value_id END) AS missing'
                . ' FROM ' . $this->support->table(self::ENTITY_TABLE) . ' AS e'
                . ' INNER JOIN ' . $this->support->table(self::GALLERY_ENTITY_TABLE) . ' AS ge'
                . ' ON ge.' . $link . ' = e.' . $link
                . ' INNER JOIN ' . $this->support->table(self::GALLERY_TABLE) . ' AS g'
                . ' ON g.value_id = ge.value_id AND g.disabled = 0 AND g.media_type = ?'
                . ' LEFT JOIN ' . $valueTable . ' AS d ON d.value_id = g.value_id AND d.' . $link . ' = e.' . $link
                . ' AND d.store_id = 0';
            if ($storeId > 0) {
                $sql .= ' LEFT JOIN ' . $valueTable . ' AS s ON s.value_id = g.value_id AND s.' . $link
                    . ' = e.' . $link . ' AND s.store_id = ?';
                $bind[] = $storeId;
            }
            $sql .= ' WHERE e.entity_id IN (' . RuleSupport::placeholders(count($chunk)) . ')'
                . ' AND ' . $disabled . ' = 0'
                . ' GROUP BY e.entity_id';
            $bind = array_merge($bind, $chunk);
            foreach ($connection->fetchAll($sql, $bind) as $row) {
                $images = (int)$row['images'];
                if ($images > 0) {
                    $result[(int)$row['entity_id']] = ['images' => $images, 'missing' => (int)$row['missing']];
                }
            }
        }
        return $result;
    }

    /**
     * Visible words of an HTML or plain-text description (scripts, styles, tags and directives removed)
     *
     * @param string $html
     * @return int
     */
    public static function countWords(string $html): int
    {
        if (trim($html) === '') {
            return 0;
        }
        $text = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $text = (string)preg_replace('/\{\{[^}]*\}\}/', ' ', $text);
        $text = strip_tags(str_replace('<', ' <', $text));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $count = preg_match_all('/[\p{L}\p{N}]+(?:[\'\x{2019}\-][\p{L}\p{N}]+)*/u', $text);
        return $count === false ? 0 : $count;
    }

    /**
     * Positive, unique integer IDs
     *
     * @param array $ids
     * @return int[]
     */
    private static function cleanIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        return array_values($clean);
    }

    /**
     * Product EAV attribute id, cached per instance
     *
     * @param string $code
     * @return int|null
     */
    private function attributeId(string $code): ?int
    {
        if (!array_key_exists($code, $this->attributeIds)) {
            $id = (int)$this->support->connection()->fetchOne(
                'SELECT a.attribute_id FROM ' . $this->support->table('eav_attribute') . ' AS a'
                . ' INNER JOIN ' . $this->support->table('eav_entity_type') . ' AS t'
                . ' ON t.entity_type_id = a.entity_type_id'
                . ' WHERE t.entity_type_code = ? AND a.attribute_code = ?',
                [self::ENTITY_TYPE, $code]
            );
            $this->attributeIds[$code] = $id > 0 ? $id : null;
        }
        return $this->attributeIds[$code];
    }

    /**
     * Column that links EAV values to the entity: row_id with staging, else entity_id
     *
     * @param string $entityTable
     * @return string
     */
    private function linkField(string $entityTable): string
    {
        if (!isset($this->linkFields[$entityTable])) {
            $this->linkFields[$entityTable] = $this->support->connection()->tableColumnExists(
                $this->support->tableName($entityTable),
                'row_id'
            ) ? 'row_id' : 'entity_id';
        }
        return $this->linkFields[$entityTable];
    }
}
