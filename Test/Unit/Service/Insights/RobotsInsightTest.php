<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Insights;

use Panth\AiVisibility\Model\Catalogue\BotCatalogue;
use Panth\AiVisibility\Service\BotClassifier;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\ScopeInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Service\Insights\InsightProvider;
use Panth\AiVisibility\Service\Insights\RobotsInsight;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\DateRange;
use PHPUnit\Framework\TestCase;

class RobotsInsightTest extends TestCase
{
    /**
     * @var DateRange
     */
    private DateRange $range;

    protected function setUp(): void
    {
        $this->range = new DateRange('2026-10-01', '2026-10-30', '30');
    }

    public function testSilentWhenRobotsSeoIsNotInstalled(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->expects($this->never())->method('getValue');
        $insight = new RobotsInsight($this->modules(false), $scope, $this->directory());
        $this->assertSame([], $insight->collect($this->range, 0, false));
    }

    public function testSilentWhenRobotsSeoIsSwitchedOff(): void
    {
        $insight = new RobotsInsight($this->modules(true), $this->config(['general/enabled' => '0']), $this->directory());
        $this->assertSame([], $insight->collect($this->range, 0, false));
    }

    public function testAllAllowedGivesNoInsight(): void
    {
        $insight = new RobotsInsight($this->modules(true), $this->config([]), $this->directory());
        $this->assertSame([], $insight->collect($this->range, 0, false));
    }

    public function testBlockedSearchAndLiveBotsAreAWarning(): void
    {
        $insight = new RobotsInsight(
            $this->modules(true),
            $this->config(['llm_bots/oai_searchbot' => '0', 'llm_bots/chatgpt_user' => '0']),
            $this->directory()
        );
        $result = $insight->collect($this->range, 0, false);
        $this->assertCount(1, $result);
        $this->assertSame(InsightProvider::LEVEL_WARNING, $result[0]['level']);
        $this->assertStringContainsString('OAI-SearchBot', $result[0]['text']);
        $this->assertStringContainsString('ChatGPT-User', $result[0]['text']);
    }

    public function testBlockedTrainingBotsAreOnlyInfo(): void
    {
        $insight = new RobotsInsight(
            $this->modules(true),
            $this->config(['llm_bots/gptbot' => '0', 'llm_bots/ccbot' => '0', 'llm_bots/google_extended' => '0']),
            $this->directory()
        );
        $result = $insight->collect($this->range, 0, false);
        $this->assertCount(1, $result);
        $this->assertSame(InsightProvider::LEVEL_INFO, $result[0]['level']);
        $this->assertStringContainsString('GPTBot', $result[0]['text']);
        $this->assertStringContainsString('CCBot', $result[0]['text']);
        $this->assertStringNotContainsString('Google', $result[0]['text']);
    }

    public function testCustomRobotsBodyIsReportedInsteadOfSwitches(): void
    {
        $insight = new RobotsInsight(
            $this->modules(true),
            $this->config(['robots_txt/override_enabled' => '1', 'llm_bots/oai_searchbot' => '0']),
            $this->directory()
        );
        $result = $insight->collect($this->range, 0, false);
        $this->assertCount(1, $result);
        $this->assertSame(InsightProvider::LEVEL_INFO, $result[0]['level']);
        $this->assertStringContainsString('custom', $result[0]['title']);
    }

    public function testStoreViewScopeIsUsedForASelectedStore(): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static function (string $path, string $scopeType = 'default', $scopeCode = null) {
                if ($path === RobotsInsight::XML_PATH_OVERRIDE) {
                    return '0';
                }
                $storeTwo = $scopeType === ScopeInterface::SCOPE_STORE && $scopeCode === 2;
                return $storeTwo && $path === 'panth_robots_seo/llm_bots/perplexitybot' ? '0' : '1';
            }
        );
        $insight = new RobotsInsight($this->modules(true), $scope, $this->directory());
        $result = $insight->collect($this->range, 2, false);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('PerplexityBot', $result[0]['text']);
        $this->assertSame([], $insight->collect($this->range, 0, false));
    }

    public function testFieldMapOnlyPointsToCatalogueCodes(): void
    {
        foreach (RobotsInsight::FIELD_MAP as $code) {
            $this->assertArrayHasKey($code, BotCatalogue::BOTS);
        }
    }

    /**
     * Module manager stub
     *
     * @param bool $enabled
     * @return ModuleManager
     */
    private function modules(bool $enabled): ModuleManager
    {
        $manager = $this->createStub(ModuleManager::class);
        $manager->method('isEnabled')->willReturnCallback(static fn (string $name) => $name === 'Panth_RobotsSeo' && $enabled);
        return $manager;
    }

    /**
     * Default-scope config: Robots SEO enabled, every bot allowed, plus overrides
     *
     * @param array<string, string> $overrides path below panth_robots_seo/ => value
     * @return ScopeConfigInterface
     */
    private function config(array $overrides): ScopeConfigInterface
    {
        $values = ['general/enabled' => '1', 'robots_txt/override_enabled' => '0'];
        foreach (array_keys(RobotsInsight::FIELD_MAP) as $field) {
            $values['llm_bots/' . $field] = '1';
        }
        $values = array_merge($values, $overrides);
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static fn (string $path) => $values[substr($path, strlen('panth_robots_seo/'))] ?? null
        );
        return $scope;
    }

    /**
     * Bot directory with the built-in catalogue only
     *
     * @return BotDirectory
     */
    private function directory(): BotDirectory
    {
        $rules = $this->createStub(RuleProvider::class);
        $rules->method('botRules')->willReturn([]);
        $config = $this->createStub(Config::class);
        $config->method('customBotPatterns')->willReturn([]);
        return new BotDirectory(new BotClassifier($config, $rules));
    }
}
