<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\ViewModel\Recommendation;

use Panth\AiVisibility\Test\Unit\Block\Adminhtml\RecommendationFixtureTrait;
use PHPUnit\Framework\TestCase;

class BeforeAfterBarsTest extends TestCase
{
    use RecommendationFixtureTrait;

    public function testRendersTwoScaledBarsWithEscapedTitle(): void
    {
        $svg = $this->bars()->render(
            ['treat_before' => '100.0000', 'treat_after' => '150.0000', 'verdict' => 'helped'],
            'bot_hits'
        );

        $this->assertStringStartsWith('<svg class="panth-aiv-bars"', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
        $this->assertSame(2, substr_count($svg, '<rect '));
        $this->assertStringContainsString('<title>AI crawler hits: 100 before, 150 after</title>', $svg);
        // after = full track (240 - 52 - 70 = 118), before = 2/3 of it
        $this->assertStringContainsString('width="118"', $svg);
        $this->assertStringContainsString('width="78.7"', $svg);
        $this->assertStringContainsString('fill="#0a7d0a"', $svg);
    }

    public function testWorseIsRedAndRatioMetricsArePercent(): void
    {
        $svg = $this->bars()->render(
            ['treat_before' => 0.05, 'treat_after' => 0.025, 'verdict' => 'worse'],
            'ai_conversion'
        );
        $this->assertStringContainsString('fill="#b42318"', $svg);
        $this->assertStringContainsString('>5.0%<', $svg);
        $this->assertStringContainsString('>2.5%<', $svg);
    }

    public function testCoverageComparesTheBaselineShareWithTheFixedShare(): void
    {
        $svg = $this->bars()->render(
            ['method' => 'coverage', 'treat_before' => 0, 'treat_after' => 0.3, 'control_after' => 0.05,
                'verdict' => 'helped'],
            'bot_hits'
        );

        $this->assertStringContainsString('>Others<', $svg);
        $this->assertStringContainsString('>Fixed<', $svg);
        $this->assertStringContainsString('>5.0%<', $svg);
        $this->assertStringContainsString('>30.0%<', $svg);
        $this->assertStringContainsString('of similar never-fetched items before', $svg);
    }

    public function testZeroValuesStillRenderMinimalBars(): void
    {
        $svg = $this->bars()->render(['treat_before' => 0, 'treat_after' => 0, 'verdict' => 'no_effect'], 'bot_404');
        $this->assertSame(2, substr_count($svg, 'width="1"'));
    }

    public function testMissingOrInvalidValuesRenderNothing(): void
    {
        $bars = $this->bars();
        $this->assertSame('', $bars->render([], 'bot_hits'));
        $this->assertSame('', $bars->render(['treat_before' => 'abc', 'treat_after' => 1], 'bot_hits'));
        $this->assertSame('', $bars->render(['treat_before' => -1, 'treat_after' => 1], 'bot_hits'));
    }

    public function testHostileMetricNameIsEscaped(): void
    {
        $svg = $this->bars()->render(['treat_before' => 1, 'treat_after' => 2, 'verdict' => 'helped'], '<script>m</script>');
        $this->assertStringNotContainsString('<script', $svg);
    }

    /**
     * Coverage rows get bars from the stored counts even when control_after is empty
     */
    public function testCoverageBarsComeFromTheStoredCounts(): void
    {
        $svg = $this->bars()->render(
            ['method' => 'coverage', 'treat_after' => null, 'control_after' => null, 'verdict' => 'helped',
                'details' => ['coverage' => ['treated_fetched' => 12, 'treated' => 20, 'baseline_fetched' => 30,
                    'baseline' => 200]]],
            'bot_hits'
        );
        $this->assertSame(2, substr_count($svg, '<rect '));
        $this->assertStringContainsString('>15.0%<', $svg);
        $this->assertStringContainsString('>60.0%<', $svg);
        $this->assertSame('', $this->bars()->render(['method' => 'coverage', 'verdict' => 'insufficient'], 'bot_hits'));
    }
}
