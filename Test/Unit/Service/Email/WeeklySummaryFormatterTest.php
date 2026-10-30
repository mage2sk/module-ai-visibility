<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Email;

use Magento\Framework\Escaper;
use Panth\AiVisibility\Service\Email\WeeklySummaryFormatter;
use PHPUnit\Framework\TestCase;

class WeeklySummaryFormatterTest extends TestCase
{
    private const XSS_SCRIPT = '<script>alert(1)</script>';
    private const XSS_ATTR = '"><img src=x onerror=alert(2)>';
    private const XSS_SVG = '<svg onload=alert(3)>';

    /**
     * @dataProvider changes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('changes')]
    public function testChange($current, $previous, string $expected): void
    {
        $this->assertSame($expected, WeeklySummaryFormatter::change($current, $previous));
    }

    /**
     * @return array<string, array{int|float, int|float, string}>
     */
    public static function changes(): array
    {
        return [
            'up' => [112, 100, '+12%'],
            'down' => [95, 100, '-5%'],
            'flat' => [100, 100, '0%'],
            'from zero' => [5, 0, 'new'],
            'both zero' => [0, 0, 'no change'],
            'to zero' => [0, 40, '-100%'],
            'money' => [150.5, 100.25, '+50%'],
        ];
    }

    public function testVerdictTextAlwaysShowsTheRange(): void
    {
        $formatter = $this->formatter();

        $this->assertSame(
            'likely helped: +23% (range +10% to +38%)',
            $formatter->verdictText(['verdict' => 'helped', 'effect' => 1.23, 'ci_low' => 1.10, 'ci_high' => 1.38])
        );
        $this->assertSame(
            'likely made it worse: -30% (range -45% to -12%)',
            $formatter->verdictText(['verdict' => 'worse', 'effect' => 0.70, 'ci_low' => 0.55, 'ci_high' => 0.88])
        );
        $this->assertSame(
            'no clear effect: +2% (range -8% to +12%)',
            $formatter->verdictText(['verdict' => 'no_effect', 'effect' => 1.02, 'ci_low' => 0.92, 'ci_high' => 1.12])
        );
        $this->assertSame('not enough data yet', $formatter->verdictText(['verdict' => 'insufficient', 'effect' => null]));
    }

