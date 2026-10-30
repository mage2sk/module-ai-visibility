<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Insights;

use Panth\AiVisibility\Service\Insights\CoverageInsight;
use Panth\AiVisibility\Service\Insights\InsightProvider;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;
use PHPUnit\Framework\TestCase;

class CoverageInsightTest extends TestCase
{
    public function testSilentWithoutCrawlData(): void
    {
        $lookup = $this->createMock(CatalogLookup::class);
        $lookup->expects($this->never())->method('productCoverage');
        $insight = new CoverageInsight($this->repository(0), $lookup);
        $this->assertSame([], $insight->collect($this->range(), 0, false));
    }

    public function testSilentWithoutEligibleProducts(): void
    {
        $insight = new CoverageInsight($this->repository(10), $this->lookup(0, 0));
        $this->assertSame([], $insight->collect($this->range(), 0, false));
    }

    public function testAllFetchedIsOk(): void
    {
        $result = (new CoverageInsight($this->repository(10), $this->lookup(40, 0)))
            ->collect($this->range(), 0, false);
        $this->assertSame(InsightProvider::LEVEL_OK, $result[0]['level']);
        $this->assertStringContainsString('40', $result[0]['text']);
    }

    public function testFewNeverFetchedIsInfo(): void
    {
        $result = (new CoverageInsight($this->repository(10), $this->lookup(200, 50)))
            ->collect($this->range(), 1, false);
        $this->assertSame(InsightProvider::LEVEL_INFO, $result[0]['level']);
        $this->assertStringContainsString('50 of 200', $result[0]['text']);
        $this->assertStringContainsString('25.0%', $result[0]['text']);
    }

    public function testMostNeverFetchedIsWarning(): void
    {
        $result = (new CoverageInsight($this->repository(10), $this->lookup(1000, 990)))
            ->collect($this->range(), 0, false);
        $this->assertSame(InsightProvider::LEVEL_WARNING, $result[0]['level']);
        $this->assertStringContainsString('990 of 1,000', $result[0]['text']);
    }

    public function testLooksAtTheLastNinetyDaysNotTheSelectedRange(): void
    {
        $today = gmdate('Y-m-d');
        $start = (new \DateTimeImmutable($today, new \DateTimeZone('UTC')))->modify('-89 days')->format('Y-m-d');
        $repository = $this->createMock(ReportRepository::class);
        $repository->expects($this->once())->method('crawlTotals')
            ->with($this->callback(static fn (DateRange $r) => $r->dayCount() === 90 && $r->to() === $today))
            ->willReturn(['hits' => 1, 'live' => 0]);
        $lookup = $this->createMock(CatalogLookup::class);
        $lookup->expects($this->once())->method('productCoverage')
            ->with(3, $start, true)
            ->willReturn(['eligible' => 1, 'never' => 0]);
        (new CoverageInsight($repository, $lookup))->collect($this->range(), 3, true);
    }

    /**
     * Repository stub with a 90-day hit total
     *
     * @param int $hits
     * @return ReportRepository
     */
    private function repository(int $hits): ReportRepository
    {
        $repository = $this->createStub(ReportRepository::class);
        $repository->method('crawlTotals')->willReturn(['hits' => $hits, 'live' => 0]);
        return $repository;
    }

    /**
     * Catalog lookup stub
     *
     * @param int $eligible
     * @param int $never
     * @return CatalogLookup
     */
    private function lookup(int $eligible, int $never): CatalogLookup
    {
        $lookup = $this->createStub(CatalogLookup::class);
        $lookup->method('productCoverage')->willReturn(['eligible' => $eligible, 'never' => $never]);
        return $lookup;
    }

    /**
     * Fixed selected range
     *
     * @return DateRange
     */
    private function range(): DateRange
    {
        return new DateRange('2026-10-24', '2026-10-30', '7');
    }
}
