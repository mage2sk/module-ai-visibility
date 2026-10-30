<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Console\Command;

use Panth\AiVisibility\Service\LogImport\LogImporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * panth:aivisibility:import-logs [--file=] [--dry-run] [--from-start --force]
 */
class ImportLogsCommand extends Command
{
    public const NAME = 'panth:aivisibility:import-logs';

    private const OPTION_FILE = 'file';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_FROM_START = 'from-start';
    private const OPTION_FORCE = 'force';

    /**
     * @param LogImporter $importer
     * @param string|null $name
     */
    public function __construct(
        private readonly LogImporter $importer,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Import AI crawler hits from the configured web server access logs.')
            ->addOption(
                self::OPTION_FILE,
                null,
                InputOption::VALUE_REQUIRED,
                'Only this configured log file (absolute path)'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Parse and count only; write no hits and no offsets (works while import is disabled)'
            )
            ->addOption(
                self::OPTION_FROM_START,
                null,
                InputOption::VALUE_NONE,
                'Ignore the saved position and read the file(s) from the beginning (needs --force unless --dry-run)'
            )
            ->addOption(
                self::OPTION_FORCE,
                null,
                InputOption::VALUE_NONE,
                'Confirm --from-start: lines imported before are imported again and counted twice'
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getOption(self::OPTION_FILE);
        $file = is_string($file) && trim($file) !== '' ? trim($file) : null;
        $dryRun = (bool)$input->getOption(self::OPTION_DRY_RUN);
        $fromStart = (bool)$input->getOption(self::OPTION_FROM_START);
        if ($fromStart && !$dryRun && !$input->getOption(self::OPTION_FORCE)) {
            $output->writeln('<error>--from-start imports every line again, so hits that were already imported are'
                . ' counted twice. Delete those hits first or add --force to confirm.</error>');
            return Command::FAILURE;
        }

        $result = $this->importer->run($file, $dryRun, $fromStart, $dryRun);
        switch ($result['status']) {
            case LogImporter::STATUS_DISABLED:
                $output->writeln('<error>Access log import is disabled (Stores > Configuration > Panth Infotech'
                    . ' > AI Visibility > Access Log Import). Use --dry-run to test a log file first.</error>');
                return Command::FAILURE;
            case LogImporter::STATUS_LOCKED:
                $output->writeln('<error>Another log import is running. Try again when it has finished.</error>');
                return Command::FAILURE;
            case LogImporter::STATUS_NO_FILES:
                $output->writeln('<error>No valid log file is configured (one "/abs/path/access.log | store_code"'
                    . ' per line).</error>');
                return Command::FAILURE;
            case LogImporter::STATUS_UNKNOWN_FILE:
                $output->writeln(sprintf(
                    '<error>"%s" is not one of the configured log files.</error>',
                    OutputFormatter::escape((string)$file)
                ));
                return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<comment>Dry run: nothing is written.</comment>');
        }
        $failed = false;
        foreach ($result['files'] as $stats) {
            $failed = $this->printFile($output, $stats) || $failed;
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Print the counters of one file; true when it had an error
     *
     * @param OutputInterface $output
     * @param array $stats Per-file result of LogImporter::run()
     * @return bool
     */
    private function printFile(OutputInterface $output, array $stats): bool
    {
        $store = $stats['store_id'] === null
            ? $stats['store_code']
            : sprintf('%s, id %d', $stats['store_code'], $stats['store_id']);
        $output->writeln(sprintf(
            '<info>%s</info> (store %s)',
            OutputFormatter::escape($stats['path']),
            OutputFormatter::escape($store)
        ));
        $output->writeln(sprintf(
            '  lines read: %d | AI hits: %d | candidates: %d | skipped: %d | malformed: %d',
            $stats['lines'],
            $stats['hits'],
            $stats['candidates'],
            $stats['skipped'],
            $stats['malformed']
        ));
        $notes = [];
        if ($stats['reset'] !== '') {
            $notes[] = [
                LogImporter::RESET_ROTATED => 'file was rotated, read from the start',
                LogImporter::RESET_TRUNCATED => 'file was truncated, read from the start',
                LogImporter::RESET_FROM_START => 'read from the start (--from-start)',
            ][$stats['reset']] ?? $stats['reset'];
        }
        $notes[] = sprintf('bytes %d -> %d', $stats['start'], $stats['end']);
        if ($stats['more']) {
            $notes[] = 'line limit reached, the rest follows in the next run';
        }
        $output->writeln('  ' . implode('; ', $notes));
        if ($stats['error'] !== '') {
            $output->writeln('  <error>' . OutputFormatter::escape($stats['error']) . '</error>');
            return true;
        }
        return false;
    }
}
