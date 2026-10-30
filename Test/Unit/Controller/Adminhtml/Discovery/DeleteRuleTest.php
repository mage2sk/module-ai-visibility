<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Discovery;

use Panth\AiVisibility\Controller\Adminhtml\Discovery\DeleteRule;
use Panth\AiVisibility\Test\Unit\Controller\Adminhtml\BackendContextTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeleteRuleTest extends TestCase
{
    use BackendContextTrait;
    use ClassifierFixtureTrait;

    private function post(array $params): void
    {
        $this->params = $params;
        (new DeleteRule($this->backendContext(), $this->classifier(), $this->createStub(LoggerInterface::class)))
            ->execute();
    }

    public function testAclResourceIsDiscovery(): void
    {
        $controller = new DeleteRule(
            $this->backendContext(),
            $this->classifier(),
            $this->createStub(LoggerInterface::class)
        );
        $this->assertAclResource($controller, 'Panth_AiVisibility::discovery');
    }

    public function testDeletesBotRuleAndMovesCandidateBackToNew(): void
    {
        $this->botCandidate(5, 'SimAgent/1.0', 'confirmed');
        $this->botRules = [$this->rule(3, 'bot', 'disc_sim', 'SimAgent', 5)];

        $this->post(['rule_id' => '3']);

        $this->assertSame([3], $this->deletedRules);
        $this->assertSame([[5, 'new']], $this->statusChanges);
        $this->assertStringContainsString('Deleted "Rule 3"', $this->messagesOfType('success')[0]);
        $this->assertStringContainsString('disc_sim', $this->messagesOfType('success')[0]);
        $this->assertSame('*/*/index', $this->redirectPath);
    }

    public function testDeletesSourceRuleWhoseCandidateWasPurged(): void
    {
        $this->sourceRules = [$this->rule(4, 'source', 'disc_helper', 'helper.example.invalid', 9)];

        $this->post(['rule_id' => '4']);

        $this->assertSame([4], $this->deletedRules);
        $this->assertSame([], $this->statusChanges);
        $this->assertCount(1, $this->messagesOfType('success'));
    }

    public function testUnknownRuleIsAnError(): void
    {
        $this->botRules = [$this->rule(3, 'bot', 'disc_sim', 'SimAgent', 5)];

        $this->post(['rule_id' => '99']);

        $this->assertStringContainsString('no longer exists', $this->messagesOfType('error')[0]);
        $this->assertNothingWritten();
    }

    public static function invalidIdProvider(): array
    {
        return [
            'missing' => [[]],
            'zero' => [['rule_id' => '0']],
            'text' => [['rule_id' => 'abc']],
            'negative' => [['rule_id' => '-3']],
            'array' => [['rule_id' => ['3']]],
        ];
    }

    /**
     * @dataProvider invalidIdProvider
     */
    #[DataProvider('invalidIdProvider')]
    public function testInvalidRuleIdIsRejected(array $params): void
    {
        $this->botRules = [$this->rule(3, 'bot', 'disc_sim', 'SimAgent', 5)];

        $this->post($params);

        $this->assertSame(['No rule was selected.'], $this->messagesOfType('error'));
        $this->assertNothingWritten();
    }

    public function testMissingFormKeyIsRejected(): void
    {
        $this->botRules = [$this->rule(3, 'bot', 'disc_sim', 'SimAgent', 5)];
        $this->formKeyValid = false;

        $this->post(['rule_id' => '3']);

        $this->assertStringContainsString('expired', $this->messagesOfType('error')[0]);
        $this->assertNothingWritten();
    }
}
