<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\Rule\LlmsNotServed;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;
use PHPUnit\Framework\TestCase;

class LlmsNotServedTest extends TestCase
{
    use RuleDbTrait;

    /**
     * @var array<int, array{0: string, 1: int, 2: bool}>
     */
    private array $reportCalls = [];

    /**
     * @var array<int, array>
     */
    private array $configCalls = [];

    private function rule(
        int $crawl,
        bool $module,
        bool $setting,
        array $status = [],
        int $minVolume = 20,
        array $other = [],
        string $urlStoreCode = ''
    ): LlmsNotServed {
        $this->route('fetchRow', 'FROM `panth_aiv_path_daily`', $other + ['requests' => 0, 's4xx' => 0, 's5xx' => 0]);
        $this->route('fetchRow', 'FROM `panth_aiv_crawl_daily`', $status + [
            'requests' => 0, 's2xx' => 0, 's4xx' => 0, 's5xx' => 0,
        ]);
        $report = $this->createStub(ReportRepository::class);
        $report->method('crawlTotals')->willReturnCallback(
            function (DateRange $range, int $storeId, bool $includeSim) use ($crawl): array {
                $this->reportCalls[] = [$range->from() . '..' . $range->to(), $storeId, $includeSim];
                return ['hits' => $crawl, 'live' => 0];
            }
        );
        $report->method('llmsFetchesByBot')->willReturnCallback(
            function (DateRange $range, int $storeId, bool $includeSim): array {
                $this->reportCalls[] = ['llms', $storeId, $includeSim];
                return ['gptbot' => 4];
            }
        );
        $bots = $this->createStub(BotDirectory::class);
        $bots->method('describe')->willReturn(['name' => 'GPTBot', 'company' => 'OpenAI', 'category' => 'training']);
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturn($module);
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(function (...$args) use ($setting): bool {
            $this->configCalls[] = $args;
            return $setting;
        });
        return new LlmsNotServed(
            $this->support($minVolume, $urlStoreCode),
            $this->logger(),
            $report,
            $bots,
            $modules,
            $scope
        );
    }

    public function testModuleDisabledWithBotTraffic(): void
    {
        $findings = $this->rule(500, false, false)->evaluate(1, self::today());

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame(Finding::SUBJECT_SITE, $finding->subjectType);
        $this->assertSame(['site'], $finding->subjects);
        $this->assertSame(MetricProviderInterface::METRIC_LLMS_FETCHES, $finding->metric);
        $this->assertSame(LlmsNotServed::REASON_DISABLED, $finding->evidence['reason']);
        $this->assertStringContainsString('module is not enabled', $finding->title);
        $this->assertSame(500, $finding->affectedCount);
        $this->assertSame([['2026-10-01..2026-10-30', 1, false], ['llms', 1, false]], $this->reportCalls);
        $this->assertSame([], $this->configCalls, 'setting not read when the module is off');
    }

    public function testSettingSwitchedOffReadsTheStoreScope(): void
    {
        $findings = $this->rule(500, true, false)->evaluate(2, self::today());

        $this->assertSame(LlmsNotServed::REASON_DISABLED, $findings[0]->evidence['reason']);
        $this->assertStringContainsString('switched off', $findings[0]->title);
        $this->assertSame([['panth_llms_txt/llms_txt/enabled', 'store', 2]], $this->configCalls);
        $this->assertStringContainsString('d.store_id = ?', $this->queries[0]['sql']);
        $this->assertSame(2, end($this->queries[0]['bind']));
    }

    public function testDisabledButAnsweredWithSuccessIsNotReported(): void
    {
        $status = ['requests' => 6, 's2xx' => 6];
        $this->assertSame([], $this->rule(500, false, false, $status)->evaluate(1, self::today()));
    }

    public function testErrorsWhenMostRequestsFail(): void
    {
        $status = ['requests' => 6, 's2xx' => 2, 's4xx' => 3, 's5xx' => 1];
        $findings = $this->rule(500, true, true, $status)->evaluate(1, self::today());

        $this->assertSame(LlmsNotServed::REASON_ERRORS, $findings[0]->evidence['reason']);
        $this->assertSame(4, $findings[0]->affectedCount);
        $this->assertStringContainsString('4 of 6', $findings[0]->title);
        $this->assertSame('GPTBot', $findings[0]->evidence['examples'][0]['bot']);
    }

