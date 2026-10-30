<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Health;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Panth\AiVisibility\Model\HealthResult;

/**
 * Total size (data + indexes) of the module's tables.
 */
class TableSizeCheck extends AbstractCheck
{
    public const CODE = 'table_size';

    public const LIMIT_BYTES = 1073741824;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @inheritdoc
     */
    public function code(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    protected function execute(): HealthResult
    {
        $connection = $this->resource->getConnection();
        $prefix = $this->resource->getTableName('panth_aiv_');
        $like = addcslashes($prefix, '\\%_') . '%';
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from(
                    ['t' => 'information_schema.TABLES'],
                    ['TABLE_NAME', 'bytes' => new Expression('COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)')]
                )
                ->where('t.TABLE_SCHEMA = DATABASE()')
                ->where('t.TABLE_NAME LIKE ?', $like)
        );
        $total = 0;
        $largest = '';
        $largestBytes = -1;
        foreach ($rows as $table => $bytes) {
            $bytes = (int)$bytes;
            $total += $bytes;
            if ($bytes > $largestBytes) {
                $largestBytes = $bytes;
                $largest = (string)$table;
            }
        }
        // information_schema sizes are MySQL statistics (refreshed by the server, not live), so an estimate.
        $details = [
            'total_bytes' => $total, 'largest_table' => $largest, 'tables' => count($rows), 'approximate' => true,
        ];
        $size = $this->formatBytes($total);
        if ($total <= self::LIMIT_BYTES) {
            return new HealthResult(
                HealthResult::STATUS_OK,
                (string)__('The AI Visibility tables use about %1 in total (MySQL table statistics).', $size),
                $details
            );
        }
        return new HealthResult(
            HealthResult::STATUS_WARN,
            (string)__(
                'The AI Visibility tables use about %1 (largest: %2). Fix: in the AI Visibility configuration lower '
                . '"Keep Detailed Crawler Hits (days)" and "Max Detailed Hits per Day"; old rows are removed by the '
                . 'nightly retention cron job. Daily summaries are kept, so the dashboard keeps its history.',
                $size,
                $largest
            ),
            $details
        );
    }

    /**
     * Bytes as MB / GB text
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= self::LIMIT_BYTES) {
            return sprintf('%.1f GB', $bytes / self::LIMIT_BYTES);
        }
        return sprintf('%.1f MB', $bytes / 1048576);
    }
}
