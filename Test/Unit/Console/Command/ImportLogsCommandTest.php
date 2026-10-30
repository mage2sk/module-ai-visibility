<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Console\Command;

use Panth\AiVisibility\Console\Command\ImportLogsCommand;
use Panth\AiVisibility\Service\LogImport\LogImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ImportLogsCommandTest extends TestCase
{
    /**
     * Per-file result with defaults
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function fileResult(array $overrides = []): array
    {
        return $overrides + [
            'path' => '/var/log/nginx/access.log', 'store_code' => 'default', 'store_id' => 1,
            'lines' => 120, 'hits' => 7, 'candidates' => 2, 'skipped' => 30, 'malformed' => 1,
            'start' => 100, 'end' => 9000, 'reset' => '', 'more' => false, 'error' => '',
        ];
    }

    /**
     * Run result
     *
     * @param string $status
     * @param array<int, array<string, mixed>> $files
     * @param bool $dryRun
     * @return array<string, mixed>
     */
    private static function runResult(string $status, array $files = [], bool $dryRun = false): array
    {
        return ['status' => $status, 'dry_run' => $dryRun, 'max_lines' => 1000, 'files' => $files];
    }

    public function testPrintsCountersPerFile(): void
    {
        $importer = $this->createMock(LogImporter::class);
        $importer->expects($this->once())->method('run')->with(null, false, false, false)
            ->willReturn(self::runResult(LogImporter::STATUS_OK, [
                self::fileResult(),
                self::fileResult([
                    'path' => '/var/log/varnish/varnishncsa.log', 'reset' => LogImporter::RESET_ROTATED,
                    'more' => true, 'start' => 0,
                ]),
            ]));
        $command = new ImportLogsCommand($importer);
        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame('panth:aivisibility:import-logs', $command->getName());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('/var/log/nginx/access.log (store default, id 1)', $display);
        $this->assertStringContainsString(
            'lines read: 120 | AI hits: 7 | candidates: 2 | skipped: 30 | malformed: 1',
            $display
        );
        $this->assertStringContainsString('bytes 100 -> 9000', $display);
        $this->assertStringContainsString('file was rotated', $display);
        $this->assertStringContainsString('line limit reached', $display);
        $this->assertStringNotContainsString('Dry run', $display);
    }

    public function testOptionsArePassedAndDryRunIsAnnounced(): void
    {
        $importer = $this->createMock(LogImporter::class);
        $importer->expects($this->once())->method('run')->with('/var/log/a.log', true, true, true)
            ->willReturn(
                self::runResult(LogImporter::STATUS_OK, [self::fileResult(['path' => '/var/log/a.log'])], true)
            );
        $tester = new CommandTester(new ImportLogsCommand($importer));

        $this->assertSame(Command::SUCCESS, $tester->execute([
            '--file' => '/var/log/a.log', '--dry-run' => true, '--from-start' => true,
        ]));
        $this->assertStringContainsString('Dry run: nothing is written.', $tester->getDisplay());
    }

    public function testFileErrorFailsTheCommand(): void
    {
        $importer = $this->createStub(LogImporter::class);
        $importer->method('run')->willReturn(self::runResult(LogImporter::STATUS_OK, [
            self::fileResult(['error' => 'Log file "/var/log/x.log" does not exist.', 'store_id' => null,
                'lines' => 0]),
        ]));
        $tester = new CommandTester(new ImportLogsCommand($importer));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('does not exist', $tester->getDisplay());
        $this->assertStringContainsString('(store default)', $tester->getDisplay());
    }

    /**
     * Run statuses that stop the command with a message
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function failingStatuses(): array
    {
        return [
            'disabled' => [LogImporter::STATUS_DISABLED, 'Access log import is disabled'],
            'locked' => [LogImporter::STATUS_LOCKED, 'Another log import is running'],
            'no files' => [LogImporter::STATUS_NO_FILES, 'No valid log file is configured'],
            'unknown file' => [LogImporter::STATUS_UNKNOWN_FILE, 'is not one of the configured log files'],
        ];
    }

    /**
     * @dataProvider failingStatuses
     * @param string $status
     * @param string $message
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failingStatuses')]
    public function testFailingStatuses(string $status, string $message): void
    {
        $importer = $this->createStub(LogImporter::class);
        $importer->method('run')->willReturn(self::runResult($status));
        $tester = new CommandTester(new ImportLogsCommand($importer));

        $this->assertSame(Command::FAILURE, $tester->execute(['--file' => '/var/log/<b>.log']));
        $this->assertStringContainsString($message, $tester->getDisplay());
    }
}
