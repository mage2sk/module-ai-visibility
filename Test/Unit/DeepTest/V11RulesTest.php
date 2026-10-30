<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\DataScope;
use Panth\AiVisibility\Service\Recommendation\CrawlFacts;
use Panth\AiVisibility\Service\Recommendation\Engine;
use Panth\AiVisibility\Service\Recommendation\RedirectGuesser;
use Panth\AiVisibility\Service\Recommendation\Repository;
use Panth\AiVisibility\Service\Recommendation\Rule\Bot404;
use Panth\AiVisibility\Service\Recommendation\Rule\BotErrorsOrSlow;
use Panth\AiVisibility\Service\Recommendation\Rule\DiscoveryBacklog;
use Panth\AiVisibility\Service\Recommendation\Rule\LiveAnswersNoSales;
use Panth\AiVisibility\Service\Recommendation\Rule\LlmsNotServed;
use Panth\AiVisibility\Service\Recommendation\Rule\ParameterCrawlWaste;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\GuardedRule;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\RuleSupport;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;
use Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule\RuleDbTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep test E2 (rules engine). Mirrors the live store-2 runs: thresholds, lifecycle, feedback ranking,
 * rule isolation. Tests named "...Defect..." fail on 1ad2d43 and prove a bug found live.
 */
class V11RulesTest extends TestCase
{
    use RuleDbTrait;

    private const NOW = 1805090400; // 2027-03-15 06:00:00 UTC

    /** @var array<int, array> */
    private array $open = [];

    /** @var array<string, array> */
    private array $existing = [];

    /** @var string[] */
    private array $penalised = [];

    /** @var int[] */
    private array $resolvedIds = [];

    /** @var array<int, array> */
    private array $savedRows = [];

    protected function setUp(): void
    {
        $this->open = [];
        $this->existing = [];
        $this->penalised = [];
        $this->resolvedIds = [];
        $this->savedRows = [];
        $this->queries = [];
        $this->routes = [];
        $this->warnings = [];
        $this->scope = null;
    }

    // ------------------------------------------------------------------ defects (fail on 1ad2d43)

    /**
     * Live S10: a GuardedRule (base of R5-R10) whose query throws returns [], so the engine resolves the
     * rule's open recommendations and reports no error (exit code 0). Engine contract: "open rows of the
     * failing rule stay untouched" and the error is reported.
     */
    public function testDefectGuardedRuleFailureMustKeepOpenRowsAndBeReported(): void
    {
        $this->open = [['rec_id' => 7, 'fingerprint' => sha1('live_answers_no_sales|2|paths'),
            'rule_code' => 'live_answers_no_sales', 'title' => 't', 'impact_score' => 28, 'affected_count' => 20]];
        $rule = new class ($this->support(), $this->logger()) extends GuardedRule {
            public function code(): string
            {
                return 'live_answers_no_sales';
            }

            public function label(): string
            {
                return 'x';
            }

            public function metric(): string
            {
                return 'ai_conversion';
            }

            protected function find(int $storeId, \DateTimeImmutable $today): array
            {
                throw new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }
        };
        $result = $this->engine(['live_answers_no_sales' => $rule])->runAt(2, false, self::NOW);

        $this->assertSame([], $this->resolvedIds, 'open recommendation of the failing rule was resolved');
        $this->assertNotEmpty($result['errors'], 'failing rule was not reported (CLI exits 0)');
    }

