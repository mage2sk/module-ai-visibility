<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Console\Command;

use Panth\AiVisibility\Console\Command\SimulateCommand;
use Panth\AiVisibility\Service\Simulator\Simulator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;

class SimulateCommandTest extends TestCase
{
    /**
     * @var Simulator&Stub
     */
    private $simulator;

    private SimulateCommand $command;

    protected function setUp(): void
    {
        $this->simulator = $this->createStub(Simulator::class);
        $this->command = new SimulateCommand($this->simulator);
    }

    /**
     * Swap the stub for a mock when a test sets expectations
     *
     * @return Simulator&MockObject
     */
    private function mockSimulator()
    {
        $mock = $this->createMock(Simulator::class);
        $this->simulator = $mock;
        $this->command = new SimulateCommand($mock);
        return $mock;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function parse(array $options): array
    {
        $input = new ArrayInput($options, $this->command->getDefinition());
        // 2026-03-10 12:00:00 UTC
        return $this->command->parseOptions($input, 1773144000);
    }

    public function testDefaults(): void
    {
        $this->assertSame([
            'days' => 60,
            'seed' => 7,
            'store' => 1,
            'scale_permille' => 1000,
            'with_orders' => 0,
            'force' => false,
            'purge' => false,
            'until' => '2026-03-09',
        ], $this->parse([]));
    }

    public function testExplicitValues(): void
    {
        $options = $this->parse([
            '--days' => '14', '--seed' => '123', '--store' => '2', '--scale' => '0.25',
            '--with-orders' => '30', '--until' => '2026-03-10', '--force' => true,
        ]);
        $this->assertSame(14, $options['days']);
        $this->assertSame(123, $options['seed']);
        $this->assertSame(2, $options['store']);
        $this->assertSame(250, $options['scale_permille']);
        $this->assertSame(30, $options['with_orders']);
        $this->assertSame('2026-03-10', $options['until']);
        $this->assertTrue($options['force']);
    }

    /**
     * @dataProvider scaleProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scaleProvider')]
    public function testScaleIsParsedWithoutFloats(string $scale, int $permille): void
    {
        $this->assertSame($permille, $this->parse(['--scale' => $scale])['scale_permille']);
    }

    /**
     * @return array<string, array>
     */
    public static function scaleProvider(): array
    {
        return [
            'one' => ['1', 1000],
            'decimal' => ['2.5', 2500],
            'three decimals' => ['0.125', 125],
            'minimum' => ['0.01', 10],
            'maximum' => ['100', 100000],
        ];
    }

    /**
     * @dataProvider invalidProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidProvider')]
    public function testInvalidOptionsAreRejected(array $options, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->parse($options);
    }

    /**
     * @return array<string, array>
     */
    public static function invalidProvider(): array
    {
        return [
            'zero days' => [['--days' => '0'], '--days'],
            'too many days' => [['--days' => '401'], '--days'],
            'text days' => [['--days' => 'ten'], '--days'],
            'negative seed' => [['--seed' => '-1'], '--seed'],
            'admin store' => [['--store' => '0'], '--store'],
            'scale too small' => [['--scale' => '0.001'], '--scale'],
            'scale too big' => [['--scale' => '101'], '--scale'],
            'scale exponent' => [['--scale' => '1e2'], '--scale'],
            'orders too many' => [['--with-orders' => '10001'], '--with-orders'],
            'bad date' => [['--until' => '2026-02-30'], '--until'],
            'future date' => [['--until' => '2026-03-11'], 'future'],
            'purge with force' => [['--purge' => true, '--force' => true], '--purge'],
            'purge with orders' => [['--purge' => true, '--with-orders' => '3'], '--purge'],
        ];
    }

    public function testInvalidOptionReturnsInvalidWithoutTouchingTheSimulator(): void
    {
        $this->mockSimulator()->expects($this->never())->method('simulate');
        $tester = new CommandTester($this->command);
        $this->assertSame(Command::INVALID, $tester->execute(['--days' => '0']));
        $this->assertStringContainsString('--days', $tester->getDisplay());
    }

    public function testRefusesWhenTablesAreMissing(): void
    {
        $mock = $this->mockSimulator();
        $mock->method('missingTables')->willReturn(['panth_aiv_hit']);
        $mock->expects($this->never())->method('simulate');
        $mock->expects($this->never())->method('purge');
        $tester = new CommandTester($this->command);
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('setup:upgrade', $tester->getDisplay());
    }

    public function testSimulatorErrorIsPrintedAsFailure(): void
    {
        $this->simulator->method('missingTables')->willReturn([]);
        $this->simulator->method('simulate')->willThrowException(
            new \Magento\Framework\Exception\LocalizedException(__('Run again with --force to replace it.'))
        );
        $tester = new CommandTester($this->command);
        $this->assertSame(Command::FAILURE, $tester->execute(['--until' => '2026-01-31']));
        $this->assertStringContainsString('--force', $tester->getDisplay());
    }

    public function testPrintsSummary(): void
    {
        $mock = $this->mockSimulator();
        $mock->method('missingTables')->willReturn([]);
        $mock->expects($this->once())->method('simulate')
            ->with($this->callback(static fn(array $o) => $o['days'] === 5 && $o['until'] === '2026-01-31'))
            ->willReturn([
                'store' => 1, 'seed' => 7, 'scale_permille' => 1500, 'first_day' => '2026-01-27',
                'last_day' => '2026-01-31', 'days' => 5, 'replaced' => [], 'hits' => 6000,
                'live_fetches' => 1200, 'visits' => 250, 'landings' => 170, 'ai_clicks' => 180,
                'hidden_clicks' => 54, 'hidden_landings' => 14, 'candidate_hits' => 135,
                'candidate_visits' => 15, 'bot_candidate' => 4, 'source_candidate' => 5,
                'bot_candidate_ua' => 'SimAgent/1.0 (+https://example.invalid/bot)',
                'source_candidate_host' => 'assistant.example.invalid', 'orders' => 0, 'rolled_up' => 5,
                'rollup_skipped' => ['2026-01-27'], 'rollup_failed' => [],
            ]);
        $tester = new CommandTester($this->command);
        $this->assertSame(Command::SUCCESS, $tester->execute(['--days' => '5', '--until' => '2026-01-31']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Simulated 5 day(s) for store 1 (2026-01-27 to 2026-01-31), seed 7, scale 1.500', $display);
        // 6000 raw hit rows = 5865 dashboard hits + 135 candidate hits, printed separately
        $this->assertMatchesRegularExpression('/AI crawler hits \(as counted on the dashboard\) +5865/', $display);
        $this->assertMatchesRegularExpression('/Unknown-bot candidate hits \(shown in Discovery\) +135/', $display);
        $this->assertStringContainsString('SimAgent/1.0', $display);
        $this->assertStringContainsString('assistant.example.invalid', $display);
        $this->assertStringContainsString('Not re-rolled', $display);
        $this->assertStringContainsString('--purge', $display);
    }

    public function testPurgePrintsDeletedRows(): void
    {
        $mock = $this->mockSimulator();
        $mock->method('missingTables')->willReturn([]);
        $mock->expects($this->never())->method('simulate');
        $mock->expects($this->once())->method('purge')->willReturn([
            'deleted' => ['panth_aiv_hit' => 100, 'panth_aiv_visit' => 7],
            'rules' => 1,
            'rolled_up' => 3,
            'rollup_skipped' => [],
            'rollup_failed' => [],
        ]);
        $tester = new CommandTester($this->command);
        $this->assertSame(Command::SUCCESS, $tester->execute(['--purge' => true]));
        $this->assertStringContainsString('Deleted 107 simulated row(s); re-rolled 3 day(s).', $tester->getDisplay());
        $this->assertStringContainsString('Removed 1 rule(s)', $tester->getDisplay());
    }
}
