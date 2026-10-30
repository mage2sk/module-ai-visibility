<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Health;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\HealthResult;

/**
 * When the access log import is enabled, every configured file must be readable and
 * must have been read by the import cron job within the last 30 minutes.
 */
class LogImportCheck extends AbstractCheck
{
    public const CODE = 'log_import';

    public const TABLE = 'panth_aiv_import_state';

    public const MAX_AGE_SECONDS = 1800;

    /**
     * @param Config $config
     * @param ResourceConnection $resource
     * @param File $fileDriver
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resource,
        private readonly File $fileDriver,
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
        if (!$this->config->logImportEnabled()) {
            return new HealthResult(
                HealthResult::STATUS_OK,
                (string)__(
                    'Access log import is off. That is right for stores using Magento\'s built-in page cache; '
                    . 'stores behind Varnish or a page-caching CDN should enable it.'
                )
            );
        }
        $files = $this->config->logImportFiles();
        if ($files === []) {
            return new HealthResult(
                HealthResult::STATUS_ERROR,
                (string)__(
                    'Access log import is on, but no valid log file is configured. Fix: in the AI Visibility '
                    . 'configuration (Access Log Import) add one line per file: "/absolute/path/access.log | store_code".'
                )
            );
        }

        $states = $this->importStates(array_column($files, 'path'));
        $now = (int)$this->dateTime->gmtTimestamp();
        $unreadable = [];
        $failed = [];
        $stale = [];
        $details = [];
        foreach ($files as $file) {
            $path = (string)$file['path'];
            $readable = $this->isReadable($path);
            $updated = $this->utcTimestamp($states[sha1($path)] ?? null);
            $lastError = $this->lastError($path);
            $details[] = [
                'path' => $path,
                'store_code' => (string)$file['store_code'],
                'readable' => $readable,
                'last_import' => $states[sha1($path)] ?? null,
                'last_error' => $lastError,
            ];
            if ($lastError !== '') {
                $failed[] = (string)__('%1: %2', $path, $lastError);
            } elseif (!$readable) {
                $unreadable[] = $path;
            } elseif ($updated === null || $now - $updated > self::MAX_AGE_SECONDS) {
                $stale[] = $updated === null
                    ? (string)__('%1 (never read)', $path)
                    : (string)__('%1 (last read %2 ago)', $path, $this->ago($now - $updated));
            }
        }
        if ($failed !== []) {
            return new HealthResult(
                HealthResult::STATUS_ERROR,
                (string)__(
                    'The last access log import ended with an error: %1. Fix the cause, then run '
                    . 'bin/magento panth:aivisibility:import-logs and read its output.',
                    implode(' | ', $failed)
                ),
                ['files' => $details]
            );
        }
        if ($unreadable !== []) {
            return new HealthResult(
                HealthResult::STATUS_ERROR,
                (string)__(
                    'These log files are missing, not regular files or cannot be read by PHP: %1. Fix: check the '
                    . 'path (it must be a file, not a folder), and give the user that runs '
                    . 'Magento cron read access to the file and its folder (for example add it to the "adm" group).',
                    implode(', ', $unreadable)
                ),
                ['files' => $details]
            );
        }
        if ($stale !== []) {
            return new HealthResult(
                HealthResult::STATUS_ERROR,
                (string)__(
                    'These log files were not imported in the last 30 minutes: %1. Fix: make sure Magento cron runs '
                    . '(job panth_aiv_import_logs, every 5 minutes) or run bin/magento panth:aivisibility:import-logs '
                    . 'and read its output.',
                    implode(', ', $stale)
                ),
                ['files' => $details]
            );
        }
        return new HealthResult(
            HealthResult::STATUS_OK,
            (string)__('All %1 configured access log file(s) are readable and were imported recently.', count($files)),
            ['files' => $details]
        );
    }

    /**
     * Last import time per file key: sha1(path) => updated_at
     *
     * @param string[] $paths
     * @return array<string, string>
     */
    private function importStates(array $paths): array
    {
        $keys = array_map('sha1', $paths);
        $connection = $this->resource->getConnection();
        return $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['file_key', 'updated_at'])
                ->where('file_key IN (?)', $keys)
        );
    }

    /**
     * Error saved by the last import run of a file ('' when it ended fine)
     *
     * @param string $path
     * @return string
     */
    private function lastError(string $path): string
    {
        try {
            $connection = $this->resource->getConnection();
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->resource->getTableName(self::TABLE), ['last_error'])
                    ->where('file_key = ?', sha1($path))
            );
        } catch (\Throwable $e) {
            return '';
        }
        return is_array($row) && is_string($row['last_error'] ?? null) ? trim($row['last_error']) : '';
    }

    /**
     * Whether the path is an existing, readable regular file (never throws)
     *
     * @param string $path
     * @return bool
     */
    private function isReadable(string $path): bool
    {
        try {
            return $this->fileDriver->isExists($path) && $this->fileDriver->isFile($path)
                && $this->fileDriver->isReadable($path);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
