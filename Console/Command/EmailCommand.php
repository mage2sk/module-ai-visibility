<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Service\DataScope;
use Panth\AiVisibility\Service\Email\WeeklySummarySender;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * panth:aivisibility:email [--dry-run] [--to=]
 *
 * --dry-run prints the subject and a plain-text summary and sends nothing.
 * --to=<address[,address]> sends a test email to these addresses instead of the configured recipients
 * (works while the weekly summary is switched off and does not count as this week's summary).
 * Without options the summary is sent now to the configured recipients and the week is marked as sent.
 * Sets the frontend area when none is set (the store email template needs a design theme; bin/magento has none).
 * The constructor parameter $sender is bound to a Proxy in di.xml.
 */
class EmailCommand extends Command
{
    public const NAME = 'panth:aivisibility:email';

    /**
     * @param WeeklySummarySender $sender
     * @param State $state
     * @param DataScope $scope
     * @param string|null $name
     */
    public function __construct(
        private readonly WeeklySummarySender $sender,
        private readonly State $state,
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
            ->setDescription('Send the AI Visibility weekly summary email now.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the summary instead of sending it')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Send to this address instead of the configured recipients')
            ->addOption(
                'simulated',
                null,
                InputOption::VALUE_NONE,
                'With --dry-run: include simulated rows in the preview (never sent)'
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = $input->getOption('to');
        if ($to !== null && !is_string($to)) {
            $to = null;
        }
        if ($input->getOption('simulated')) {
            if (!$input->getOption('dry-run')) {
                $output->writeln('<error>--simulated only works with --dry-run: simulated numbers are never sent.</error>');
                return Command::FAILURE;
            }
            $this->scope->includeSimulated(true);
        }
        try {
            $this->ensureAreaCode();
            return $input->getOption('dry-run') ? $this->dryRun($output, $to) : $this->send($output, $to);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * Set the frontend area when bin/magento has none yet
     *
     * The area is set, not emulated: some installed modules drop every email while the area code is emulated.
     *
     * @return void
     */
    private function ensureAreaCode(): void
    {
        try {
            $this->state->getAreaCode();
        } catch (LocalizedException $e) {
            unset($e);
            $this->state->setAreaCode(Area::AREA_FRONTEND);
        }
    }

    /**
     * Print subject, recipients and the text summary
     *
     * @param OutputInterface $output
     * @param string|null $to
     * @return int
     */
    private function dryRun(OutputInterface $output, ?string $to): int
    {
        $preview = $this->sender->preview();
        $recipients = $to !== null ? $to : implode(', ', $preview['recipients']);
        $output->writeln('<info>Dry run: nothing is sent.</info>');
        $output->writeln('Subject: ' . OutputFormatter::escape($preview['subject']));
        $output->writeln('To: ' . OutputFormatter::escape($recipients !== '' ? $recipients : '(no recipients configured)'));
        $output->writeln('');
        $output->write(OutputFormatter::escape($preview['text']));
        return Command::SUCCESS;
    }

    /**
     * Send now
     *
     * @param OutputInterface $output
     * @param string|null $to
     * @return int
     */
    private function send(OutputInterface $output, ?string $to): int
    {
        $result = $this->sender->sendNow($to);
        if (!$result['sent']) {
            $output->writeln('<error>' . OutputFormatter::escape($result['message']) . '</error>');
            return Command::FAILURE;
        }
        $output->writeln(
            '<info>Weekly summary sent to ' . OutputFormatter::escape(implode(', ', $result['recipients'])) . '.</info>'
        );
        return Command::SUCCESS;
    }
}