    public function testHostileStoredValuesAreEscapedInEveryFragment(): void
    {
        $vars = $this->formatter()->templateVars(
            $this->hostileSummary(),
            'Shop ' . self::XSS_SCRIPT,
            'https://shop.test/admin/panth_aivisibility/dashboard/index/?a="b"'
        );

        foreach ($vars as $name => $html) {
            if (!str_ends_with($name, '_html')) {
                continue;
            }
            $this->assertStringNotContainsString('<script', $html, $name);
            $this->assertStringNotContainsString('<img', $html, $name);
            $this->assertStringNotContainsString('<svg', $html, $name);
            $this->assertStringNotContainsString('<b>', $html, $name);
        }
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $vars['pages_html']);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(2)&gt;', $vars['pages_html']);
        $this->assertStringContainsString('&lt;svg onload=alert(3)&gt;', $vars['candidates_html']);
        $this->assertStringContainsString('&lt;b&gt;Assistant&lt;/b&gt;', $vars['sources_html']);
        $this->assertStringContainsString('&lt;script&gt;', $vars['recommendations_html']);
        $this->assertStringContainsString('&lt;script&gt;', $vars['verdicts_html']);
        $this->assertStringContainsString('href="https://shop.test/admin/panth_aivisibility/dashboard/index/?a=&quot;b&quot;"', $vars['dashboard_link_html']);
        // site_name / period_label / subject are plain text: the template prints them with {{var}} (escaped).
        $this->assertSame('Shop ' . self::XSS_SCRIPT, $vars['site_name']);
    }

    public function testSubjectHasNoLineBreaks(): void
    {
        $subject = $this->formatter()->subject(['from' => '2026-10-19', 'to' => '2026-10-25'], "Shop\r\nBcc: x@example.invalid");

        $this->assertStringNotContainsString("\n", $subject);
        $this->assertStringNotContainsString("\r", $subject);
        $this->assertSame('AI Visibility weekly summary for Shop Bcc: x@example.invalid: 2026-10-19 to 2026-10-25', $subject);
    }

    /**
     * @dataProvider unsafeUrls
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeUrls')]
    public function testDashboardLinkOnlyForHttpUrls(string $url): void
    {
        $vars = $this->formatter()->templateVars($this->emptySummary(), 'Shop', $url);

        $this->assertSame('', $vars['dashboard_link_html']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'empty' => [''],
            'data' => ['data:text/html,<b>x</b>'],
            'relative' => ['/admin/'],
        ];
    }

    public function testEmptyWeekShowsNoneLinesAndZeroNumbers(): void
    {
        $formatter = $this->formatter();
        $vars = $formatter->templateVars($this->emptySummary(), 'Shop', '');
        $text = $formatter->text($this->emptySummary(), 'Shop');

        $this->assertStringContainsString('No AI bot reads this week.', $vars['pages_html']);
        $this->assertStringContainsString('No open recommendations.', $vars['recommendations_html']);
        $this->assertStringContainsString('No verdicts this week.', $vars['verdicts_html']);
        $this->assertStringContainsString('no change', $vars['metrics_html']);
        $this->assertStringContainsString('New discovery candidates: 0', $text);
        $this->assertStringContainsString('2026-10-19 to 2026-10-25 (UTC), compared with 2026-10-12 to 2026-10-18', $text);
    }

    public function testTextSummaryListsTheNumbers(): void
    {
        $summary = $this->emptySummary();
        $summary['metrics']['crawler_hits'] = ['current' => 1250, 'previous' => 1000];
        $summary['metrics']['ai_revenue'] = ['current' => 99.5, 'previous' => 0.0];
        $summary['money'] = ['current' => '$99.50', 'previous' => '$0.00'];
        $summary['top_pages'] = [['label' => 'Blue Shirt', 'path' => "/blue\nshirt.html", 'type' => 'Product', 'hits' => 40]];

        $text = $this->formatter()->text($summary, 'Shop');

        $this->assertMatchesRegularExpression('/AI crawler hits:\s+1,250\s+\+25%/', $text);
        $this->assertMatchesRegularExpression('/AI revenue:\s+\$99\.50\s+new/', $text);
        $this->assertStringContainsString('1. Blue Shirt (/blue shirt.html) - 40', $text);
    }

    public function testMixedCurrencyNoteOnlyWhenFlagged(): void
    {
        $formatter = $this->formatter();
        $summary = $this->emptySummary();
        $this->assertStringNotContainsString('different base currencies', $formatter->templateVars($summary, 'Shop', '')['metrics_html']);
        $this->assertStringNotContainsString('different base currencies', $formatter->text($summary, 'Shop'));

        $summary['mixed_currencies'] = true;
        $this->assertStringContainsString('different base currencies', $formatter->templateVars($summary, 'Shop', '')['metrics_html']);
        $this->assertStringContainsString('different base currencies', $formatter->text($summary, 'Shop'));
    }

    /**
     * @return WeeklySummaryFormatter
     */
    private function formatter(): WeeklySummaryFormatter
    {
        return new WeeklySummaryFormatter(new Escaper());
    }

    /**
     * @return array
     */
    private function emptySummary(): array
    {
        return [
            'from' => '2026-10-19',
            'to' => '2026-10-25',
            'prev_from' => '2026-10-12',
            'prev_to' => '2026-10-18',
            'metrics' => [],
            'money' => ['current' => '0.00', 'previous' => '0.00'],
            'top_pages' => [],
            'top_sources' => [],
            'recommendations' => [],
            'verdicts' => [],
            'candidates' => ['count' => 0, 'items' => []],
        ];
    }

    /**
     * Summary whose stored values are hostile
     *
     * @return array
     */
    private function hostileSummary(): array
    {
        $summary = $this->emptySummary();
        $summary['money'] = ['current' => self::XSS_SCRIPT, 'previous' => '0'];
        $summary['top_pages'] = [
            ['label' => self::XSS_SCRIPT, 'path' => '/p' . self::XSS_ATTR, 'type' => 'Page', 'hits' => 3],
        ];
        $summary['top_sources'] = [['name' => '<b>Assistant</b>', 'visits' => 2]];
        $summary['recommendations'] = [
            ['title' => 'Fix ' . self::XSS_SCRIPT, 'rule_code' => 'bot_404', 'impact_score' => 80, 'affected_count' => 4],
        ];
        $summary['verdicts'] = [[
            'title' => self::XSS_SCRIPT . self::XSS_ATTR,
            'verdict' => self::XSS_ATTR,
            'effect' => 1.5,
            'ci_low' => 1.2,
            'ci_high' => 1.9,
            'metric' => 'bot_hits',
        ]];
        $summary['candidates'] = ['count' => 1, 'items' => [
            ['type' => 'bot', 'key' => 'Mozilla/5.0 ' . self::XSS_SVG, 'hits' => 9],
        ]];
        return $summary;
    }
}
