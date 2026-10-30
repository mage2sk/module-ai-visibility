<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\Rule\ParameterCrawlWaste;
use Panth\AiVisibility\Service\Report\BotDirectory;
use PHPUnit\Framework\TestCase;

class ParameterCrawlWasteTest extends TestCase
{
    use RuleDbTrait;

    private function rule(int $total, int $withQuery, int $minVolume = 20): ParameterCrawlWaste
    {
        $this->route('fetchRow', 'COUNT(*) AS total', ['total' => $total, 'with_query' => $withQuery]);
        $this->route('fetchAll', 'GROUP BY h.path_hash', [
            ['path_hash' => 'a', 'path' => '/gear.html', 'hits' => 30],
            ['path_hash' => 'b', 'path' => '/men.html', 'hits' => 9],
        ]);
        $this->route('fetchPairs', 'GROUP BY h.bot_code', ['gptbot' => 25, 'claudebot' => 14]);
        $bots = $this->createStub(BotDirectory::class);
        $bots->method('describe')->willReturnCallback(
            static fn (string $code): array => ['name' => strtoupper($code), 'company' => '', 'category' => 'training']
        );
        return new ParameterCrawlWaste($this->support($minVolume), $this->logger(), $bots);
    }

    public function testShareAboveThirtyPercentIsReported(): void
    {
        $findings = $this->rule(100, 31)->evaluate(1, self::today());

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame(Finding::SUBJECT_SITE, $finding->subjectType);
        $this->assertSame(MetricProviderInterface::METRIC_CANONICAL_CRAWL_SHARE, $finding->metric);
        $this->assertSame(31, $finding->affectedCount);
        $this->assertSame(31.0, $finding->evidence['share_percent']);
        $this->assertSame(30, $finding->evidence['threshold_percent']);
        $this->assertSame('/gear.html', $finding->evidence['examples'][0]['path']);
        $this->assertSame('GPTBOT', $finding->evidence['bots'][0]['bot']);
        $this->assertStringStartsWith('31% of AI crawler requests', $finding->title);
        $this->assertSame('2026-10-23', $finding->evidence['from']);
        $this->assertSame('2026-10-29', $finding->evidence['to']);
    }

    public function testShareAtThresholdIsNotReported(): void
    {
        $this->assertSame([], $this->rule(100, 30)->evaluate(1, self::today()));
        $this->assertCount(1, $this->queries, 'examples only read when the rule fires');
    }

    public function testBelowMinimumVolumeFindsNothing(): void
    {
        $this->assertSame([], $this->rule(19, 19)->evaluate(1, self::today()));
    }

    public function testEmptyDataFindsNothing(): void
    {
        $this->assertSame([], $this->rule(0, 0)->evaluate(1, self::today()));
    }

    public function testStoreFilterWindowAndRealRowsOnly(): void
    {
        $this->rule(100, 60)->evaluate(2, self::today());

        $this->assertCount(3, $this->queries);
        $this->assertRealRowsOnly(['panth_aiv_hit']);
        foreach ($this->queries as $query) {
            $this->assertStringContainsString('h.store_id = ?', $query['sql']);
            $this->assertSame(
                ['2026-10-23 00:00:00', '2026-10-30 00:00:00', 'training', 'search', 'live', 'other', 2],
                $query['bind']
            );
        }
    }

    public function testAllStoresHaveNoStoreFilter(): void
    {
        $this->rule(100, 60)->evaluate(0, self::today());
        $this->assertStringNotContainsString('store_id', $this->queries[0]['sql']);
    }
}