    /**
     * Live: recommend --simulated (DataScope includes simulated rows) with only simulated traffic
     * (110 crawler hits, 10/10 llms.txt requests answered 404) -> R7 silent, because crawlTotals() and
     * llmsFetchesByBot() are called with includeSim=false while llmsStatus() follows the scope.
     */
    public function testDefectLlmsNotServedIgnoresSimulatedPreviewScope(): void
    {
        $this->scope = new DataScope();
        $this->scope->includeSimulated(true);
        $calls = [];
        $this->route('fetchRow', 'FROM `panth_aiv_crawl_daily`', ['requests' => 10, 's2xx' => 0, 's4xx' => 10, 's5xx' => 0]);
        $report = $this->createStub(ReportRepository::class);
        $report->method('crawlTotals')->willReturnCallback(
            function (DateRange $range, int $storeId, bool $includeSim) use (&$calls): array {
                $calls[] = $includeSim;
                return ['hits' => $includeSim ? 110 : 0, 'live' => 0];
            }
        );
        $report->method('llmsFetchesByBot')->willReturn([]);
        $rule = new LlmsNotServed(
            $this->support(),
            $this->logger(),
            $report,
            $this->bots(),
            $this->modules(true),
            $this->scopeConfig(true)
        );
        $findings = $rule->evaluate(2, self::day());

        $this->assertSame([true], $calls, 'crawlTotals must follow DataScope');
        $this->assertCount(1, $findings);
        $this->assertSame(LlmsNotServed::REASON_ERRORS, $findings[0]->evidence['reason']);
    }

    /**
     * Live (200k simulated hits, 750 pages with ~67 live fetches each): R5 went silent although
     * /compete-track-tote.html (10 live fetches, 12 AI visits, 0 orders) and /2-savvy-shoulder-tote.html
     * (6, 8, 0) still qualify, because livePages() keeps only the 500 most fetched pages before visits
     * are looked at.
     */
    public function testDefectLiveAnswersNoSalesMustNotCutPagesBeforeLookingAtVisits(): void
    {
        $rule = new LiveAnswersNoSales($this->support(), $this->logger(), $this->createStub(CatalogLookup::class));
        $rule->evaluate(2, self::day());
        $live = $this->queriesWith("p.category = ?");
        $this->assertNotEmpty($live);
        $this->assertTrue(
            !str_contains($live[0]['sql'], 'LIMIT ' . RuleSupport::SCAN_LIMIT) || str_contains($live[0]['sql'], 'panth_aiv_visit'),
            'live pages are cut to the top ' . RuleSupport::SCAN_LIMIT . ' by fetches before visits are considered'
        );
    }

    /**
     * R2 counts status 404 only, but its verification metric bot_404 counts every 4xx (s4xx). A correct
     * fix that answers 410 Gone for a dead URL is then verified as "no effect". Live: 3 x 410 on
     * /aivtest-e2-410 are ignored by R2 but counted by MetricProvider bot_404 (num=3).
     */
    public function testDefectBot404CountsTheSameStatusesAsItsVerificationMetric(): void
    {
        $ranges = [];
        $facts = $this->createStub(CrawlFacts::class);
        $facts->method('hitSummary')->willReturnCallback(
            function ($store, $from, $to, $min = 0, $max = 0) use (&$ranges): array {
                $ranges[] = [$min, $max];
                return ['hits' => 0, 'paths' => 0, 'bots' => 0];
            }
        );
        (new Bot404($facts, $this->createStub(RedirectGuesser::class), $this->bots(), $this->config(20)))
            ->evaluate(2, self::day());
        $this->assertSame([400, 499], $ranges[0]);
    }

    // ------------------------------------------------------------------ thresholds (pass)

