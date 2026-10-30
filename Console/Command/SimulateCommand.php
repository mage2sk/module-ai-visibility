<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Console\Command;

use Panth\AiVisibility\Service\Simulator\Scenario;
use Panth\AiVisibility\Service\Simulator\Simulator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * panth:aivisibility:simulate - deterministic simulated AI traffic for testing.
 */
class SimulateCommand extends Command
{
    public const NAME = 'panth:aivisibility:simulate';

    private const OPT_DAYS = 'days';
    private const OPT_SEED = 'seed';
    private const OPT_STORE = 'store';
    private const OPT_SCALE = 'scale';
    private const OPT_ORDERS = 'with-orders';
    private const OPT_UNTIL = 'until';
    private const OPT_FORCE = 'force';
    private const OPT_PURGE = 'purge';
    private const OPT_SCENARIO = 'scenario';

    /**
     * @param Simulator $simulator
     * @param string|null $name
     */
    public function __construct(
        private readonly Simulator $simulator,
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
            ->setDescription(
                'Generate deterministic simulated AI crawler hits, AI visits and landings for testing '
                . '(all rows are flagged as simulated), or remove them with --purge.'
            )
            ->addOption(self::OPT_DAYS, null, InputOption::VALUE_REQUIRED, 'Days to simulate (1-400)', '60')
            ->addOption(self::OPT_SEED, null, InputOption::VALUE_REQUIRED, 'Random seed (same seed, same data)', '7')
            ->addOption(self::OPT_STORE, null, InputOption::VALUE_REQUIRED, 'Store view ID', '1')
            ->addOption(self::OPT_SCALE, null, InputOption::VALUE_REQUIRED, 'Volume multiplier (0.01-100)', '1')
            ->addOption(
                self::OPT_ORDERS,
                null,
                InputOption::VALUE_REQUIRED,
                'Attribute up to N existing orders of the store that have no AI attribution yet (0-10000)',
                '0'
            )
            ->addOption(
                self::OPT_UNTIL,
                null,
                InputOption::VALUE_REQUIRED,
                'Last simulated UTC day, Y-m-d (default: yesterday)'
            )
            ->addOption(self::OPT_FORCE, null, InputOption::VALUE_NONE, 'Replace simulated data already in the period')
            ->addOption(
                self::OPT_SCENARIO,
                null,
                InputOption::VALUE_REQUIRED,
                'Plant an applied fix with a known effect for testing verification: '
                . implode(' (treated products +50% AI bot hits) | ', array_keys(Scenario::FIX_EFFECTS))
                . ' (no change). Needs --days of at least twice the verification window plus one.'
            )
            ->addOption(
                self::OPT_PURGE,
                null,
                InputOption::VALUE_NONE,
                'Delete every simulated row from every module table and re-roll the affected days'
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $options = $this->parseOptions($input, time());
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }

        $missing = $this->simulator->missingTables();
        if ($missing) {
            $output->writeln(sprintf(
                '<error>The AI Visibility tables do not exist (%s). Run bin/magento setup:upgrade first.</error>',
                implode(', ', $missing)
            ));
            return Command::FAILURE;
        }

        $progress = static function (string $line) use ($output): void {
            $output->writeln($line, OutputInterface::VERBOSITY_VERBOSE);
        };
        try {
            if ($options['purge']) {
                $this->printPurge($output, $this->simulator->purge($progress));
            } else {
                $this->printSummary($output, $this->simulator->simulate($options, null, $progress));
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    /**
     * Validate and normalise the command options
     *
     * @param InputInterface $input
     * @param int $now Unix time used for the default and the upper bound of --until
     * @return array{days: int, seed: int, store: int, scale_permille: int, with_orders: int, force: bool,
     *     purge: bool, until: string, scenario: string}
     * @throws \InvalidArgumentException
     */
    public function parseOptions(InputInterface $input, int $now): array
    {
        $purge = (bool) $input->getOption(self::OPT_PURGE);
        $force = (bool) $input->getOption(self::OPT_FORCE);
        $orders = $this->intOption($input, self::OPT_ORDERS, 0, Simulator::MAX_ORDERS);
        $scenario = trim((string) $input->getOption(self::OPT_SCENARIO));
        if ($purge && ($force || $orders > 0 || $scenario !== '')) {
            throw new \InvalidArgumentException('--purge cannot be combined with --force, --with-orders or --scenario.');
        }
        if ($scenario !== '' && !isset(Scenario::FIX_EFFECTS[$scenario])) {
            throw new \InvalidArgumentException(
                '--scenario must be one of: ' . implode(', ', array_keys(Scenario::FIX_EFFECTS)) . '.'
            );
        }

        $today = gmdate('Y-m-d', $now);
        $until = $input->getOption(self::OPT_UNTIL);
        if ($until === null || $until === '') {
            $until = gmdate('Y-m-d', $now - 86400);
        }
        $until = (string) $until;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $until, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $until) {
            throw new \InvalidArgumentException('--until must be a date in the form Y-m-d.');
        }
        if ($until > $today) {
            throw new \InvalidArgumentException('--until cannot be in the future.');
        }

        return [
            'days' => $this->intOption($input, self::OPT_DAYS, 1, Simulator::MAX_DAYS),
            'seed' => $this->intOption($input, self::OPT_SEED, 0, 2147483647),
            'store' => $this->intOption($input, self::OPT_STORE, 1, 65535),
            'scale_permille' => $this->scaleOption($input),
            'with_orders' => $orders,
            'force' => $force,
            'purge' => $purge,
            'until' => $until,
            'scenario' => $scenario,
        ];
    }

    /**
     * Whole-number option within bounds
     *
     * @param InputInterface $input
     * @param string $name
     * @param int $min
     * @param int $max
     * @return int
     */
    private function intOption(InputInterface $input, string $name, int $min, int $max): int
    {
        $value = trim((string) $input->getOption($name));
        if ($value === '' || !ctype_digit($value) || strlen($value) > 10 || (int) $value < $min || (int) $value > $max) {
            throw new \InvalidArgumentException(sprintf('--%s must be a whole number from %d to %d.', $name, $min, $max));
        }
        return (int) $value;
    }

    /**
     * --scale as per mille, parsed from the string (no float rounding)
     *
     * @param InputInterface $input
     * @return int
     */
    private function scaleOption(InputInterface $input): int
    {
        $value = trim((string) $input->getOption(self::OPT_SCALE));
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,3}))?$/', $value, $match)) {
            throw new \InvalidArgumentException('--scale must be a number from 0.01 to 100 (up to 3 decimals).');
        }
        $permille = (int) $match[1] * 1000 + (int) str_pad($match[2] ?? '', 3, '0');
        if ($permille < 10 || $permille > 100000) {
            throw new \InvalidArgumentException('--scale must be a number from 0.01 to 100 (up to 3 decimals).');
        }
        return $permille;
    }

    /**
     * Print the simulation summary
     *
     * @param OutputInterface $output
     * @param array<string, mixed> $summary
     * @return void
     */
    private function printSummary(OutputInterface $output, array $summary): void
    {
        $scale = (int) $summary['scale_permille'];
        $output->writeln(sprintf(
            '<info>Simulated %d day(s) for store %d (%s to %s), seed %d, scale %d.%03d.</info>',
            $summary['days'],
            $summary['store'],
            $summary['first_day'],
            $summary['last_day'],
            $summary['seed'],
            intdiv($scale, 1000),
            $scale % 1000
        ));
        if (!empty($summary['replaced'])) {
            $output->writeln(sprintf('Replaced %d earlier simulated row(s).', array_sum($summary['replaced'])));
        }
        $lines = [
            // Raw hit rows include the planted candidate; the dashboard counts confirmed bots only.
            'AI crawler hits (as counted on the dashboard)' => max(0, $summary['hits'] - $summary['candidate_hits']),
            '  of which live-answer page fetches' => $summary['live_fetches'],
            'Unknown-bot candidate hits (shown in Discovery)' => $summary['candidate_hits'],
            'AI visits (referrer or utm)' => $summary['visits'],
            'Direct landings (sampled)' => $summary['landings'],
            'AI clicks after a live fetch' => $summary['ai_clicks'],
            '  arriving without referrer (planted truth)' => $summary['hidden_clicks'],
            '  of those kept as sampled landings' => $summary['hidden_landings'],
            'Orders attributed' => $summary['orders'],
            'Days rolled up' => $summary['rolled_up'],
        ];
        foreach ($lines as $label => $value) {
            $output->writeln(sprintf('  %-46s %d', $label, $value));
        }
        $output->writeln($summary['bot_candidate'] === null
            ? '  Planted bot candidate skipped: a real candidate with the same User-Agent exists.'
            : sprintf(
                '  Planted bot candidate #%d "%s": %d hit(s)',
                $summary['bot_candidate'],
                $summary['bot_candidate_ua'],
                $summary['candidate_hits']
            ));
        $output->writeln($summary['source_candidate'] === null
            ? '  Planted source candidate skipped: a real candidate with the same host exists.'
            : sprintf(
                '  Planted source candidate #%d "%s": %d visit(s)',
                $summary['source_candidate'],
                $summary['source_candidate_host'],
                $summary['candidate_visits']
            ));
        $this->printFix($output, $summary['fix'] ?? null);
        $this->printRollupProblems($output, $summary);
        $output->writeln('All rows are flagged as simulated. Remove them with: bin/magento ' . self::NAME . ' --purge');
    }

    /**
     * Print the planted fix of a --scenario run
     *
     * @param OutputInterface $output
     * @param array<string, mixed>|null $fix
     * @return void
     */
    private function printFix(OutputInterface $output, ?array $fix): void
    {
        if (!$fix) {
            return;
        }
        $output->writeln(sprintf(
            '  Planted applied action #%d (%s, expected effect %+d%%) on %d product(s), applied %s, '
            . 'verifiable from %s (window %d days).',
            $fix['action_id'],
            $fix['scenario'],
            intdiv((int) $fix['effect_permille'], 10),
            count($fix['treated']),
            $fix['applied_day'],
            $fix['verify_after'],
            $fix['window_days']
        ));
        $output->writeln(sprintf(
            '    Treated product hits before / after: %d / %d; untreated product hits: %d / %d.',
            $fix['treated_before'] ?? 0,
            $fix['treated_after'] ?? 0,
            $fix['untreated_before'] ?? 0,
            $fix['untreated_after'] ?? 0
        ));
        $output->writeln(sprintf(
            '    Treated product IDs: %s; matched neighbours: %s',
            implode(', ', $fix['treated']),
            implode(', ', $fix['controls'])
        ));
        $output->writeln(sprintf(
            '    Verify it with: bin/magento panth:aivisibility:verify --action=%d',
            $fix['action_id']
        ));
    }

    /**
     * Print the purge summary
     *
     * @param OutputInterface $output
     * @param array<string, mixed> $summary
     * @return void
     */
    private function printPurge(OutputInterface $output, array $summary): void
    {
        $output->writeln(sprintf(
            '<info>Deleted %d simulated row(s); re-rolled %d day(s).</info>',
            array_sum($summary['deleted']),
            $summary['rolled_up']
        ));
        foreach ($summary['deleted'] as $table => $rows) {
            $output->writeln(sprintf('  %-28s %d', $table, $rows));
        }
        if ($summary['rules'] > 0) {
            $output->writeln(sprintf('  Removed %d rule(s) confirmed from simulated candidates.', $summary['rules']));
        }
        $this->printRollupProblems($output, $summary);
    }

    /**
     * Print skipped / failed rollup days
     *
     * @param OutputInterface $output
     * @param array<string, mixed> $summary
     * @return void
     */
    private function printRollupProblems(OutputInterface $output, array $summary): void
    {
        if (!empty($summary['rollup_skipped'])) {
            $output->writeln(sprintf(
                '<comment>Not re-rolled (real raw rows already pruned, re-rolling would drop real daily totals): '
                . '%s</comment>',
                implode(', ', $summary['rollup_skipped'])
            ));
        }
        foreach ($summary['rollup_failed'] ?? [] as $failure) {
            $output->writeln('<error>Rollup failed for ' . $failure . '</error>');
        }
    }
}
