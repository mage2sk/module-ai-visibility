<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Panth\AiVisibility\Console\Command\VerifyCommand;
use Panth\AiVisibility\Service\Verification\ImpactStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class VerifyCommandTest extends TestCase
{
    /**
     * @return array
     */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'action_id' => 42,
            'window_days' => 14,
            'method' => 'did',
            'treat_before' => 200.0,
            'treat_after' => 360.0,
            'control_before' => 297.0,
            'control_after' => 327.0,
            'effect' => 1.6349,
            'ci_low' => 1.52,
            'ci_high' => 1.7801,
            'events' => 560,
            'subjects_treated' => 4,
            'subjects_control' => 6,
            'verdict' => 'helped',
            'details' => [
                'reason' => null,
                'metric' => 'bot_hits',
                'direction' => 'more_is_better',
                'before' => ['2026-02-15', '2026-02-28'],
                'after' => ['2026-03-02', '2026-03-15'],
                'final' => true,
            ],
        ];
    }

    public function testOneActionIsPrintedReadably(): void
    {
        $calculator = $this->createMock(ImpactCalculatorInterface::class);
        $calculator->expects($this->once())->method('verify')->with(42, true)->willReturn(self::row());
        $tester = new CommandTester(new VerifyCommand($calculator, $this->createStub(ImpactStore::class)));

        $code = $tester->execute(['--action' => '42', '--dry-run' => true]);
        $out = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('Dry run', $out);
        $this->assertStringContainsString('Action #42: likely helped', $out);
        $this->assertStringContainsString('Treated (4): 200 -> 360', $out);
        $this->assertStringContainsString('Control (6): 297 -> 327', $out);
        $this->assertStringContainsString('Effect: +63.5%, 95% range +52.0% to +78.0%', $out);
        $this->assertStringContainsString('Before 2026-02-15 to 2026-02-28, after 2026-03-02 to 2026-03-15', $out);
    }

    public function testInsufficientShowsTheReason(): void
    {
        $calculator = $this->createStub(ImpactCalculatorInterface::class);
        $calculator->method('verify')->willReturn(self::row([
            'verdict' => 'insufficient',
            'effect' => null,
            'ci_low' => null,
            'ci_high' => null,
            'control_before' => null,
            'details' => ['reason' => 'too_few_events', 'final' => false, 'after' => null],
        ]));
        $tester = new CommandTester(new VerifyCommand($calculator, $this->createStub(ImpactStore::class)));

        $tester->execute(['--action' => '42']);
        $out = $tester->getDisplay();

        $this->assertStringContainsString('not enough data yet', $out);
        $this->assertStringContainsString('Reason: too few events', $out);
        $this->assertStringContainsString('after not started (window not complete yet', $out);
        $this->assertStringNotContainsString('Control (', $out);
    }

    /**
     * @return array
     */
    private static function coverageRow(array $coverage, string $verdict = 'no_effect'): array
    {
        return self::row([
            'method' => 'coverage',
            'verdict' => $verdict,
            'treat_before' => 0.0,
            'treat_after' => 0.0,
            'control_before' => null,
            'control_after' => 1.0,
            'effect' => 0.0,
            'ci_low' => 0.0,
            'ci_high' => 0.2,
            'details' => [
                'reason' => null,
                'metric' => 'product_coverage',
                'direction' => 'more_is_better',
                'before' => ['2026-02-15', '2026-02-28'],
                'after' => ['2026-03-02', '2026-03-15'],
                'final' => true,
                'coverage' => $coverage,
            ],
        ]);
    }

    public function testCoverageIsPrintedAsSharesAndPoints(): void
    {
        $calculator = $this->createStub(ImpactCalculatorInterface::class);
        $calculator->method('verify')->willReturn(self::coverageRow([
            'treated_fetched' => 0,
            'treated' => 8,
            'baseline_fetched' => 40,
            'baseline' => 40,
            'diff' => -1.0,
            'diff_low' => -0.95,
            'diff_high' => -0.6,
        ]));
        $tester = new CommandTester(new VerifyCommand($calculator, $this->createStub(ImpactStore::class)));

        $tester->execute(['--action' => '42']);
        $out = $tester->getDisplay();

        $this->assertStringContainsString('Coverage: 0% of fixed vs 100% baseline, difference -100 points [-95, -60]', $out);
        $this->assertStringNotContainsString('-100%', $out);
        $this->assertStringNotContainsString('Effect:', $out);
    }

    /**
     * @dataProvider coverageLineProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('coverageLineProvider')]
    public function testCoverageLine(array $row, ?string $expected): void
    {
        $this->assertSame($expected, VerifyCommand::coverageLine($row));
    }

    /**
     * @return array
     */
    public static function coverageLineProvider(): array
    {
        $full = ['treated_fetched' => 6, 'treated' => 10, 'baseline_fetched' => 3, 'baseline' => 20];
        return [
            'not a coverage result' => [self::row(), null],
            'helped with range' => [
                self::coverageRow($full + ['diff' => 0.45, 'diff_low' => 0.2, 'diff_high' => 0.65], 'helped'),
                '60% of fixed vs 15% baseline, difference +45 points [+20, +65]',
            ],
            'range bounds are ordered' => [
                self::coverageRow($full + ['diff' => 0.45, 'diff_low' => 0.65, 'diff_high' => 0.2], 'helped'),
                '60% of fixed vs 15% baseline, difference +45 points [+20, +65]',
            ],
            'zero difference' => [
                self::coverageRow([
                    'treated_fetched' => 1, 'treated' => 4, 'baseline_fetched' => 5, 'baseline' => 20,
                    'diff' => 0.0, 'diff_low' => -0.3, 'diff_high' => 0.4,
                ]),
                '25% of fixed vs 25% baseline, difference 0 points [-30, +40]',
            ],
            'no range' => [
                self::coverageRow($full + ['diff' => 0.45]),
                '60% of fixed vs 15% baseline, difference +45 points (no reliable range)',
            ],
            'insufficient' => [
                self::coverageRow($full, 'insufficient'),
                '60% of fixed vs 15% baseline so far',
            ],
            'shares from treat_after and control_after' => [
                self::coverageRow([], 'no_effect'),
                '0% of fixed vs 100% baseline, difference -100 points (no reliable range)',
            ],
        ];
    }

    public function testInvalidOrUnknownActionFails(): void
    {
        $calculator = $this->createStub(ImpactCalculatorInterface::class);
        $calculator->method('verify')->willThrowException(new LocalizedException(__('Action 9 does not exist.')));
        $tester = new CommandTester(new VerifyCommand($calculator, $this->createStub(ImpactStore::class)));

        $this->assertSame(Command::FAILURE, $tester->execute(['--action' => 'abc']));
        $this->assertStringContainsString('positive action ID', $tester->getDisplay());
        $this->assertSame(Command::FAILURE, $tester->execute(['--action' => '9']));
        $this->assertStringContainsString('Action 9 does not exist.', $tester->getDisplay());
    }

    public function testDueActionsAreVerifiedOneByOne(): void
    {
        $store = $this->createStub(ImpactStore::class);
        $store->method('dueActionIds')->willReturn([3, 4]);
        $calculator = $this->createMock(ImpactCalculatorInterface::class);
        $calculator->expects($this->exactly(2))->method('verify')->willReturnCallback(
            static fn (int $id, bool $dry): array => self::row(['action_id' => $id, 'verdict' => 'no_effect'])
        );
        $tester = new CommandTester(new VerifyCommand($calculator, $store));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $out = $tester->getDisplay();
        $this->assertStringContainsString('Action #3: no clear effect', $out);
        $this->assertStringContainsString('Action #4: no clear effect', $out);
        $this->assertStringContainsString('2 of 2 due action(s) verified.', $out);
    }

    public function testNothingDue(): void
    {
        $store = $this->createStub(ImpactStore::class);
        $store->method('dueActionIds')->willReturn([]);
        $tester = new CommandTester(new VerifyCommand($this->createStub(ImpactCalculatorInterface::class), $store));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('No actions are due', $tester->getDisplay());
    }

    public function testRetentionWarning(): void
    {
        $this->assertNull(VerifyCommand::retentionWarning(14, 13));
        $this->assertNull(VerifyCommand::retentionWarning(14, 2), '43 days fit in 2 months');
        $this->assertNull(VerifyCommand::retentionWarning(14, 0));
        $this->assertStringContainsString('85 days', (string)VerifyCommand::retentionWarning(28, 3));
        $this->assertNotNull(VerifyCommand::retentionWarning(14, 1));
    }
}