    /**
     * @dataProvider bot404Volumes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bot404Volumes')]
    public function testBot404MinVolumeBoundary(int $hits, bool $fires): void
    {
        $facts = $this->createStub(CrawlFacts::class);
        $facts->method('hitSummary')->willReturnCallback(
            fn ($s, $f, $t, $min = 0) => $min > 0 ? ['hits' => $hits, 'paths' => 2, 'bots' => 3] : ['hits' => 178, 'paths' => 30, 'bots' => 5]
        );
        $facts->method('hitCount')->willReturn(178);
        $facts->method('topHitPaths')->willReturn([
            ['path' => '/aivtest-e2-404/a', 'hits' => $hits, 'bots' => 2, 'avg_ms' => 120, 'max_status' => 404, 'last_seen' => '2027-03-14 13:12:00'],
        ]);
        $facts->method('topHitBots')->willReturn(['gptbot' => $hits]);
        $findings = (new Bot404($facts, $this->createStub(RedirectGuesser::class), $this->bots(), $this->config(20)))
            ->evaluate(2, self::day());
        $this->assertSame($fires, $findings !== []);
        if ($fires) {
            $this->assertSame($hits, $findings[0]->affectedCount);
            $this->assertSame(round($hits * 100 / 178, 1), $findings[0]->evidence['share_pct']);
            $this->assertSame(['from' => '2027-03-08', 'to' => '2027-03-14'], $findings[0]->evidence['window']);
        }
    }

    public static function bot404Volumes(): array
    {
        return ['19 silent' => [19, false], '20 fires' => [20, true], '0 silent' => [0, false]];
    }

    /**
     * @dataProvider errorVolumes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('errorVolumes')]
    public function testBotErrorsOrSlowBoundaries(int $errors, int $slowHits, array $expectedRefs): void
    {
        $facts = $this->createStub(CrawlFacts::class);
        $facts->method('hitSummary')->willReturnCallback(
            fn ($s, $f, $t, $min = 0) => $min === 500 ? ['hits' => $errors, 'paths' => 2, 'bots' => 2] : ['hits' => 178, 'paths' => 30, 'bots' => 5]
        );
        $facts->method('hitCount')->willReturn(178);
        $facts->method('topHitPaths')->willReturn([]);
        $facts->method('topHitBots')->willReturn([]);
        $facts->method('slowPaths')->willReturn(['paths' => 2, 'hits' => $slowHits, 'rows' => []]);
        $findings = (new BotErrorsOrSlow($facts, $this->bots(), $this->config(20)))->evaluate(2, self::day());
        $this->assertSame($expectedRefs, array_map(static fn (Finding $f) => $f->subjectRef, $findings));
    }

    public static function errorVolumes(): array
    {
        return [
            'both below' => [19, 19, []],
            'both at min volume' => [20, 20, ['paths:5xx', 'paths:slow']],
            'only slow' => [0, 25, ['paths:slow']],
        ];
    }

    /**
     * @dataProvider queryShares
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('queryShares')]
    public function testParameterCrawlWasteThresholdIsStrictlyAbove30Percent(int $total, int $withQuery, bool $fires): void
    {
        $this->route('fetchRow', 'COUNT(*) AS total', ['total' => $total, 'with_query' => $withQuery]);
        $findings = (new ParameterCrawlWaste($this->support(), $this->logger(), $this->bots()))->evaluate(2, self::day());
        $this->assertSame($fires, $findings !== []);
        if ($fires) {
            $this->assertSame($withQuery, $findings[0]->affectedCount);
            $this->assertSame(round($withQuery * 100 / $total, 1), $findings[0]->evidence['share_percent']);
        }
    }

    public static function queryShares(): array
    {
        return [
            'live base 54/178 = 30.3%' => [178, 54, true],
            'live exactly 54/180 = 30.0%' => [180, 54, false],
            'below min volume' => [19, 19, false],
        ];
    }

    /**
     * @dataProvider backlogHits
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('backlogHits')]
    public function testDiscoveryBacklogMinVolumeAndWindow(array $hits, bool $fires): void
    {
        $rows = [];
        foreach ($hits as $i => $count) {
            $rows[] = ['candidate_id' => 477 + $i, 'type' => $i === 0 ? 'bot' : 'source', 'key_value' => 'AivTest-E2 ' . $i,
                'first_seen' => '2027-03-01 08:00:00', 'hits' => $count, 'llms_fetches' => 0];
        }
        $this->route('fetchAll', 'FROM `panth_aiv_candidate`', $rows);
        $findings = (new DiscoveryBacklog($this->support(), $this->logger()))->evaluate(2, self::day());
        $this->assertSame($fires, $findings !== []);
        $query = $this->queriesWith('FROM `panth_aiv_candidate`')[0];
        $this->assertContains('2027-03-08 00:00:00', $query['bind'], 'first_seen cutoff = today - 7 days');
        $this->assertContains(2, $query['bind'], 'store filter bound');
    }

    public static function backlogHits(): array
    {
        return ['15+6 = 21' => [[15, 6], true], '14+6 = 20' => [[14, 6], true], '13+6 = 19' => [[13, 6], false]];
    }

    // ------------------------------------------------------------------ engine lifecycle / ranking (pass)

    public function testFeedbackHalvesRankAndPushesRuleBelowMaxOpenCut(): void
    {
        $this->penalised = ['aivtest_f1'];
        $findings = [];
        for ($i = 1; $i <= 49; $i++) {
            $findings[] = new Finding('aivtest_f2', 2, 'path', 'f2_' . $i, 'T', [], 0, 50);
        }
        $result = $this->engine([
            'aivtest_f1' => $this->fixed('aivtest_f1', [
                new Finding('aivtest_f1', 2, 'path', 'f1a', 'T', [], 0, 80),
                new Finding('aivtest_f1', 2, 'path', 'f1b', 'T', [], 0, 70),
            ]),
            'aivtest_f2' => $this->fixed('aivtest_f2', $findings),
        ])->runAt(2, true, self::NOW);
        $ranks = [];
        $over = [];
        foreach ($result['findings'] as $f) {
            $ranks[$f['subject_ref']] = $f['rank_score'];
            if ($f['action'] === Engine::ACTION_OVER_LIMIT) {
                $over[] = $f['subject_ref'];
            }
        }
        $this->assertEquals(24.0, $ranks['f1a']);
        $this->assertEquals(21.0, $ranks['f1b']);
        $this->assertEquals(30.0, $ranks['f2_1']);
        $this->assertSame(['f1b'], $over);
        $this->assertSame(50, $result['created']);
    }

    /**
     * @dataProvider suppression
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('suppression')]
    public function testAppliedAndDismissedStayClosedFor30Days(string $status, string $changedAt, string $expected): void
    {
        $finding = new Finding('aivtest_a', 2, 'path', 'a1', 'T', [], 100, 0);
        $this->existing[$finding->fingerprint()] = ['rec_id' => 3, 'fingerprint' => $finding->fingerprint(),
            'status' => $status, 'status_changed_at' => $changedAt, 'is_simulated' => 0, 'feedback' => null];
        $result = $this->engine(['aivtest_a' => $this->fixed('aivtest_a', [$finding])])->runAt(2, true, self::NOW);
        $this->assertSame($expected, $result['findings'][0]['action']);
    }

    public static function suppression(): array
    {
        return [
            'applied 30d - 1s' => ['applied', '2027-02-13 06:00:01', 'suppressed'],
            'applied exactly 30d' => ['applied', '2027-02-13 06:00:00', 'reopen'],
            'dismissed 1 day' => ['dismissed', '2027-03-14 06:00:00', 'suppressed'],
            'dismissed 31 days' => ['dismissed', '2027-02-12 06:00:00', 'reopen'],
            'resolved yesterday' => ['resolved', '2027-03-14 06:00:00', 'reopen'],
            'applied without change time' => ['applied', '', 'suppressed'],
        ];
    }

    public function testThrowingRuleKeepsOnlyItsOwnOpenRowsAndOthersStillRun(): void
    {
        $this->open = [
            ['rec_id' => 5, 'fingerprint' => sha1('b'), 'rule_code' => 'aivtest_b', 'title' => 't', 'impact_score' => 1, 'affected_count' => 1],
            ['rec_id' => 6, 'fingerprint' => sha1('c'), 'rule_code' => 'aivtest_c', 'title' => 't', 'impact_score' => 1, 'affected_count' => 1],
        ];
        $boom = $this->createStub(\Panth\AiVisibility\Api\RecommendationRuleInterface::class);
        $boom->method('code')->willReturn('aivtest_b');
        $boom->method('evaluate')->willThrowException(new \RuntimeException('boom'));
        $result = $this->engine([
            'aivtest_b' => $boom,
            'aivtest_c' => $this->fixed('aivtest_c', []),
            'aivtest_a' => $this->fixed('aivtest_a', [new Finding('aivtest_a', 2, 'path', 'a1', 'T', [], 100, 0)]),
        ])->runAt(2, false, self::NOW);
        $this->assertSame([6], $this->resolvedIds);
        $this->assertSame(1, $result['created']);
        $this->assertCount(1, $result['errors']);
    }

    public function testFindingsForAnotherStoreOrRuleCodeAreIgnored(): void
    {
        $result = $this->engine(['aivtest_a' => $this->fixed('aivtest_a', [
            new Finding('aivtest_a', 1, 'path', 'other-store', 'T', [], 10, 0),
            new Finding('aivtest_x', 2, 'path', 'other-code', 'T', [], 10, 0),
        ])])->runAt(2, false, self::NOW);
        $this->assertSame(0, $result['created']);
        $this->assertSame([], $this->savedRows);
    }

    /**
     * @dataProvider liveScores
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('liveScores')]
    public function testEngineScoresMatchLiveRun(string $rule, int $affected, int $own, int $expected): void
    {
        $this->assertSame($expected, Engine::score(new Finding($rule, 2, 'site', 's', 'T', [], $affected, $own)));
    }

    public static function liveScores(): array
    {
        // values printed by the live store-2 run (2027-03-15)
        return [
            'R1' => ['robots_blocks_ai_search', 1, 100, 100],
            'R2' => ['bot_404', 20, 0, 26],
            'R3' => ['bot_errors_or_slow', 20, 0, 30],
            'R4' => ['never_crawled_products', 186, 0, 34],
            'R5' => ['live_answers_no_sales', 20, 40, 28],
            'R6' => ['thin_content_ai_read', 36, 31, 20],
            'R7' => ['llms_not_served', 4, 14, 10],
            'R8' => ['parameter_crawl_waste', 54, 35, 22],
            'R9' => ['ai_visitors_out_of_stock', 21, 34, 24],
            'R10' => ['discovery_backlog', 2, 20, 8],
        ];
    }

    // ------------------------------------------------------------------ helpers

    private static function day(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 00:00:00', new \DateTimeZone('UTC'));
    }

    private function engine(array $rules): Engine
    {
        $repository = $this->createStub(Repository::class);
        $repository->method('byFingerprints')->willReturnCallback(
            fn (array $fps): array => array_intersect_key($this->existing, array_flip($fps))
        );
        $repository->method('openRows')->willReturnCallback(fn (): array => $this->open);
        $repository->method('penalisedRules')->willReturnCallback(fn (): array => $this->penalised);
        $repository->method('save')->willReturnCallback(function (array $rows): int {
            array_push($this->savedRows, ...$rows);
            return count($rows);
        });
        $repository->method('resolve')->willReturnCallback(function (array $ids): void {
            array_push($this->resolvedIds, ...$ids);
        });
        $repository->method('transactional')->willReturnCallback(fn (callable $callback) => $callback());
        $config = $this->createStub(Config::class);
        $config->method('recommendationsMaxOpen')->willReturn(50);
        $config->method('isEnabled')->willReturn(true);
        return new Engine(
            $repository,
            $config,
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(LoggerInterface::class),
            $rules
        );
    }

    private function fixed(string $code, array $findings): \Panth\AiVisibility\Api\RecommendationRuleInterface
    {
        $rule = $this->createStub(\Panth\AiVisibility\Api\RecommendationRuleInterface::class);
        $rule->method('code')->willReturn($code);
        $rule->method('evaluate')->willReturn($findings);
        return $rule;
    }

    private function config(int $minVolume): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('recommendationsMinVolume')->willReturn($minVolume);
        return $config;
    }

    private function bots(): BotDirectory
    {
        $bots = $this->createStub(BotDirectory::class);
        $bots->method('describe')->willReturn(['name' => 'GPTBot', 'company' => 'OpenAI', 'category' => 'training']);
        return $bots;
    }

    private function modules(bool $enabled): ModuleManager
    {
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturn($enabled);
        return $modules;
    }

    private function scopeConfig(bool $flag): ScopeConfigInterface
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn($flag);
        return $scope;
    }
}
