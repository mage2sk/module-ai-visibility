<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Discovery;

use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\BotClassifier;
use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Api\RelabelerInterface;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Model\BotMatch;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Model\RuleWriter;
use Panth\AiVisibility\Model\SourceMatch;
use Panth\AiVisibility\Service\BotNames;
use Panth\AiVisibility\Service\Discovery\CandidateClassifier;
use Panth\AiVisibility\Service\Discovery\CandidateRepository;

/**
 * A real CandidateClassifier wired to recording stubs.
 */
trait ClassifierFixtureTrait
{
    /**
     * candidate_id => row
     */
    private array $candidateRows = [];

    private array $botRules = [];

    private array $sourceRules = [];

    private array $knownSources = ['chatgpt' => 'ChatGPT', 'perplexity' => 'Perplexity'];

    private ?BotMatch $knownBot = null;

    private ?SourceMatch $knownSource = null;

    private ?\Throwable $relabelFailure = null;

    private ?\Throwable $saveFailure = null;

    private array $savedRules = [];

    private array $deletedRules = [];

    private array $relabels = [];

    private array $statusChanges = [];

    private function botCandidate(int $id, string $ua, string $status = 'new'): void
    {
        $this->candidateRows[$id] = [
            'candidate_id' => (string)$id, 'type' => 'bot', 'key_value' => $ua, 'status' => $status,
            'hits' => '4', 'llms_fetches' => '1', 'robots_fetches' => '0', 'evidence' => '0',
            'first_seen' => '2026-05-01 10:00:00', 'last_seen' => '2026-05-01 11:00:00', 'is_simulated' => '0',
        ];
    }

    private function sourceCandidate(int $id, string $key, string $status = 'new'): void
    {
        $this->candidateRows[$id] = [
            'candidate_id' => (string)$id, 'type' => 'source', 'key_value' => $key, 'status' => $status,
            'hits' => '2', 'llms_fetches' => '0', 'robots_fetches' => '0', 'evidence' => '1',
            'first_seen' => '2026-05-01 10:00:00', 'last_seen' => '2026-05-01 11:00:00', 'is_simulated' => '0',
        ];
    }

    private function rule(int $ruleId, string $type, string $code, string $pattern, ?int $candidateId): array
    {
        return [
            'rule_id' => $ruleId, 'type' => $type, 'code' => $code, 'name' => 'Rule ' . $ruleId,
            'match_on' => $type === 'bot' ? 'ua' : 'host', 'pattern' => $pattern, 'category' => null,
            'company' => null, 'candidate_id' => $candidateId, 'created_at' => '2026-05-01 00:00:00',
        ];
    }

    private function classifier(): CandidateClassifier
    {
        $candidates = $this->createStub(CandidateRepository::class);
        $candidates->method('get')->willReturnCallback(fn (int $id) => $this->candidateRows[$id] ?? null);
        $candidates->method('setStatus')->willReturnCallback(function (int $id, string $status): void {
            $this->statusChanges[] = [$id, $status];
        });

        $ruleProvider = $this->createStub(RuleProvider::class);
        $ruleProvider->method('botRules')->willReturnCallback(fn () => $this->botRules);
        $ruleProvider->method('sourceRules')->willReturnCallback(fn () => $this->sourceRules);

        $ruleWriter = $this->createStub(RuleWriter::class);
        $ruleWriter->method('save')->willReturnCallback(function (array $rule): int {
            if ($this->saveFailure !== null) {
                throw $this->saveFailure;
            }
            $this->savedRules[] = $rule;
            return 42;
        });
        $ruleWriter->method('delete')->willReturnCallback(function (int $id): void {
            $this->deletedRules[] = $id;
        });

        $relabeler = $this->createStub(RelabelerInterface::class);
        $relabeler->method('relabelCandidate')->willReturnCallback(
            function (int $id, string $type, string $code, ?string $category = null): int {
                if ($this->relabelFailure !== null) {
                    throw $this->relabelFailure;
                }
                $this->relabels[] = [$id, $type, $code, $category];
                return 3;
            }
        );

        $botClassifier = $this->createStub(BotClassifierInterface::class);
        $botClassifier->method('classify')->willReturnCallback(fn () => $this->knownBot);

        $sourceClassifier = $this->createStub(SourceClassifierInterface::class);
        $sourceClassifier->method('classifyHost')->willReturnCallback(fn () => $this->knownSource);
        $sourceClassifier->method('classifyUtm')->willReturnCallback(fn () => $this->knownSource);
        $sourceClassifier->method('allSources')->willReturnCallback(fn () => $this->knownSources);

        return new CandidateClassifier(
            $candidates,
            $ruleProvider,
            $ruleWriter,
            $relabeler,
            $botClassifier,
            $sourceClassifier,
            new BotNames(new BotClassifier($this->createStub(Config::class), $ruleProvider))
        );
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame([], $this->savedRules, 'no rule saved');
        $this->assertSame([], $this->relabels, 'nothing relabelled');
        $this->assertSame([], $this->statusChanges, 'no status changed');
        $this->assertSame([], $this->deletedRules, 'no rule deleted');
    }
}
