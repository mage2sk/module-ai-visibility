<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Console\Command;

use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Console\Command\RollupCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RollupCommandTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $days = [];

    protected function setUp(): void
    {
        $this->days = [];
    }

    public function testDefaultsToToday(): void
    {
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([gmdate('Y-m-d')], $this->days);
        $this->assertStringContainsString('Rolled up ' . gmdate('Y-m-d'), $tester->getDisplay());
    }

    public function testRollsUpDaysEndingAtDateOldestFirst(): void
    {
        $command = $this->command();
        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--date' => '2026-03-01', '--days' => '3']));
        $this->assertSame(['2026-02-27', '2026-02-28', '2026-03-01'], $this->days);
        $this->assertSame('panth:aivisibility:rollup', $command->getName());
    }

    /**
     * @dataProvider invalidOptions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOptions')]
    public function testInvalidOptionsFail(array $options, string $message): void
    {
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::FAILURE, $tester->execute($options));
        $this->assertStringContainsString($message, $tester->getDisplay());
        $this->assertSame([], $this->days);
    }

    public static function invalidOptions(): array
    {
        return [
            'bad date' => [['--date' => '2026-02-30'], '--date'],
            'date with time' => [['--date' => '2026-02-01 10:00'], '--date'],
            'zero days' => [['--days' => '0'], '--days'],
            'negative days' => [['--days' => '-2'], '--days'],
            'too many days' => [['--days' => '801'], '--days'],
            'text days' => [['--days' => 'x'], '--days'],
        ];
    }

    public function testFailedDayIsReportedAndOthersContinue(): void
    {
        $tester = new CommandTester($this->command('2026-02-28'));

        $this->assertSame(Command::FAILURE, $tester->execute(['--date' => '2026-03-01', '--days' => '2']));
        $this->assertSame(['2026-03-01'], $this->days);
        $this->assertStringContainsString('Rollup of 2026-02-28 failed: locked', $tester->getDisplay());
    }

    /**
     * @param string|null $failingDay
     * @return RollupCommand
     */
    private function command(?string $failingDay = null): RollupCommand
    {
        $rollup = $this->createStub(RollupInterface::class);
        $rollup->method('rollupDay')->willReturnCallback(function (string $day) use ($failingDay): void {
            if ($day === $failingDay) {
                throw new \RuntimeException('locked');
            }
            $this->days[] = $day;
        });
        return new RollupCommand($rollup);
    }
}
