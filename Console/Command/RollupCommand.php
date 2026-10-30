<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Console\Command;

use Panth\AiVisibility\Api\RollupInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * panth:aivisibility:rollup [--date=Y-m-d] [--days=N]
 *
 * Rebuilds the daily summary rows of N UTC days ending at --date (default: today, 1 day).
 */
class RollupCommand extends Command
{
    public const NAME = 'panth:aivisibility:rollup';
    public const OPTION_DATE = 'date';
    public const OPTION_DAYS = 'days';
    public const MAX_DAYS = 800;

    /**
     * @param RollupInterface $rollup
     * @param string|null $name
     */
    public function __construct(
        private readonly RollupInterface $rollup,
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
            ->setDescription('Rebuild the AI Visibility daily summary rows from the raw rows.')
            ->addOption(
                self::OPTION_DATE,
                null,
                InputOption::VALUE_REQUIRED,
                'Last UTC day to roll up (Y-m-d), default today'
            )
            ->addOption(
                self::OPTION_DAYS,
                null,
                InputOption::VALUE_REQUIRED,
                'Number of days ending at --date (1-' . self::MAX_DAYS . ')',
                '1'
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $date = trim((string)($input->getOption(self::OPTION_DATE) ?? ''));
        if ($date === '') {
            $date = gmdate('Y-m-d');
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $output->writeln('<error>--date must be a valid Y-m-d date.</error>');
            return Command::FAILURE;
        }
        $days = trim((string)$input->getOption(self::OPTION_DAYS));
        if (!preg_match('/^\d{1,4}$/', $days) || (int)$days < 1 || (int)$days > self::MAX_DAYS) {
            $output->writeln(sprintf('<error>--days must be a whole number from 1 to %d.</error>', self::MAX_DAYS));
            return Command::FAILURE;
        }

        $end = (int)strtotime($date . ' 00:00:00 UTC');
        $failed = 0;
        for ($i = (int)$days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', $end - $i * 86400);
            try {
                $this->rollup->rollupDay($day);
                $output->writeln(sprintf('<info>Rolled up %s.</info>', $day));
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln(sprintf('<error>Rollup of %s failed: %s</error>', $day, $e->getMessage()));
            }
        }
        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
