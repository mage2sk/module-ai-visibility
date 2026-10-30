<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\CrawlFacts;
use Panth\AiVisibility\Service\Recommendation\Rule\BotErrorsOrSlow;
use Panth\AiVisibility\Service\Report\BotDirectory;
use PHPUnit\Framework\TestCase;

class BotErrorsOrSlowTest extends TestCase
{
    public function testEmptyDataGivesNoFinding(): void
    {
        $this->assertSame([], $this->rule($this->facts(0, 0), 20)->evaluate(1, $this->today()));
    }

    /**
     * @dataProvider volumes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('volumes')]
    public function testEachKindHasItsOwnMinVolume(int $errors, int $slowHits, array $expectedRefs): void
    {
        $findings = $this->rule($this->facts($errors, $slowHits), 20)->evaluate(1, $this->today());
        $this->assertSame($expectedRefs, array_map(static fn (Finding $f): string => $f->subjectRef, $findings));
    }

    public static function volumes(): array
    {
        return [
            'both below' => [19, 19, []],
            'errors only' => [20, 5, [BotErrorsOrSlow::SUBJECT_REF_ERRORS]],
            'slow only' => [0, 40, [BotErrorsOrSlow::SUBJECT_REF_SLOW]],
            'both' => [25, 40, [BotErrorsOrSlow::SUBJECT_REF_ERRORS, BotErrorsOrSlow::SUBJECT_REF_SLOW]],
        ];
    }

    public function testErrorFindingEvidenceAndMetric(): void
    {
        $finding = $this->rule($this->facts(30, 0), 20)->evaluate(4, $this->today())[0];

        $this->assertSame(4, $finding->storeId);
        $this->assertSame(MetricProviderInterface::METRIC_BOT_5XX, $finding->metric);
        $this->assertSame(Finding::SUBJECT_PATH, $finding->subjectType);
        $this->assertSame(30, $finding->affectedCount);
        $this->assertSame(['/checkout/broken'], $finding->subjects);
        $this->assertSame('AI bots got 30 server errors (5xx) on 1 pages in the last 7 days', $finding->title);
        $this->assertSame(503, $finding->evidence['top_paths'][0]['status']);
        $this->assertSame(10.0, $finding->evidence['share_pct']);
        $this->assertSame('ClaudeBot', $finding->evidence['top_bots'][0]['name']);
    }

    public function testSlowFindingUsesThresholdAndAverageMetric(): void
    {
        $facts = $this->facts(0, 45);
        $finding = $this->rule($facts, 20)->evaluate(1, $this->today())[0];

        $this->assertSame(MetricProviderInterface::METRIC_BOT_AVG_MS, $finding->metric);
        $this->assertSame(45, $finding->affectedCount);
        $this->assertSame(['/slow-category.html'], $finding->subjects);
        $this->assertSame(
            '2 pages took over 2 seconds on average to answer AI bots (45 requests, last 7 days)',
            $finding->title
        );
        $this->assertSame(2000, $finding->evidence['threshold_ms']);
        $this->assertSame(3100, $finding->evidence['top_paths'][0]['avg_ms']);
    }

    public function testQueriesUseTheStoreTheErrorStatusRangeAndTheSlowThreshold(): void
    {
        $summaryCalls = [];
        $slowCalls = [];
        $facts = $this->createStub(CrawlFacts::class);
        $facts->method('hitSummary')->willReturnCallback(function (...$args) use (&$summaryCalls) {
            $summaryCalls[] = $args;
            return ['hits' => 0, 'paths' => 0, 'bots' => 0];
        });
        $facts->method('slowPaths')->willReturnCallback(function (...$args) use (&$slowCalls) {
            $slowCalls[] = $args;
            return ['paths' => 0, 'hits' => 0, 'rows' => []];
        });

        $this->rule($facts, 20)->evaluate(2, $this->today());

        $this->assertSame([2, '2026-03-03 00:00:00', '2026-03-10 00:00:00', 500, 599], array_slice($summaryCalls[0], 0, 5));
        $this->assertSame(
            [2, '2026-03-03 00:00:00', '2026-03-10 00:00:00', BotErrorsOrSlow::SLOW_MS, BotErrorsOrSlow::MIN_PATH_HITS,
                BotErrorsOrSlow::SUBJECT_LIMIT],
            $slowCalls[0]
        );
    }

    /**
     * Facts stub
     *
     * @param int $errors 5xx hits
     * @param int $slowHits Hits on slow paths
     * @return CrawlFacts
     */
    private function facts(int $errors, int $slowHits): CrawlFacts
    {
        $facts = $this->createStub(CrawlFacts::class);
        $facts->method('hitSummary')->willReturnCallback(static function (...$args) use ($errors): array {
            return ($args[3] ?? 0) === 500
                ? ['hits' => $errors, 'paths' => $errors > 0 ? 1 : 0, 'bots' => 1]
                : ['hits' => 300, 'paths' => 40, 'bots' => 3];
        });
        $facts->method('hitCount')->willReturn(300);
        $facts->method('topHitPaths')->willReturn([
            ['path' => '/checkout/broken', 'hits' => $errors, 'bots' => 1, 'avg_ms' => 900, 'max_status' => 503,
                'last_seen' => '2026-03-09 01:00:00'],
        ]);
        $facts->method('topHitBots')->willReturn(['claudebot' => $errors]);
        $facts->method('slowPaths')->willReturn([
            'paths' => $slowHits > 0 ? 2 : 0,
            'hits' => $slowHits,
            'rows' => $slowHits > 0
                ? [['path' => '/slow-category.html', 'hits' => $slowHits, 'bots' => 2, 'avg_ms' => 3100, 'max_ms' => 9000]]
                : [],
        ]);
        return $facts;
    }

    /**
     * Rule under test
     *
     * @param CrawlFacts $facts
     * @param int $minVolume
     * @return BotErrorsOrSlow
     */
    private function rule(CrawlFacts $facts, int $minVolume): BotErrorsOrSlow
    {
        $config = $this->createStub(Config::class);
        $config->method('recommendationsMinVolume')->willReturn($minVolume);
        $directory = $this->createStub(BotDirectory::class);
        $directory->method('describe')->willReturn(['name' => 'ClaudeBot', 'company' => 'Anthropic', 'category' => 'training']);
        return new BotErrorsOrSlow($facts, $directory, $config);
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
