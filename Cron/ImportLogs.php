<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Cron;

use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\LogImport\LogImporter;
use Psr\Log\LoggerInterface;

/**
 * Cron panth_aiv_import_logs (every 5 minutes): reads new access log lines.
 *
 * No-op unless Access Log Import is enabled. Never throws: file problems are logged as warnings
 * and reported by the Log Import health check.
 */
class ImportLogs
{
    /**
     * @param Config $config
     * @param LogImporter $importer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly LogImporter $importer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run one import pass
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            if (!$this->config->logImportEnabled()) {
                return;
            }
            $result = $this->importer->run();
            if ($result['status'] === LogImporter::STATUS_NO_FILES) {
                $this->logger->warning(
                    '[Panth_AiVisibility] log import is enabled but no valid log file is configured.'
                );
                return;
            }
            foreach ($result['files'] as $file) {
                if ($file['error'] !== '') {
                    $this->logger->warning(sprintf(
                        '[Panth_AiVisibility] log import %s: %s',
                        $file['path'],
                        $file['error']
                    ));
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] log import cron failed: ' . $e->getMessage());
        }
    }
}
