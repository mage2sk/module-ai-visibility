<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\ScopeInterface;
use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\CrawlFacts;
use Panth\AiVisibility\Service\Recommendation\Rule\RobotsBlocksAiSearch;
use Panth\AiVisibility\Service\Report\BotDirectory;
use PHPUnit\Framework\TestCase;

class RobotsBlocksAiSearchTest extends TestCase
{
    private const BOTS = [
        'gptbot' => ['name' => 'GPTBot', 'company' => 'OpenAI', 'category' => 'training'],
        'oai_searchbot' => ['name' => 'OAI-SearchBot', 'company' => 'OpenAI', 'category' => 'search'],
        'chatgpt_user' => ['name' => 'ChatGPT-User', 'company' => 'OpenAI', 'category' => 'live'],
        'perplexitybot' => ['name' => 'PerplexityBot', 'company' => 'Perplexity', 'category' => 'search'],
    ];

    public function testSilentWhenRobotsSeoIsNotInstalled(): void
    {
        $rule = $this->rule(['oai_searchbot' => '0'], false);
        $this->assertSame([], $rule->evaluate(1, $this->today()));
    }

    public function testSilentWhenRobotsSeoIsDisabledOrUsesACustomBody(): void
    {
        $this->assertSame([], $this->rule(['oai_searchbot' => '0'], true, '0')->evaluate(1, $this->today()));
        $this->assertSame([], $this->rule(['oai_searchbot' => '0'], true, '1', '1')->evaluate(1, $this->today()));
    }

    public function testBlockedTrainingBotsAndUnsetSwitchesAreIgnored(): void
    {
        $rule = $this->rule(['gptbot' => '0', 'oai_searchbot' => '1', 'perplexitybot' => null]);
        $this->assertSame([], $rule->evaluate(1, $this->today()));
    }

    public function testBlockedSearchAndLiveBotsGiveOneSiteFindingRegardlessOfVolume(): void
    {
        $facts = $this->createMock(CrawlFacts::class);
        $facts->expects($this->once())->method('botHitsByCode')
            ->with(2, ['oai_searchbot', 'chatgpt_user'], '2026-02-08', '2026-03-09')
            ->willReturn(['oai_searchbot' => 0, 'chatgpt_user' => 3]);
        $rule = $this->rule(['gptbot' => '0', 'oai_searchbot' => '0', 'chatgpt_user' => '0'], true, '1', '0', $facts);

        $findings = $rule->evaluate(2, $this->today());

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame(RobotsBlocksAiSearch::CODE, $finding->ruleCode);
        $this->assertSame(2, $finding->storeId);
        $this->assertSame(Finding::SUBJECT_SITE, $finding->subjectType);
        $this->assertSame('site', $finding->subjectRef);
        $this->assertSame(['site'], $finding->subjects);
        $this->assertSame(MetricProviderInterface::METRIC_SEARCH_BOT_HITS, $finding->metric);
        $this->assertSame(2, $finding->affectedCount);
        $this->assertSame(100, $finding->impactScore);
        $this->assertSame(
            'robots.txt blocks 2 AI search or answer bot(s): OAI-SearchBot, ChatGPT-User',
            $finding->title
        );
        $this->assertSame(3, $finding->evidence['blocked'][1]['hits_30d']);
        $this->assertSame('panth_robots_seo/llm_bots/oai_searchbot', $finding->evidence['blocked'][0]['setting']);
    }

    public function testReadsStoreScopeForAStoreViewAndDefaultScopeForZero(): void
    {
        $scopes = [];
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(function (...$args) use (&$scopes) {
            $scopes[] = [$args[1] ?? 'default', $args[2] ?? null];
            return null;
        });
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(true);
        $rule = new RobotsBlocksAiSearch(
            $modules,
            $config,
            $this->createStub(BotDirectory::class),
            $this->createStub(CrawlFacts::class)
        );

        $rule->evaluate(3, $this->today());
        $rule->evaluate(0, $this->today());

        $this->assertSame([ScopeInterface::SCOPE_STORE, 3], $scopes[0]);
        $this->assertSame(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopes[1][0]);
    }

    /**
     * Rule with Robots SEO switches
     *
     * @param array<string, string|null> $switches llm_bots field => value
     * @param bool $installed
     * @param string $enabled
     * @param string $override
     * @param CrawlFacts|null $facts
     * @return RobotsBlocksAiSearch
     */
    private function rule(
        array $switches,
        bool $installed = true,
        string $enabled = '1',
        string $override = '0',
        ?CrawlFacts $facts = null
    ): RobotsBlocksAiSearch {
        $values = [
            'panth_robots_seo/general/enabled' => $enabled,
            'panth_robots_seo/robots_txt/override_enabled' => $override,
        ];
        foreach ($switches as $field => $value) {
            $values['panth_robots_seo/llm_bots/' . $field] = $value;
        }
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(static fn (string $path) => $values[$path] ?? null);
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturnCallback(static fn (string $name): bool => $name === 'Panth_RobotsSeo' && $installed);
        $directory = $this->createStub(BotDirectory::class);
        $directory->method('describe')->willReturnCallback(
            static fn (string $code): array => self::BOTS[$code] ?? ['name' => $code, 'company' => $code, 'category' => 'other']
        );
        if ($facts === null) {
            $facts = $this->createStub(CrawlFacts::class);
            $facts->method('botHitsByCode')->willReturn([]);
        }
        return new RobotsBlocksAiSearch($modules, $config, $directory, $facts);
    }

    /**
     * Evaluation day
     *
     * @return \DateTimeImmutable
     */
    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-10', new \DateTimeZone('UTC'));
    }
}
