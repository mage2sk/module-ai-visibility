<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Health;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AiVisibility\Model\HealthResult;

/**
 * The dashboard numbers come from the half-hourly rollup cron job: it must have
 * finished successfully within the last two hours.
 */
class CronCheck extends AbstractCheck
{
    public const CODE = 'cron';

    public const JOB_CODE = 'panth_aiv_rollup';

    public const MAX_AGE_SECONDS = 7200;

    /**
     * @param ResourceConnection $resource
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
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
        $table = $this->resource->getTableName('cron_schedule');
        $lastSuccess = $connection->fetchOne(
            $connection->select()
                ->from($table, ['last' => new Expression('MAX(finished_at)')])
                ->where('job_code = ?', self::JOB_CODE)
                ->where('status = ?', 'success')
        );
        $lastError = $connection->fetchRow(
            $connection->select()
                ->from($table, ['messages', 'executed_at'])
                ->where('job_code = ?', self::JOB_CODE)
                ->where('status IN (?)', ['error', 'missed'])
                ->order('schedule_id DESC')
                ->limit(1)
        );
        $details = [];
        if (is_array($lastError) && $lastError !== []) {
            $details['last_error'] = mb_substr((string)($lastError['messages'] ?? ''), 0, 300);
        }

        $fix = (string)__(
            'Fix: make sure Magento cron runs every minute (bin/magento cron:install, or a crontab line '
            . 'running bin/magento cron:run), then check var/log/cron.log for errors.'
        );
        $timestamp = $this->utcTimestamp($lastSuccess);
        if ($timestamp === null) {
            return new HealthResult(
                HealthResult::STATUS_ERROR,
                (string)__('The rollup cron job (%1) has never finished, so the dashboard stays empty. ', self::JOB_CODE)
                . $fix,
                $details
            );
        }
        $age = (int)$this->dateTime->gmtTimestamp() - $timestamp;
        $details['last_success'] = (string)$lastSuccess;
        if ($age <= self::MAX_AGE_SECONDS) {
            return new HealthResult(
                HealthResult::STATUS_OK,
                (string)__('The rollup cron job last finished %1 ago.', $this->ago($age)),
                $details
            );
        }
        return new HealthResult(
            HealthResult::STATUS_ERROR,
            (string)__(
                'The rollup cron job last finished %1 ago (expected every 30 minutes), so the dashboard is out of date. ',
                $this->ago($age)
            ) . $fix,
            $details
        );
    }
}
