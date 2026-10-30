<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Cron;

use Panth\AiVisibility\Cron\ImportLogs;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\LogImport\LogImporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportLogsTest extends TestCase
{
    public function testDoesNothingWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('logImportEnabled')->willReturn(false);
        $importer = $this->createMock(LogImporter::class);
        $importer->expects($this->never())->method('run');

        (new ImportLogs($config, $importer, $this->createStub(LoggerInterface::class)))->execute();
    }

    public function testLogsFileErrorsAsWarnings(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('logImportEnabled')->willReturn(true);
        $importer = $this->createMock(LogImporter::class);
        $importer->expects($this->once())->method('run')->willReturn([
            'status' => LogImporter::STATUS_OK,
            'dry_run' => false,
            'max_lines' => 1000,
            'files' => [
                ['path' => '/var/log/a.log', 'error' => 'Log file "/var/log/a.log" does not exist.'],
                ['path' => '/var/log/b.log', 'error' => ''],
            ],
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('does not exist'));

        (new ImportLogs($config, $importer, $logger))->execute();
    }

    public function testNeverThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('logImportEnabled')->willReturn(true);
        $importer = $this->createStub(LogImporter::class);
        $importer->method('run')->willThrowException(new \RuntimeException('table missing'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('table missing'));

        (new ImportLogs($config, $importer, $logger))->execute();
    }

    public function testWarnsWhenNoFileIsConfigured(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('logImportEnabled')->willReturn(true);
        $importer = $this->createStub(LogImporter::class);
        $importer->method('run')->willReturn([
            'status' => LogImporter::STATUS_NO_FILES, 'dry_run' => false, 'max_lines' => 1000, 'files' => [],
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('no valid log file'));

        (new ImportLogs($config, $importer, $logger))->execute();
    }
}
