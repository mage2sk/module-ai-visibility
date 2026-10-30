<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Console\Command;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\RecommendationEngineInterface;
use Panth\AiVisibility\Console\Command\RecommendCommand;
use PHPUnit\Framework\TestCase;
use Panth\AiVisibility\Service\DataScope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class RecommendCommandTest extends TestCase
{
    /**
     * @var DataScope
     */
    private DataScope $scope;

    /**
     * @var array<int, array{0: int|null, 1: bool}>
     */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
    }

    public function testDryRunPrintsATableAndSavesNothing(): void
    {
        $command = $this->command([
            'created' => 1, 'updated' => 0, 'resolved' => 1, 'errors' => [],
            'findings' => [
                [
                    'store_id' => 1, 'rule_code' => 'bot_404', 'action' => 'create', 'rank_score' => 40.0,
                    'affected_count' => 1200, 'title' => 'AI bots hit 1,200 broken links (404) on <b>3</b> pages',
                    'evidence' => ['hits_404' => 1200], 'subjects' => ['/a', '/b'],
                ],
                ['store_id' => 1, 'rule_code' => 'bot_404', 'action' => 'resolve', 'rank_score' => 10,
                    'affected_count' => 30, 'title' => 'Old finding'],
            ],
        ]);
        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $display = $tester->getDisplay();
        $this->assertSame([[null, true]], $this->calls);
        $this->assertStringContainsString('Rule', $display);
        $this->assertStringContainsString('bot_404', $display);
        $this->assertStringContainsString('new', $display);
        $this->assertStringContainsString('resolved (not found again)', $display);
        $this->assertStringContainsString('1,200', $display);
        $this->assertStringContainsString('<b>3</b>', $display);
        $this->assertStringContainsString('Dry run, nothing was saved. Would create 1, update 0 and resolve 1', $display);
        $this->assertStringNotContainsString('hits_404', $display);
        $this->assertSame('panth:aivisibility:recommend', $command->getName());
    }

    public function testVerbosePrintsEvidence(): void
    {
        $tester = new CommandTester($this->command([
            'created' => 1, 'updated' => 0, 'resolved' => 0, 'errors' => [],
            'findings' => [[
                'store_id' => 2, 'rule_code' => 'bot_404', 'action' => 'create', 'rank_score' => 40,
                'affected_count' => 50, 'title' => 'T', 'evidence' => ['hits_404' => 50],
                'subjects' => array_map(static fn (int $i): string => '/p' . $i, range(1, 12)),
            ]],
        ]));

        $tester->execute(['--store' => '2'], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $display = $tester->getDisplay();
        $this->assertSame([[2, false]], $this->calls);
        $this->assertStringContainsString('"hits_404": 50', $display);
        $this->assertStringContainsString('... 2 more', $display);
        $this->assertStringContainsString('Created 1, updated 0 and resolved 0', $display);
    }

    public function testNoFindingsAndErrorsFail(): void
    {
        $tester = new CommandTester($this->command([
            'created' => 0, 'updated' => 0, 'resolved' => 0, 'findings' => [],
            'errors' => ['store 1, rule bot_404: boom'],
        ]));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('No recommendations', $tester->getDisplay());
        $this->assertStringContainsString('rule bot_404: boom', $tester->getDisplay());
        $this->assertStringContainsString('failed rules were left unchanged', $tester->getDisplay());
    }

    /**
     * @dataProvider badStores
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badStores')]
    public function testInvalidStoreFails(string $store, string $message): void
    {
        $tester = new CommandTester($this->command([]));

        $this->assertSame(Command::FAILURE, $tester->execute(['--store' => $store]));
        $this->assertStringContainsString($message, $tester->getDisplay());
        $this->assertSame([], $this->calls);
    }

    public function testSimulatedPreviewIncludesSimulatedRowsAndNeverSaves(): void
    {
        $tester = new CommandTester($this->command(['created' => 2, 'findings' => []]));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--store' => '1', '--simulated' => true]));
        $this->assertSame([[1, true]], $this->calls, 'forced dry run');
        $this->assertTrue($this->scope->isIncludingSimulated());
        $this->assertStringContainsString('Simulated rows are included (test preview).', $tester->getDisplay());
        $this->assertStringContainsString('Dry run, nothing was saved. Would create 2', $tester->getDisplay());
    }

    public function testRealRowsOnlyByDefault(): void
    {
        $tester = new CommandTester($this->command(['findings' => []]));
        $tester->execute([]);

        $this->assertFalse($this->scope->isIncludingSimulated());
        $this->assertSame([[null, false]], $this->calls);
    }

    public static function badStores(): array
    {
        return [
            'text' => ['abc', '--store must be a store view ID'],
            'zero' => ['0', '--store must be a store view ID'],
            'missing' => ['77', 'Store view 77 does not exist'],
        ];
    }

    /**
     * Command with an engine returning a fixed result
     *
     * @param array $result
     * @return RecommendCommand
     */
    private function command(array $result): RecommendCommand
    {
        $engine = $this->createStub(RecommendationEngineInterface::class);
        $engine->method('run')->willReturnCallback(function (?int $storeId = null, bool $dryRun = false) use ($result) {
            $this->calls[] = [$storeId, $dryRun];
            return $result;
        });
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function (int $id) {
            if ($id === 77) {
                throw new NoSuchEntityException(__('missing'));
            }
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            return $store;
        });
        $this->scope = new DataScope();
        return new RecommendCommand($engine, $storeManager, $this->scope);
    }
}
