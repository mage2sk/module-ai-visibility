<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use Panth\AiVisibility\Console\Command\EmailCommand;
use Panth\AiVisibility\Service\Email\WeeklySummarySender;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Panth\AiVisibility\Service\DataScope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Shared mocks get expectations only in the tests that need them.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class EmailCommandTest extends TestCase
{
    /**
     * @var DataScope
     */
    private DataScope $scope;

    /**
     * @var WeeklySummarySender&MockObject
     */
    private $sender;

    protected function setUp(): void
    {
        $this->sender = $this->createMock(WeeklySummarySender::class);
        $this->scope = new DataScope();
    }

    public function testDryRunPrintsSubjectAndTextWithoutSending(): void
    {
        $this->sender->method('preview')->willReturn([
            'subject' => 'AI Visibility weekly summary for Shop: 2026-10-19 to 2026-10-25',
            'text' => "AI crawler hits: 10 <error>\n",
            'recipients' => ['ops@shop.test'],
        ]);
        $this->sender->expects($this->never())->method('sendNow');
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString(
            'Subject: AI Visibility weekly summary for Shop: 2026-10-19 to 2026-10-25',
            $display
        );
        $this->assertStringContainsString('To: ops@shop.test', $display);
        $this->assertStringContainsString('AI crawler hits: 10 <error>', $display);
    }

    public function testToOverridesRecipients(): void
    {
        $this->sender->expects($this->once())->method('sendNow')->with('aivtest@example.invalid')
            ->willReturn(['sent' => true, 'recipients' => ['aivtest@example.invalid'], 'message' => 'Sent.']);
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::SUCCESS, $tester->execute(['--to' => 'aivtest@example.invalid']));
        $this->assertStringContainsString('sent to aivtest@example.invalid', $tester->getDisplay());
    }

    public function testFailureReturnsErrorCode(): void
    {
        $this->sender->method('sendNow')
            ->willReturn(['sent' => false, 'recipients' => [], 'message' => 'Invalid address(es): <bad>']);
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::FAILURE, $tester->execute(['--to' => '<bad>']));
        $this->assertStringContainsString('Invalid address(es): <bad>', $tester->getDisplay());
        $this->assertSame('panth:aivisibility:email', $this->command()->getName());
    }

    public function testSetsTheFrontendAreaWhenNoneIsSet(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willThrowException(
            new \Magento\Framework\Exception\LocalizedException(__('Area code is not set'))
        );
        $state->expects($this->once())->method('setAreaCode')->with('frontend');
        $state->expects($this->never())->method('emulateAreaCode');
        $this->sender->method('sendNow')
            ->willReturn(['sent' => true, 'recipients' => ['ops@shop.test'], 'message' => 'Sent.']);

        $tester = new CommandTester(new EmailCommand($this->sender, $state, $this->scope));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
    }

    public function testSimulatedNeedsDryRunAndIsNeverSent(): void
    {
        $this->sender->expects($this->never())->method('sendNow');
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::FAILURE, $tester->execute(['--simulated' => true]));
        $this->assertStringContainsString('--simulated only works with --dry-run', $tester->getDisplay());
        $this->assertFalse($this->scope->isIncludingSimulated());
    }

    public function testSimulatedDryRunPreviewIncludesSimulatedRows(): void
    {
        $this->sender->method('preview')->willReturnCallback(function (): array {
            return ['subject' => $this->scope->isIncludingSimulated() ? 'with simulated' : 'real only',
                'recipients' => [], 'text' => 'body'];
        });
        $tester = new CommandTester($this->command());

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true, '--simulated' => true]));
        $this->assertStringContainsString('Subject: with simulated', $tester->getDisplay());
    }

    /**
     * @return EmailCommand
     */
    private function command(): EmailCommand
    {
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn('crontab');
        return new EmailCommand($this->sender, $state, $this->scope);
    }
}
