<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Chart;

use Panth\AiVisibility\Service\Chart\SvgChart;
use PHPUnit\Framework\TestCase;

class SvgChartTest extends TestCase
{
    /**
     * @var SvgChart
     */
    private SvgChart $chart;

    protected function setUp(): void
    {
        $this->chart = new SvgChart();
    }

    public function testOutputIsWellFormedAccessibleSvg(): void
    {
        $svg = $this->chart->bars(
            ['2026-10-01', '2026-10-02'],
            [['name' => 'OpenAI', 'color' => '#2a78d6', 'values' => [3, 5]]],
            'Daily hits',
            'Bars per day'
        );
        $doc = $this->load($svg);
        $root = $doc->documentElement;
        $this->assertSame('svg', $root->nodeName);
        $this->assertSame('img', $root->getAttribute('role'));
        $ids = explode(' ', $root->getAttribute('aria-labelledby'));
        $this->assertCount(2, $ids);
        $this->assertSame('Daily hits', $doc->getElementById($ids[0])?->textContent ?? $this->titleText($doc));
        $this->assertSame(2, $doc->getElementsByTagName('rect')->length);
        $this->assertStringContainsString('<title>2026-10-02 - OpenAI: 5</title>', $svg);
    }

    public function testLabelsTitlesAndSeriesNamesAreEscaped(): void
    {
        $evil = '<script>alert("x")</script>&\'';
        $svg = $this->chart->bars(
            [$evil],
            [['name' => $evil, 'color' => '"><script>', 'values' => [1]]],
            $evil,
            $evil,
            [$evil]
        );
        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringNotContainsString('fill="&quot;', $svg);
        $this->assertStringContainsString('&lt;script&gt;', $svg);
        $this->load($svg);
        // An invalid colour is replaced by a palette colour.
        $this->assertStringContainsString('fill="' . SvgChart::PALETTE[0] . '"', $svg);
    }

    public function testZeroDataRendersAxisAndMessage(): void
    {
        $svg = $this->chart->bars(['a', 'b', 'c'], [['name' => 'S', 'values' => [0, 0, 0]]], 'Empty');
        $doc = $this->load($svg);
        $this->assertSame(0, $doc->getElementsByTagName('rect')->length);
        $this->assertStringContainsString('No data for this period', $svg);
        $this->assertStringNotContainsString('NAN', strtoupper($svg));
        $this->assertStringNotContainsString('INF', strtoupper($svg));
    }

    public function testNoLabelsAndNoSeries(): void
    {
        $svg = $this->chart->bars([], [], 'Nothing');
        $this->load($svg);
        $this->assertStringContainsString('No data for this period', $svg);
    }

    public function testSinglePointBarIsCappedInWidthAndCentered(): void
    {
        $svg = $this->chart->bars(['2026-10-01'], [['name' => 'S', 'values' => [7]]], 'One');
        $rect = $this->load($svg)->getElementsByTagName('rect')->item(0);
        $this->assertNotNull($rect);
        $width = (float)$rect->getAttribute('width');
        $this->assertLessThanOrEqual(48.0, $width);
        $x = (float)$rect->getAttribute('x');
        $this->assertEqualsWithDelta(56 + (720 - 56 - 12) / 2, $x + $width / 2, 0.01);
    }

    public function testLargeNumbersUseCompactAxisLabelsAndStayInsideTheChart(): void
    {
        $svg = $this->chart->bars(
            ['a', 'b'],
            [['name' => 'Big', 'values' => [9876543210, 1234567890123]]],
            'Large'
        );
        $doc = $this->load($svg);
        $this->assertMatchesRegularExpression('/>\d+(\.\d)?T</', $svg);
        $this->assertStringContainsString('1,234,567,890,123', $svg);
        foreach ($doc->getElementsByTagName('rect') as $rect) {
            $y = (float)$rect->getAttribute('y');
            $this->assertGreaterThanOrEqual(12.0 - 0.01, $y);
            $this->assertLessThanOrEqual(210.01, $y + (float)$rect->getAttribute('height'));
        }
    }

