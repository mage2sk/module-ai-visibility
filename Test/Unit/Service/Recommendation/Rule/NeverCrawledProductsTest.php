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
use Panth\AiVisibility\Service\Recommendation\Rule\NeverCrawledProducts;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use PHPUnit\Framework\TestCase;

class NeverCrawledProductsTest extends TestCase
{
    /**
     * @dataProvider dataStarts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('dataStarts')]
    public function testNeedsFourteenDaysOfCrawlData(?string $start, int $expected): void
    {
        $facts = $this->createMock(CrawlFacts::class);
        $facts->method('crawlDataStart')->willReturn($start);
        $facts->expects($expected > 0 ? $this->once() : $this->never())->method('productCoverage')
            ->willReturn(['eligible' => 100, 'never' => 40, 'ids' => [1, 2]]);

        $this->assertCount($expected, $this->rule($facts, 20)->evaluate(1, $this->today()));
    }

    public static function dataStarts(): array
    {
        return [
            'no data' => [null, 0],
            'thirteen days' => ['2026-02-25', 0],
            'fourteen days' => ['2026-02-24', 1],
            'months' => ['2025-10-01', 1],
        ];
    }

    /**
     * @dataProvider volumes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('volumes')]
    public function testMinVolumeAndEmptyCatalog(int $eligible, int $never, int $minVolume, int $expected): void
    {
        $facts = $this->createStub(CrawlFacts::class);
        $facts->method('crawlDataStart')->willReturn('2025-10-01');
        $facts->method('productCoverage')->willReturn(['eligible' => $eligible, 'never' => $never, 'ids' => [5]]);

        $this->assertCount($expected, $this->rule($facts, $minVolume)->evaluate(1, $this->today()));
    }

    public static function volumes(): array
    {
        return [
            'empty catalog' => [0, 0, 1, 0],
            'all crawled' => [50, 0, 1, 0],
            'below' => [50, 19, 20, 0],
            'at' => [50, 20, 20, 1],
        ];
    }

    public function testFindingEvidenceAndQueryArguments(): void
    {
        $facts = $this->createMock(CrawlFacts::class);
        $facts->expects($this->once())->method('crawlDataStart')->with(2)->willReturn('2025-11-01');
        $facts->expects($this->once())->method('productCoverage')
            ->with(2, '2025-12-10', '2026-02-24 00:00:00', NeverCrawledProducts::SUBJECT_LIMIT)
            ->willReturn(['eligible' => 400, 'never' => 100, 'ids' => [7, 9]]);
        $lookup = $this->createMock(CatalogLookup::class);
        $lookup->expects($this->once())->method('entityNames')
            ->with([['entity_type' => 'product', 'entity_id' => 7], ['entity_type' => 'product', 'entity_id' => 9]], 2)
            ->willReturn(['product:7' => 'Trail Jacket']);

        $finding = $this->rule($facts, 20, $lookup)->evaluate(2, $this->today())[0];

        $this->assertSame(NeverCrawledProducts::CODE, $finding->ruleCode);
        $this->assertSame(2, $finding->storeId);
        $this->assertSame(Finding::SUBJECT_PRODUCT, $finding->subjectType);
        $this->assertSame(MetricProviderInterface::METRIC_BOT_HITS, $finding->metric);
        $this->assertSame(['7', '9'], $finding->subjects);
        $this->assertSame(100, $finding->affectedCount);
        $this->assertSame('100 of 400 in-stock products (25.0%) were never fetched by an AI bot in 90 days', $finding->title);
        $this->assertSame(
            [['product_id' => 7, 'name' => 'Trail Jacket'], ['product_id' => 9, 'name' => '#9']],
            $finding->evidence['examples']
        );
        $this->assertSame(25.0, $finding->evidence['share_pct']);
        $this->assertSame('2025-11-01', $finding->evidence['crawl_data_since']);
    }

    /**
     * Upgrade from 1.0 / young install: per-product history shorter than 90 days -> the window starts on
     * its first day and the title names the real number of days.
     */
    public function testShortHistoryNarrowsTheWindowAndTheTitle(): void
    {
        $facts = $this->createMock(CrawlFacts::class);
        $facts->method('crawlDataStart')->willReturn('2026-02-14');
        $facts->expects($this->once())->method('productCoverage')
            ->with(1, '2026-02-14', '2026-02-24 00:00:00', NeverCrawledProducts::SUBJECT_LIMIT)
            ->willReturn(['eligible' => 400, 'never' => 100, 'ids' => [7]]);

        $finding = $this->rule($facts, 20)->evaluate(1, $this->today())[0];

        $this->assertSame('100 of 400 in-stock products (25.0%) were never fetched by an AI bot in 24 days', $finding->title);
        $this->assertSame(['from' => '2026-02-14', 'to' => '2026-03-09'], $finding->evidence['window']);
        $this->assertSame(24, $finding->evidence['window_days']);
    }

    /**
     * Rule under test
     *
     * @param CrawlFacts $facts
     * @param int $minVolume
     * @param CatalogLookup|null $lookup
     * @return NeverCrawledProducts
     */
    private function rule(CrawlFacts $facts, int $minVolume, ?CatalogLookup $lookup = null): NeverCrawledProducts
    {
        $config = $this->createStub(Config::class);
        $config->method('recommendationsMinVolume')->willReturn($minVolume);
        if ($lookup === null) {
            $lookup = $this->createStub(CatalogLookup::class);
            $lookup->method('entityNames')->willReturn([]);
        }
        return new NeverCrawledProducts($facts, $lookup, $config);
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