    public function testFewErrorsAreNotReported(): void
    {
        $status = ['requests' => 10, 's2xx' => 8, 's4xx' => 2];
        $this->assertSame([], $this->rule(500, true, true, $status)->evaluate(1, self::today()));
    }

    public function testNeverFetchedWhileBotsCrawl(): void
    {
        $findings = $this->rule(80, true, true)->evaluate(1, self::today());

        $this->assertSame(LlmsNotServed::REASON_NEVER_FETCHED, $findings[0]->evidence['reason']);
        $this->assertStringContainsString('80 pages', $findings[0]->title);
    }

    public function testCrawlBelowMinimumVolumeFindsNothing(): void
    {
        $this->assertSame([], $this->rule(19, false, false)->evaluate(1, self::today()));
        $this->assertSame([], $this->queries, 'no status query below the volume');
    }

    public function testRealRowsOnlyAndAllStores(): void
    {
        $this->rule(500, true, true)->evaluate(0, self::today());

        $this->assertRealRowsOnly(['panth_aiv_crawl_daily']);
        $this->assertStringNotContainsString('store_id', $this->queries[0]['sql']);
        $this->assertSame([['panth_llms_txt/llms_txt/enabled', 'default', null]], $this->configCalls);
        $this->assertFalse($this->reportCalls[0][2], 'simulated rows excluded');
    }

    public function testSimulatedPreviewScopeReachesTheReportQueries(): void
    {
        $this->scope = new \Panth\AiVisibility\Service\DataScope();
        $this->scope->includeSimulated(true);
        $this->rule(500, false, false)->evaluate(1, self::today());

        $this->assertSame([['2026-10-01..2026-10-30', 1, true], ['llms', 1, true]], $this->reportCalls);
        foreach ($this->queries as $query) {
            $this->assertStringContainsString('is_simulated IN (0, 1)', $query['sql']);
        }
    }

    /**
     * /.well-known/llms.txt (and llms files in sub folders) answering 404 are not errors of the root file
     */
    public function testErrorsOfLlmsFilesOutsideTheRootAreLeftOut(): void
    {
        $status = ['requests' => 10, 's2xx' => 2, 's4xx' => 8];
        $other = ['requests' => 8, 's4xx' => 8, 's5xx' => 0];
        $this->assertSame([], $this->rule(500, true, true, $status, 20, $other)->evaluate(1, self::today()));

        $query = $this->queriesWith('FROM `panth_aiv_path_daily`')[0];
        $this->assertStringContainsString('(p.path LIKE ? OR p.path LIKE ?)', $query['sql']);
        $this->assertStringContainsString('p.path NOT IN (?, ?)', $query['sql']);
        $this->assertStringContainsString('p.is_simulated = 0', $query['sql']);
        $this->assertSame(
            ['2026-10-01', '2026-10-30', '%/llms.txt', '%/llms-full.txt', '/llms.txt', '/llms-full.txt'],
            array_slice($query['bind'], 0, 6)
        );
        $this->assertSame(1, end($query['bind']));
        $this->assertSame(substr_count($query['sql'], '?'), count($query['bind']));
    }

    public function testRootErrorsStillReportedNextToOtherLlmsPaths(): void
    {
        $status = ['requests' => 12, 's2xx' => 1, 's4xx' => 11];
        $other = ['requests' => 3, 's4xx' => 3, 's5xx' => 0];
        $findings = $this->rule(500, true, true, $status, 20, $other)->evaluate(1, self::today());

        $this->assertSame(LlmsNotServed::REASON_ERRORS, $findings[0]->evidence['reason']);
        $this->assertSame(8, $findings[0]->affectedCount);
        $this->assertSame(9, $findings[0]->evidence['llms_requests']);
        $this->assertStringContainsString('8 of 9', $findings[0]->title);
    }

    public function testStoreCodeInUrlCountsAsRoot(): void
    {
        $this->rule(500, true, true, [], 20, [], 'en')->evaluate(3, self::today());

        $query = $this->queriesWith('FROM `panth_aiv_path_daily`')[0];
        $this->assertStringContainsString('p.path NOT IN (?, ?, ?, ?)', $query['sql']);
        $this->assertSame(
            ['/llms.txt', '/llms-full.txt', '/en/llms.txt', '/en/llms-full.txt'],
            array_slice($query['bind'], 4, 4)
        );
    }
}
