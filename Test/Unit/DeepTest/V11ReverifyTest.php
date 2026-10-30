<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Framework\AuthorizationInterface;
use Panth\AiVisibility\Console\Command\VerifyCommand;
use Panth\AiVisibility\Controller\Adminhtml\Dashboard\Export;
use Panth\AiVisibility\Service\Export\ReportExporter;
use Panth\AiVisibility\Service\Rollup;
use Panth\AiVisibility\Setup\Patch\Data\RerollRawRetentionDays;
use PHPUnit\Framework\TestCase;

/**
 * Independent re-verification checks of the 1.1 fix round (AivTest-RV11)
 */
class V11ReverifyTest extends TestCase
{
    public function testRecommendationAndVerdictExportsNeedTheRecommendationsResource(): void
    {
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => $resource !== 'Panth_AiVisibility::recommendations'
        );
        $this->assertFalse(Export::isReportAllowed(ReportExporter::REPORT_RECOMMENDATIONS, $auth));
        $this->assertFalse(Export::isReportAllowed(ReportExporter::REPORT_VERDICTS, $auth));
        foreach (array_diff(ReportExporter::REPORTS, [ReportExporter::REPORT_RECOMMENDATIONS, ReportExporter::REPORT_VERDICTS]) as $report) {
            $this->assertTrue(Export::isReportAllowed($report, $auth), $report);
        }
    }

    public function testRerollDaysAreCappedAtSixtyAndEndToday(): void
    {
        $now = (int)strtotime('2026-10-30 13:00:00 UTC');
        $days = RerollRawRetentionDays::days(730, $now);
        $this->assertCount(60, $days);
        $this->assertSame('2026-10-30', end($days));
        $this->assertSame('2026-09-01', $days[0]);
        $this->assertCount(30, RerollRawRetentionDays::days(30, $now));
        $this->assertSame([], RerollRawRetentionDays::days(0, $now));
    }

    public function testExtraPathBoundAndRetentionWarning(): void
    {
        $this->assertFalse(Rollup::keepBeyondCap(['s4xx' => 2, 's5xx' => 0, 'total_ms' => 0, 'timed_hits' => 0]));
        $this->assertTrue(Rollup::keepBeyondCap(['s4xx' => 2, 's5xx' => 1, 'total_ms' => 0, 'timed_hits' => 0]));
        $this->assertNull(VerifyCommand::retentionWarning(14, 13));
        $this->assertNotNull(VerifyCommand::retentionWarning(56, 1));
    }
}
