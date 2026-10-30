<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Console\Command;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\RecommendationEngineInterface;
use Panth\AiVisibility\Service\DataScope;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * panth:aivisibility:recommend [--store=] [--dry-run]
 *
 * Runs the recommendation engine (also while the daily cron is switched off) and prints one table row per
 * finding with the planned action. With -v the evidence of each finding is printed as well.
 * Exit code 1 when a store view or a rule failed (the other rules still ran).
 * The constructor parameter $engine is bound to a Proxy in di.xml.
 */
class RecommendCommand extends Command
{
    public const NAME = 'panth:aivisibility:recommend';
    private const TITLE_WIDTH = 90;

    /**
     * Readable labels of the engine actions
     */
    private const ACTION_LABELS = [
        'create' => 'new',
        'update' => 'still open',
        'reopen' => 'reopened',
        'suppressed' => 'applied/dismissed, kept closed',
        'over_limit' => 'below the max_open cut',
        'resolve' => 'resolved (not found again)',
        'simulated' => 'simulator row, skipped',
    ];

    /**
     * @param RecommendationEngineInterface $engine
     * @param StoreManagerInterface $storeManager
     * @param DataScope $scope
     * @param string|null $name
     */
    public function __construct(
        private readonly RecommendationEngineInterface $engine,
        private readonly StoreManagerInterface $storeManager,
        private readonly DataScope $scope,
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
            ->setDescription('Evaluate the AI Visibility recommendation rules and update the recommendation list.')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view ID (default: every active store view)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Evaluate and print only; write nothing')
            ->addOption(
                'simulated',
                null,
                InputOption::VALUE_NONE,
                'Also read simulated rows (test preview; implies --dry-run, nothing is saved)'
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeOption = $input->getOption('store');
        $storeId = null;
        if ($storeOption !== null && $storeOption !== '') {
            $raw = trim((string)$storeOption);
            if (!ctype_digit($raw) || (int)$raw < 1) {
                $output->writeln('<error>--store must be a store view ID (a whole number above 0).</error>');
                return Command::FAILURE;
            }
            try {
                $storeId = (int)$this->storeManager->getStore((int)$raw)->getId();
            } catch (NoSuchEntityException $e) {
                $output->writeln(sprintf('<error>Store view %d does not exist.</error>', (int)$raw));
                return Command::FAILURE;
            }
        }
        $dryRun = (bool)$input->getOption('dry-run');
        if ($input->getOption('simulated')) {
            // Findings from simulated traffic are a preview only: they are never stored.
            $this->scope->includeSimulated(true);
            $dryRun = true;
            $output->writeln('Simulated rows are included (test preview).');
        }

        $result = $this->engine->run($storeId, $dryRun);
        $findings = $result['findings'] ?? [];
        if ($findings === []) {
            $output->writeln('No recommendations: none of the rules found a problem above the minimum volume.');
        } else {
            $this->table($output, $findings);
            if ($output->isVerbose()) {
                $this->evidence($output, $findings);
            }
        }
        $output->writeln(sprintf(
            $dryRun
                ? 'Dry run, nothing was saved. Would create %d, update %d and resolve %d recommendation(s).'
                : 'Created %d, updated %d and resolved %d recommendation(s).',
            (int)($result['created'] ?? 0),
            (int)($result['updated'] ?? 0),
            (int)($result['resolved'] ?? 0)
        ));
        $errors = $result['errors'] ?? [];
        foreach ($errors as $error) {
            $output->writeln('<error>' . OutputFormatter::escape((string)$error) . '</error>');
        }
        if ($errors !== []) {
            $output->writeln('Open recommendations of the failed rules were left unchanged.');
        }
        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Print the findings table
     *
     * @param OutputInterface $output
     * @param array $findings
     * @return void
     */
    private function table(OutputInterface $output, array $findings): void
    {
        $table = new Table($output);
        $table->setHeaders(['Store', 'Rule', 'Action', 'Score', 'Affected', 'Title']);
        foreach ($findings as $finding) {
            $action = (string)($finding['action'] ?? '');
            $title = (string)($finding['title'] ?? '');
            if (mb_strlen($title) > self::TITLE_WIDTH) {
                $title = mb_substr($title, 0, self::TITLE_WIDTH - 3) . '...';
            }
            $table->addRow([
                (int)($finding['store_id'] ?? 0),
                OutputFormatter::escape((string)($finding['rule_code'] ?? '')),
                self::ACTION_LABELS[$action] ?? OutputFormatter::escape($action),
                number_format((float)($finding['rank_score'] ?? 0), 0),
                number_format((int)($finding['affected_count'] ?? 0)),
                OutputFormatter::escape($title),
            ]);
        }
        $table->render();
    }

    /**
     * Print the evidence of every finding (verbose mode)
     *
     * @param OutputInterface $output
     * @param array $findings
     * @return void
     */
    private function evidence(OutputInterface $output, array $findings): void
    {
        foreach ($findings as $finding) {
            if (!isset($finding['evidence'])) {
                continue;
            }
            $evidence = (array)$finding['evidence'];
            $subjects = (array)($finding['subjects'] ?? []);
            $evidence[RecommendationRepositoryInterface::EVIDENCE_SUBJECTS] = count($subjects) > 10
                ? array_merge(array_slice($subjects, 0, 10), ['... ' . (count($subjects) - 10) . ' more'])
                : $subjects;
            $output->writeln('');
            $output->writeln(sprintf(
                '<info>[store %d] %s</info>',
                (int)($finding['store_id'] ?? 0),
                OutputFormatter::escape((string)($finding['title'] ?? ''))
            ));
            $output->writeln(OutputFormatter::escape(
                (string)json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ));
        }
    }
}
