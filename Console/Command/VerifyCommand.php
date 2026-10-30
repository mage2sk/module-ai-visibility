<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Verification\ImpactStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * panth:aivisibility:verify [--action=] [--dry-run]
 *
 * Without --action every due action (pending, verify_after reached, not undone) is verified, one line
 * block per action. The constructor parameter $calculator is bound to a Proxy in di.xml.
 * Warns when the daily summary retention is shorter than what a verification reads (the window before the
 * before window, used to match controls, starts 3 x window + 1 days before verify_after).
 */
class VerifyCommand extends Command
{
    public const NAME = 'panth:aivisibility:verify';

    private const VERDICT_TEXT = [
        ImpactCalculatorInterface::VERDICT_HELPED => 'likely helped',
        ImpactCalculatorInterface::VERDICT_NO_EFFECT => 'no clear effect',
        ImpactCalculatorInterface::VERDICT_WORSE => 'likely made it worse',
        ImpactCalculatorInterface::VERDICT_INSUFFICIENT => 'not enough data yet',
    ];

    /**
     * @param ImpactCalculatorInterface $calculator
     * @param ImpactStore $store
     * @param string|null $name
     * @param Config|null $config
     */
    public function __construct(
        private readonly ImpactCalculatorInterface $calculator,
        private readonly ImpactStore $store,
        ?string $name = null,
        private readonly ?Config $config = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Verify whether applied AI Visibility fixes helped (before/after against a control group).')
            ->addOption('action', null, InputOption::VALUE_REQUIRED, 'Verify one action ID now (default: every due action)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute and print only; write nothing');
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $action = $input->getOption('action');
        if ($dryRun) {
            $output->writeln('<comment>Dry run: nothing is written.</comment>');
        }
        if ($this->config !== null) {
            $warning = self::retentionWarning(
                $this->config->verifyWindowDays(),
                $this->config->dailyRetentionMonths()
            );
            if ($warning !== null) {
                $output->writeln('<comment>' . $warning . '</comment>');
            }
        }
        if ($action !== null && $action !== '') {
            if (!ctype_digit((string)$action) || (int)$action < 1) {
                $output->writeln('<error>--action must be a positive action ID.</error>');
                return Command::FAILURE;
            }
            try {
                $this->printRow($output, $this->calculator->verify((int)$action, $dryRun));
            } catch (LocalizedException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
            return Command::SUCCESS;
        }

        $ids = $this->store->dueActionIds(gmdate('Y-m-d'), 200);
        if ($ids === []) {
            $output->writeln('No actions are due for verification.');
            return Command::SUCCESS;
        }
        $done = 0;
        foreach ($ids as $id) {
            try {
                $this->printRow($output, $this->calculator->verify($id, $dryRun));
                $done++;
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Action #%d: %s</error>', $id, $e->getMessage()));
            }
        }
        $output->writeln(sprintf('%d of %d due action(s) verified%s.', $done, count($ids), $dryRun ? ' (dry run)' : ''));
        return $done === count($ids) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Readable summary of one impact row
     *
     * @param OutputInterface $output
     * @param array $row
     * @return void
     */
    private function printRow(OutputInterface $output, array $row): void
    {
        $details = is_array($row['details'] ?? null) ? $row['details'] : [];
        $verdict = (string)($row['verdict'] ?? ImpactCalculatorInterface::VERDICT_INSUFFICIENT);
        $output->writeln(sprintf(
            '<info>Action #%d</info>: %s',
            (int)($row['action_id'] ?? 0),
            self::VERDICT_TEXT[$verdict] ?? $verdict
        ));
        $output->writeln(sprintf(
            '  Metric %s (%s), method %s, window %d days',
            (string)($details['metric'] ?? ''),
            ($details['direction'] ?? '') === 'less_is_better' ? 'lower is better' : 'higher is better',
            (string)($row['method'] ?? ''),
            (int)($row['window_days'] ?? 0)
        ));
        $before = $details['before'] ?? null;
        $after = $details['after'] ?? null;
        $output->writeln(sprintf(
            '  Before %s, after %s%s',
            is_array($before) ? implode(' to ', $before) : '-',
            is_array($after) ? implode(' to ', $after) : 'not started',
            empty($details['final']) ? ' (window not complete yet; the action stays pending)' : ''
        ));
        $output->writeln(sprintf(
            '  Treated (%d): %s -> %s',
            (int)($row['subjects_treated'] ?? 0),
            self::number($row['treat_before'] ?? null),
            self::number($row['treat_after'] ?? null)
        ));
        if (($row['control_before'] ?? null) !== null) {
            $output->writeln(sprintf(
                '  Control (%d): %s -> %s',
                (int)($row['subjects_control'] ?? 0),
                self::number($row['control_before']),
                self::number($row['control_after'] ?? null)
            ));
        }
        $output->writeln(sprintf('  Events: %d', (int)($row['events'] ?? 0)));
        $coverage = self::coverageLine($row);
        if ($coverage !== null) {
            $output->writeln('  Coverage: ' . $coverage);
        } elseif (($row['effect'] ?? null) !== null) {
            $range = ($row['ci_low'] ?? null) !== null
                ? sprintf(', 95%% range %s to %s', self::change((float)$row['ci_low']), self::change((float)$row['ci_high']))
                : '';
            $output->writeln(sprintf('  Effect: %s%s', self::change((float)$row['effect']), $range));
        }
        if (!empty($details['reason'])) {
            $output->writeln('  Reason: ' . str_replace('_', ' ', (string)$details['reason']));
        }
        if (!empty($details['kept_previous_verdict'])) {
            $output->writeln('  The stored final verdict was kept: the data needed to recompute it is no longer complete.');
        }
    }

    /**
     * Warning when the daily summary retention cannot hold the days a verification reads, else null
     *
     * @param int $windowDays verify/window_days
     * @param int $retentionMonths Daily summary retention in months (0 = not set)
     * @return string|null
     */
    public static function retentionWarning(int $windowDays, int $retentionMonths): ?string
    {
        $needed = 3 * max(1, $windowDays) + 1;
        // Shortest possible month span: 28 days per month.
        if ($retentionMonths <= 0 || $retentionMonths * 28 >= $needed) {
            return null;
        }
        return sprintf(
            'The daily summary retention (%d month(s)) is shorter than the %d days a %d-day verification reads; '
            . 'raise it or verifications will be "not enough data" (expired).',
            $retentionMonths,
            $needed,
            $windowDays
        );
    }

    /**
     * Coverage result as shares and a difference in percentage points, like the admin shows it, null when
     * the row is not a coverage result
     *
     * E.g. "60% of fixed vs 15% baseline, difference +45 points [+20, +65]" ("... so far" while the
     * verdict is "not enough data"). The shares come from the
     * counts in details.coverage (else treat_after / control_after), the difference and its 95% range from
     * details.coverage diff / diff_low / diff_high.
     *
     * @param array $row
     * @return string|null
     */
    public static function coverageLine(array $row): ?string
    {
        if ((string)($row['method'] ?? '') !== ImpactCalculatorInterface::METHOD_COVERAGE) {
            return null;
        }
        $details = is_array($row['details'] ?? null) ? $row['details'] : [];
        $coverage = is_array($details['coverage'] ?? null) ? $details['coverage'] : [];
        $treated = (int)($coverage['treated'] ?? 0);
        $baseline = (int)($coverage['baseline'] ?? 0);
        if ($treated > 0 && $baseline > 0) {
            $fixed = (int)($coverage['treated_fetched'] ?? 0) / $treated;
            $normal = (int)($coverage['baseline_fetched'] ?? 0) / $baseline;
        } elseif (is_numeric($row['treat_after'] ?? null) && is_numeric($row['control_after'] ?? null)) {
            $fixed = (float)$row['treat_after'];
            $normal = (float)$row['control_after'];
        } else {
            return null;
        }
        $fixed = min(1.0, max(0.0, $fixed));
        $normal = min(1.0, max(0.0, $normal));
        $shares = sprintf('%d%% of fixed vs %d%% baseline', (int)round($fixed * 100), (int)round($normal * 100));
        if ((string)($row['verdict'] ?? '') === ImpactCalculatorInterface::VERDICT_INSUFFICIENT) {
            return $shares . ' so far';
        }
        $diff = is_numeric($coverage['diff'] ?? null) ? (float)$coverage['diff'] : $fixed - $normal;
        $text = $shares . ', difference ' . self::points($diff) . ' points';
        if (is_numeric($coverage['diff_low'] ?? null) && is_numeric($coverage['diff_high'] ?? null)) {
            $low = (float)$coverage['diff_low'];
            $high = (float)$coverage['diff_high'];
            return $text . sprintf(' [%s, %s]', self::points(min($low, $high)), self::points(max($low, $high)));
        }
        return $text . ' (no reliable range)';
    }

    /**
     * Difference of two shares as signed percentage points, e.g. 0.45 -> "+45", 0 -> "0"
     *
     * @param float $diff
     * @return string
     */
    private static function points(float $diff): string
    {
        $points = (int)round($diff * 100);
        if ($points === 0) {
            return '0';
        }
        return ($points > 0 ? '+' : '-') . abs($points);
    }

    /**
     * Ratio as a signed percentage change, e.g. 1.234 -> "+23.4%"
     *
     * @param float $ratio
     * @return string
     */
    private static function change(float $ratio): string
    {
        $pct = ($ratio - 1.0) * 100.0;
        return sprintf('%s%.1f%%', $pct >= 0 ? '+' : '', $pct);
    }

    /**
     * Number with up to 4 decimals, "-" for null
     *
     * @param mixed $value
     * @return string
     */
    private static function number($value): string
    {
        if ($value === null) {
            return '-';
        }
        return rtrim(rtrim(number_format((float)$value, 4, '.', ''), '0'), '.');
    }
}