    public function testNegativeAndNonNumericValuesAreTreatedAsZero(): void
    {
        $svg = $this->chart->bars(
            ['a', 'b', 'c', 'd'],
            [['name' => 'S', 'values' => [-5, 'abc', INF, 4]]],
            'Mixed'
        );
        $doc = $this->load($svg);
        $this->assertSame(1, $doc->getElementsByTagName('rect')->length);
    }

    public function testStackedSeriesShowTheDayTotal(): void
    {
        $svg = $this->chart->bars(
            ['d1'],
            [
                ['name' => 'OpenAI', 'color' => '#2a78d6', 'values' => [6]],
                ['name' => 'Anthropic', 'color' => '#eb6834', 'values' => [4]],
            ],
            'Stacked'
        );
        $this->assertSame(2, $this->load($svg)->getElementsByTagName('rect')->length);
        $this->assertStringContainsString('d1 - Anthropic: 4 (10 in total)', $svg);
    }

    public function testAtMostEightXAxisLabels(): void
    {
        $labels = [];
        for ($i = 1; $i <= 400; $i++) {
            $labels[] = 'L' . $i;
        }
        $svg = $this->chart->bars($labels, [['name' => 'S', 'values' => array_fill(0, 400, 1)]], 'Many');
        preg_match_all('/>L\d+</', $svg, $matches);
        $this->assertLessThanOrEqual(8, count($matches[0]));
        $this->assertGreaterThan(0, count($matches[0]));
    }

    public function testCustomWidthIsClamped(): void
    {
        $narrow = $this->chart->bars(['x'], [['name' => 'S', 'values' => [1]]], 'N', '', [], 460);
        $this->assertStringContainsString('viewBox="0 0 460 240"', $narrow);
        $tiny = $this->chart->bars(['x'], [['name' => 'S', 'values' => [1]]], 'T', '', [], 10);
        $this->assertStringContainsString('viewBox="0 0 240 240"', $tiny);
        $this->load($tiny);
    }

    public function testIdsAreUniquePerChart(): void
    {
        $a = $this->chart->bars(['x'], [['name' => 'S', 'values' => [1]]], 'A');
        $b = $this->chart->bars(['x'], [['name' => 'S', 'values' => [1]]], 'B');
        preg_match('/aria-labelledby="([^" ]+)/', $a, $ma);
        preg_match('/aria-labelledby="([^" ]+)/', $b, $mb);
        $this->assertNotSame($ma[1], $mb[1]);
    }

    /**
     * @dataProvider scaleProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scaleProvider')]
    public function testScale(float $max, float $top, float $step): void
    {
        $this->assertSame([$top, $step], $this->chart->scale($max));
    }

    public static function scaleProvider(): array
    {
        return [
            'zero' => [0.0, 4.0, 1.0],
            'negative' => [-3.0, 4.0, 1.0],
            'one' => [1.0, 1.0, 1.0],
            'three' => [3.0, 3.0, 1.0],
            'seven' => [7.0, 8.0, 2.0],
            'hundred' => [100.0, 100.0, 25.0],
            'odd' => [1234.0, 1500.0, 500.0],
            'huge' => [9.0e12, 1.0e13, 2.5e12],
        ];
    }

    /**
     * @dataProvider compactProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('compactProvider')]
    public function testCompact(float $value, string $expected): void
    {
        $this->assertSame($expected, $this->chart->compact($value));
    }

    public static function compactProvider(): array
    {
        return [
            [0.0, '0'],
            [950.0, '950'],
            [0.5, '0.5'],
            [1000.0, '1K'],
            [1500.0, '1.5K'],
            [250000.0, '250K'],
            [2500000.0, '2.5M'],
            [3.0e9, '3B'],
            [1.25e12, '1.3T'],
        ];
    }

    /**
     * Parse the SVG as XML (fails the test on malformed markup)
     *
     * @param string $svg
     * @return \DOMDocument
     */
    private function load(string $svg): \DOMDocument
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($svg);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertTrue($ok, 'SVG is not well-formed XML');
        $this->assertSame([], $errors);
        return $doc;
    }

    /**
     * Text of the first <title> element
     *
     * @param \DOMDocument $doc
     * @return string
     */
    private function titleText(\DOMDocument $doc): string
    {
        return (string)$doc->getElementsByTagName('title')->item(0)?->textContent;
    }
}
