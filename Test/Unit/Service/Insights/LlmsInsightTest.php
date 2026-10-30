<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Insights;

use Panth\AiVisibility\Service\BotClassifier;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Service\Insights\InsightProvider;
use Panth\AiVisibility\Service\Insights\LlmsInsight;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;
use PHPUnit\Framework\TestCase;

class LlmsInsightTest extends TestCase
{
    public function testModuleMissingWithFetchesIsInfoWithCount(): void
    {
        $result = $this->insight(['gptbot' => 7, 'claudebot' => 3], false, true)->collect($this->range(), 0, false);
        $this->assertCount(1, $result);
        $this->assertSame(InsightProvider::LEVEL_INFO, $result[0]['level']);
        $this->assertStringContainsString('10', $result[0]['text']);
        $this->assertStringContainsString('Panth_LlmsTxt', $result[0]['text']);
    }

    public function testModuleMissingWithoutFetchesIsInfo(): void
    {
        $result = $this->insight([], false, true)->collect($this->range(), 0, false);
        $this->assertSame(InsightProvider::LEVEL_INFO, $result[0]['level']);
        $this->assertStringContainsString('not enabled', $result[0]['text']);
    }

    public function testModuleInstalledButSwitchedOffCountsAsNotEnabled(): void
    {
        $result = $this->insight(['gptbot' => 2], true, false)->collect($this->range(), 0, false);
        $this->assertSame('No llms.txt module is active', $result[0]['title']);
    }

    public function testServedButNotFetched(): void
    {
        $result = $this->insight([], true, true)->collect($this->range(), 0, false);
        $this->assertSame(InsightProvider::LEVEL_INFO, $result[0]['level']);
        $this->assertSame('llms.txt was not fetched in this period', $result[0]['title']);
    }

    public function testFetchesPerBotAreListedWithNames(): void
    {
        $fetches = [
            'gptbot' => 9, 'claudebot' => 5, 'perplexitybot' => 4, 'ccbot' => 3, 'amazonbot' => 2, 'applebot' => 1,
        ];
        $result = $this->insight($fetches, true, true)->collect($this->range(), 0, false);
        $this->assertSame(InsightProvider::LEVEL_OK, $result[0]['level']);
        $this->assertStringContainsString('GPTBot (9)', $result[0]['text']);
        $this->assertStringContainsString('ClaudeBot (5)', $result[0]['text']);
        $this->assertStringContainsString('1 more', $result[0]['text']);
        $this->assertStringNotContainsString('Applebot', $result[0]['text']);
        $this->assertStringContainsString('24', $result[0]['text']);
    }

    /**
     * Insight with stubbed fetch counts and module / config state
     *
     * @param array $fetches
     * @param bool $moduleEnabled
     * @param bool $configEnabled
     * @return LlmsInsight
     */
    private function insight(array $fetches, bool $moduleEnabled, bool $configEnabled): LlmsInsight
    {
        $repository = $this->createStub(ReportRepository::class);
        $repository->method('llmsFetchesByBot')->willReturn($fetches);
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturnCallback(
            static fn (string $name) => $name === 'Panth_LlmsTxt' && $moduleEnabled
        );
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(
            static fn (string $path) => $path === LlmsInsight::XML_PATH_ENABLED && $configEnabled
        );
        $rules = $this->createStub(RuleProvider::class);
        $rules->method('botRules')->willReturn([]);
        $config = $this->createStub(Config::class);
        $config->method('customBotPatterns')->willReturn([]);
        return new LlmsInsight($repository, new BotDirectory(new BotClassifier($config, $rules)), $modules, $scope);
    }

    /**
     * Fixed range
     *
     * @return DateRange
     */
    private function range(): DateRange
    {
        return new DateRange('2026-10-01', '2026-10-30', '30');
    }
}
