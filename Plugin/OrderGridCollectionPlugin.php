<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Plugin;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Source\AttributionModel;
use Psr\Log\LoggerInterface;

/**
 * Adds the ai_source field to the admin order grid collection.
 *
 * LEFT JOINs panth_aiv_order (1:1 on order_id, so the row count never changes) under a
 * module-specific alias and maps the ai_source filter / sort field to the first or last touch
 * column, depending on general/attribution_model. Simulated attribution rows are not joined.
 * Every other data source is returned untouched.
 */
class OrderGridCollectionPlugin
{
    public const REQUEST_NAME = 'sales_order_grid_data_source';
    public const FIELD = 'ai_source';
    public const ALIAS = 'panth_aiv_ord';

    private const TABLE = 'panth_aiv_order';

    /**
     * Whether panth_aiv_order exists (checked once per request)
     *
     * @var bool|null
     */
    private ?bool $tableExists = null;

    /**
     * @param ResourceConnection $resource
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Join the AI attribution onto the sales order grid collection
     *
     * @param CollectionFactory $subject
     * @param mixed $result
     * @param mixed $requestName
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetReport(CollectionFactory $subject, $result, $requestName)
    {
        if ($requestName !== self::REQUEST_NAME || !$result instanceof AbstractDb) {
            return $result;
        }
        try {
            $select = $result->getSelect();
            if (isset($select->getPart(Select::FROM)[self::ALIAS]) || !$this->tableExists()) {
                return $result;
            }
            $column = self::ALIAS . '.' . $this->sourceColumn();
            $select->joinLeft(
                [self::ALIAS => $this->resource->getTableName(self::TABLE)],
                self::ALIAS . '.order_id = main_table.entity_id AND ' . self::ALIAS . '.is_simulated = 0',
                [self::FIELD => $column]
            );
            $result->addFilterToMap(self::FIELD, $column);
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] Order grid AI source join skipped: ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * Whether the attribution table exists (the grid must keep working when it does not, e.g. mid-upgrade)
     *
     * @return bool
     */
    private function tableExists(): bool
    {
        if ($this->tableExists === null) {
            try {
                $this->tableExists = $this->resource->getConnection()->isTableExists(
                    $this->resource->getTableName(self::TABLE)
                );
            } catch (\Throwable $e) {
                $this->tableExists = false;
            }
        }
        return $this->tableExists;
    }

    /**
     * Attribution column credited by the configured model
     *
     * @return string
     */
    private function sourceColumn(): string
    {
        return $this->config->attributionModel() === AttributionModel::LAST ? 'last_source' : 'first_source';
    }
}
