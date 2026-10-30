<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Discovery;

use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Controller\Adminhtml\Discovery\Classify;
use Panth\AiVisibility\Model\BotMatch;
use Panth\AiVisibility\Model\SourceMatch;
use Panth\AiVisibility\Test\Unit\Controller\Adminhtml\BackendContextTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClassifyTest extends TestCase
{
    use BackendContextTrait;
    use ClassifierFixtureTrait;

    private const UA = 'Mozilla/5.0 (compatible; SimAgent/1.0; +https://example.invalid/bot)';

    private array $warnings = [];

    private function controller(): Classify
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });
        return new Classify($this->backendContext(), $this->classifier(), $logger);
    }

    private function post(array $params): void
    {
        $this->params = $params;
        $this->controller()->execute();
    }

    public function testAclResourceIsDiscovery(): void
    {
        $this->assertAclResource($this->controller(), 'Panth_AiVisibility::discovery');
    }

    public function testConfirmBotSavesPrefixedRuleRelabelsAndConfirms(): void
    {
        $this->botCandidate(5, self::UA);

        $this->post(['candidate_id' => '5', 'decision' => 'live', 'name' => '  Sim   Agent ', 'token' => '']);

        $this->assertSame([[
            'type' => 'bot',
            'code' => 'disc_sim_agent',
            'name' => 'Sim Agent',
            'match_on' => 'ua',
            'pattern' => 'SimAgent',
            'category' => 'live',
            'company' => 'Sim Agent',
            'candidate_id' => 5,
        ]], $this->savedRules);
        $this->assertSame([[5, 'bot', 'disc_sim_agent', 'live']], $this->relabels);
        $this->assertSame([[5, 'confirmed']], $this->statusChanges);
        $this->assertSame([], $this->messagesOfType('error'));
        $this->assertStringContainsString('3 earlier hit(s)', $this->messagesOfType('success')[0]);
        $this->assertSame('*/*/index', $this->redirectPath);
    }

    public function testConfirmBotWithExplicitTokenAndCompany(): void
    {
        $this->botCandidate(6, self::UA);

        $this->post([
            'candidate_id' => '6', 'decision' => 'training', 'name' => 'Sim', 'token' => 'simagent/1.0',
            'company' => 'Example Labs',
        ]);

        $this->assertSame('simagent/1.0', $this->savedRules[0]['pattern']);
        $this->assertSame('Example Labs', $this->savedRules[0]['company']);
        $this->assertSame('training', $this->savedRules[0]['category']);
    }

    public function testConfirmHostSource(): void
    {
        $this->sourceCandidate(8, 'host:assistant.example.invalid');

        $this->post(['candidate_id' => '8', 'decision' => 'confirm', 'name' => 'Example Assistant']);

        $this->assertSame([[
            'type' => 'source',
            'code' => 'disc_example_assistant',
            'name' => 'Example Assistant',
            'match_on' => 'host',
            'pattern' => 'assistant.example.invalid',
            'candidate_id' => 8,
        ]], $this->savedRules);
        $this->assertSame([[8, 'source', 'disc_example_assistant', null]], $this->relabels);
        $this->assertSame([[8, 'confirmed']], $this->statusChanges);
        $this->assertStringContainsString('3 earlier visit(s)', $this->messagesOfType('success')[0]);
    }

    public function testConfirmUtmSource(): void
    {
        $this->sourceCandidate(9, 'utm:askbot');

        $this->post(['candidate_id' => '9', 'decision' => 'confirm', 'name' => 'Ask Bot']);

        $this->assertSame('utm', $this->savedRules[0]['match_on']);
        $this->assertSame('askbot', $this->savedRules[0]['pattern']);
    }

    public static function statusProvider(): array
    {
        return [
            'not ai' => ['not_ai', 'not_ai', 'not AI'],
            'ignored' => ['ignored', 'ignored', 'Ignored'],
            'restore' => ['restore', 'new', 'back to the New list'],
        ];
    }

    /**
     * @dataProvider statusProvider
     */
    #[DataProvider('statusProvider')]
    public function testStatusOnlyDecisions(string $decision, string $status, string $needle): void
    {
        $this->botCandidate(5, self::UA, $decision === 'restore' ? 'ignored' : 'new');

        $this->post(['candidate_id' => '5', 'decision' => $decision, 'return_status' => 'ignored']);

        $this->assertSame([[5, $status]], $this->statusChanges);
        $this->assertSame([], $this->savedRules);
        $this->assertSame([], $this->relabels);
        $this->assertStringContainsString($needle, $this->messagesOfType('success')[0]);
        $this->assertSame(['status' => 'ignored'], $this->redirectParams);
    }

    public static function invalidProvider(): array
    {
        $ua = self::UA;
        return [
            'no candidate id' => [['decision' => 'live', 'name' => 'X'], 'No candidate'],
            'non-numeric id' => [['candidate_id' => '5abc', 'decision' => 'live', 'name' => 'X'], 'No candidate'],
            'array id' => [['candidate_id' => ['5'], 'decision' => 'live', 'name' => 'X'], 'No candidate'],
            'unknown decision' => [['candidate_id' => '5', 'decision' => 'delete', 'name' => 'X'], 'Choose'],
            'missing decision' => [['candidate_id' => '5', 'name' => 'X'], 'Choose'],
            'empty name' => [['candidate_id' => '5', 'decision' => 'live', 'name' => '   '], 'Enter a name'],
            'markup in name' => [
                ['candidate_id' => '5', 'decision' => 'live', 'name' => '<script>x</script>'],
                'Enter a name',
            ],
            'too long name' => [
                ['candidate_id' => '5', 'decision' => 'live', 'name' => str_repeat('a', 129)],
                'Enter a name',
            ],
            'bad company' => [
                ['candidate_id' => '5', 'decision' => 'live', 'name' => 'Sim', 'company' => '"quoted"'],
                'Enter a company',
            ],
            'token not in user agent' => [
                ['candidate_id' => '5', 'decision' => 'live', 'name' => 'Sim', 'token' => 'OtherBot'],
                'does not appear',
            ],
            'generic token' => [
                ['candidate_id' => '5', 'decision' => 'live', 'name' => 'Sim', 'token' => 'Mozilla'],
                'normal browser',
            ],
            'short token' => [
                ['candidate_id' => '5', 'decision' => 'live', 'name' => 'Sim', 'token' => 'Si'],
                'identifies this bot',
            ],
            'source decision on bot' => [['candidate_id' => '5', 'decision' => 'confirm', 'name' => 'Sim'], 'Training'],
            'unknown candidate' => [['candidate_id' => '77', 'decision' => 'live', 'name' => 'Sim'], 'no longer exists'],
            'token with punctuation' => [
                ['candidate_id' => '5', 'decision' => 'live', 'name' => 'Sim', 'token' => $ua],
                'identifies this bot',
            ],
        ];
    }

    /**
     * @dataProvider invalidProvider
     */
    #[DataProvider('invalidProvider')]
    public function testInvalidInputIsRejectedWithoutWrites(array $params, string $needle): void
    {
        $this->botCandidate(5, self::UA);

        $this->post($params);

        $errors = $this->messagesOfType('error');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString($needle, $errors[0]);
        $this->assertSame([], $this->messagesOfType('success'));
        $this->assertNothingWritten();
        $this->assertSame('*/*/index', $this->redirectPath);
    }

    public function testBotCategoryOnSourceCandidateIsRejected(): void
    {
        $this->sourceCandidate(8, 'host:assistant.example.invalid');

        $this->post(['candidate_id' => '8', 'decision' => 'search', 'name' => 'X']);

        $this->assertStringContainsString('AI referrer source', $this->messagesOfType('error')[0]);
        $this->assertNothingWritten();
    }

    public function testMissingFormKeyIsRejected(): void
    {
        $this->botCandidate(5, self::UA);
        $this->formKeyValid = false;

        $this->post(['candidate_id' => '5', 'decision' => 'live', 'name' => 'Sim']);

        $this->assertStringContainsString('expired', $this->messagesOfType('error')[0]);
        $this->assertNothingWritten();
    }

    public function testCodeClashWithAnotherRuleGetsSuffix(): void
    {
        $this->botCandidate(5, self::UA);
        $this->botRules = [
            $this->rule(1, 'bot', 'disc_sim_agent', 'OtherSim', 11),
            $this->rule(2, 'bot', 'disc_sim_agent_2', 'ThirdSim', 12),
        ];

        $this->post(['candidate_id' => '5', 'decision' => 'other', 'name' => 'Sim Agent']);

        $this->assertSame('disc_sim_agent_3', $this->savedRules[0]['code']);
    }

    public function testSourceCodeNeverClashesWithKnownSourceCodes(): void
    {
        $this->sourceCandidate(8, 'host:assistant.example.invalid');
        $this->knownSources['disc_helper'] = 'Helper';

        $this->post(['candidate_id' => '8', 'decision' => 'confirm', 'name' => 'Helper']);

        $this->assertSame('disc_helper_2', $this->savedRules[0]['code']);
    }

    public function testCodesNeverEqualBuiltInCodes(): void
    {
        $this->botCandidate(5, 'GPTBotClone/2.0');

        $this->post(['candidate_id' => '5', 'decision' => 'training', 'name' => 'gptbot']);

        $this->assertSame('disc_gptbot', $this->savedRules[0]['code']);
        $this->assertStringStartsWith('disc_', $this->savedRules[0]['code']);
    }

    public function testReconfirmReusesTheCandidateRuleCode(): void
    {
        $this->botCandidate(5, self::UA);
        $this->botRules = [$this->rule(1, 'bot', 'disc_first_name', 'SimAgent', 5)];

        $this->post(['candidate_id' => '5', 'decision' => 'search', 'name' => 'Renamed']);

        $this->assertSame('disc_first_name', $this->savedRules[0]['code']);
        $this->assertSame([[5, 'bot', 'disc_first_name', 'search']], $this->relabels);
    }

    public function testNameWithoutLatinCharactersFallsBackToTypeAndId(): void
    {
        $this->botCandidate(5, self::UA);

        $this->post(['candidate_id' => '5', 'decision' => 'live', 'name' => 'Бот']);

        $this->assertSame('disc_bot_5', $this->savedRules[0]['code']);
        $this->assertSame('Бот', $this->savedRules[0]['name']);
    }

    public function testTokenUsedByAnotherRuleIsRejected(): void
    {
        $this->botCandidate(5, self::UA);
        $this->botRules = [$this->rule(1, 'bot', 'disc_other', 'simagent', 11)];

        $this->post(['candidate_id' => '5', 'decision' => 'live', 'name' => 'Sim']);

        $this->assertStringContainsString('already uses the token', $this->messagesOfType('error')[0]);
        $this->assertNothingWritten();
    }

    public function testAlreadyKnownBotIsRejected(): void
    {
        $this->botCandidate(5, self::UA);
        $this->knownBot = new BotMatch('gptbot', 'GPTBot', 'OpenAI', 'training');

        $this->post(['candidate_id' => '5', 'decision' => 'live', 'name' => 'Sim']);

        $this->assertStringContainsString('already recognised as "GPTBot"', $this->messagesOfType('error')[0]);
        $this->assertNothingWritten();
    }

    public function testAlreadyKnownSourceIsRejected(): void
    {
        $this->sourceCandidate(8, 'host:chat.example.invalid');
        $this->knownSource = new SourceMatch('chatgpt', 'ChatGPT');

        $this->post(['candidate_id' => '8', 'decision' => 'confirm', 'name' => 'Chat']);

        $this->assertStringContainsString('already recognised as "ChatGPT"', $this->messagesOfType('error')[0]);
        $this->assertNothingWritten();
    }

    public function testInvalidSourceKeyIsRejected(): void
    {
        $this->sourceCandidate(8, 'host:bad..host');

        $this->post(['candidate_id' => '8', 'decision' => 'confirm', 'name' => 'Bad']);

        $this->assertStringContainsString('no valid host', $this->messagesOfType('error')[0]);
        $this->assertNothingWritten();
    }

    public function testConfirmedCandidateCannotBeIgnored(): void
    {
        $this->botCandidate(5, self::UA, 'confirmed');

        $this->post(['candidate_id' => '5', 'decision' => 'ignored']);

        $this->assertStringContainsString('Delete its rule', $this->messagesOfType('error')[0]);
        $this->assertNothingWritten();
    }

    public function testRelabelFailureKeepsStatusAndExplains(): void
    {
        $this->botCandidate(5, self::UA);
        $this->relabelFailure = new \RuntimeException('lock wait timeout');

        $this->post(['candidate_id' => '5', 'decision' => 'live', 'name' => 'Sim']);

        $this->assertCount(1, $this->savedRules);
        $this->assertSame([], $this->statusChanges);
        $error = $this->messagesOfType('error')[0];
        $this->assertStringContainsString('rule was saved', $error);
        $this->assertStringContainsString('lock wait timeout', $error);
    }

    public function testRuleWriterValidationMessageIsShown(): void
    {
        $this->botCandidate(5, self::UA);
        $this->saveFailure = new LocalizedException(__('The rule code may only contain a-z.'));

        $this->post(['candidate_id' => '5', 'decision' => 'live', 'name' => 'Sim']);

        $this->assertSame(['The rule code may only contain a-z.'], $this->messagesOfType('error'));
        $this->assertSame([], $this->statusChanges);
    }

    public function testUnexpectedErrorIsLoggedAndGeneric(): void
    {
        $this->botCandidate(5, self::UA);
        $this->saveFailure = new \RuntimeException('SQLSTATE secret detail');

        $this->post(['candidate_id' => '5', 'decision' => 'live', 'name' => 'Sim']);

        $error = $this->messagesOfType('error')[0];
        $this->assertStringNotContainsString('SQLSTATE', $error);
        $this->assertStringContainsString('could not be saved', $error);
        $this->assertCount(1, $this->warnings);
    }

    public function testInvalidReturnStatusIsIgnored(): void
    {
        $this->botCandidate(5, self::UA);

        $this->post(['candidate_id' => '5', 'decision' => 'ignored', 'return_status' => 'javascript:x']);

        $this->assertSame([], $this->redirectParams);
    }
}
