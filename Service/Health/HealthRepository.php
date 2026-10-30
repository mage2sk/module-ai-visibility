<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Health;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AiVisibility\Api\HealthRepositoryInterface;
use Panth\AiVisibility\Model\HealthResult;

/**
 * Latest health check results (panth_aiv_health), one row per check code.
 */
class HealthRepository implements HealthRepositoryInterface
{
    public const TABLE = 'panth_aiv_health';

    public const STATUSES = [
        HealthResult::STATUS_OK,
        HealthResult::STATUS_WARN,
        HealthResult::STATUS_ERROR,
        HealthResult::STATUS_UNKNOWN,
    ];

    /**
     * Display labels of the built-in checks (unknown codes are humanised)
     */
    public const LABELS = [
        PageCacheCheck::CODE => 'Page cache coverage',
        CronCheck::CODE => 'Cron jobs',
        CollectorCheck::CODE => 'Visit collector',
        ConsentCheck::CODE => 'Cookie consent',
        TableSizeCheck::CODE => 'Table size',
        LogImportCheck::CODE => 'Access log import',
        TrackingCheck::CODE => 'Tracking switches',
    ];

    /**
     * Display order of the built-in checks
     */
    private const ORDER = [
        TrackingCheck::CODE,
        CronCheck::CODE,
        PageCacheCheck::CODE,
        LogImportCheck::CODE,
        CollectorCheck::CODE,
        ConsentCheck::CODE,
        TableSizeCheck::CODE,
    ];

    /**
     * @param ResourceConnection $resource
     * @param Json $json
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json
    ) {
    }

    /**
     * @inheritdoc
     */
    public function save(string $code, HealthResult $result): void
    {
        $code = mb_substr(trim($code), 0, 64);
        if ($code === '') {
            return;
        }
        $status = in_array($result->status, self::STATUSES, true) ? $result->status : HealthResult::STATUS_UNKNOWN;
        try {
            $details = $result->details === [] ? null : $this->json->serialize($result->details);
        } catch (\Throwable $e) {
            $details = null;
        }
        $connection = $this->resource->getConnection();
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'check_code' => $code,
                'status' => $status,
                'message' => mb_substr($result->message, 0, 512),
                'details' => $details,
                'checked_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['status', 'message', 'details', 'checked_at']
        );
    }

    /**
     * @inheritdoc
     */
    public function latest(): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()->from(
                $this->resource->getTableName(self::TABLE),
                ['check_code', 'status', 'message', 'details', 'checked_at']
            )
        );
        $result = [];
        foreach ($rows as $row) {
            $code = (string)$row['check_code'];
            $details = [];
            if (is_string($row['details'] ?? null) && $row['details'] !== '') {
                try {
                    $decoded = $this->json->unserialize($row['details']);
                    $details = is_array($decoded) ? $decoded : [];
                } catch (\Throwable $e) {
                    $details = [];
                }
            }
            $status = (string)$row['status'];
            $result[$code] = [
                'label' => self::labelFor($code),
                'status' => in_array($status, self::STATUSES, true) ? $status : HealthResult::STATUS_UNKNOWN,
                'message' => (string)$row['message'],
                'details' => $details,
                'checked_at' => (string)$row['checked_at'],
            ];
        }
        uksort($result, static function (string $a, string $b): int {
            $posA = array_search($a, self::ORDER, true);
            $posB = array_search($b, self::ORDER, true);
            $posA = $posA === false ? PHP_INT_MAX : $posA;
            $posB = $posB === false ? PHP_INT_MAX : $posB;
            return $posA === $posB ? strcmp($a, $b) : $posA <=> $posB;
        });
        return $result;
    }

    /**
     * Display label of a check code
     *
     * @param string $code
     * @return string
     */
    public static function labelFor(string $code): string
    {
        return self::LABELS[$code] ?? ucfirst(str_replace('_', ' ', $code));
    }
}
