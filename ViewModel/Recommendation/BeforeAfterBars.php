<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\ViewModel\Recommendation;

use Magento\Framework\Escaper;
use Panth\AiVisibility\Api\ImpactCalculatorInterface;

/**
 * Small server-rendered SVG with two horizontal bars: before and after a fix (coverage method: share of
 * similar never-fetched items fetched before vs share of the fixed items fetched after).
 *
 * Every text node and attribute is escaped here, so templates print the markup as it is.
 */
class BeforeAfterBars
{
    public const WIDTH = 240;

    private const LABEL_WIDTH = 52;
    private const VALUE_WIDTH = 70;
    private const BAR_HEIGHT = 14;
    private const ROW_GAP = 8;

    private const COLOR_BEFORE = '#8c8b86';
    private const COLOR_GOOD = '#0a7d0a';
    private const COLOR_BAD = '#b42318';
    private const COLOR_NEUTRAL = '#2a78d6';

    /**
     * @param Escaper $escaper
     * @param Presenter $presenter
     */
    public function __construct(
        private readonly Escaper $escaper,
        private readonly Presenter $presenter
    ) {
    }

    /**
     * SVG markup for an impact row ('' when it has no usable before / after values)
     *
     * @param array $impact Impact row (treat_before, treat_after, verdict)
     * @param string $metric Metric of the action
     * @return string
     */
    public function render(array $impact, string $metric): string
    {
        $coverage = (string)($impact['method'] ?? '') === ImpactCalculatorInterface::METHOD_COVERAGE;
        if ($coverage) {
            // Shares from the stored counts (details.coverage), else from control_after / treat_after
            $shares = $this->presenter->coverageShares($impact);
            [$after, $before] = $shares ?? [null, null];
        } else {
            $before = $this->number($impact['treat_before'] ?? null);
            $after = $this->number($impact['treat_after'] ?? null);
        }
        if ($before === null || $after === null || $before < 0 || $after < 0) {
            return '';
        }
        $tone = $this->presenter->verdictTone((string)($impact['verdict'] ?? ''));
        $afterColor = $tone === 'good' ? self::COLOR_GOOD : ($tone === 'bad' ? self::COLOR_BAD : self::COLOR_NEUTRAL);

        $max = max($before, $after);
        $track = self::WIDTH - self::LABEL_WIDTH - self::VALUE_WIDTH;
        $rows = [
            [$coverage ? (string)__('Others') : (string)__('Before'), $before, self::COLOR_BEFORE],
            [$coverage ? (string)__('Fixed') : (string)__('After'), $after, $afterColor],
        ];
        $height = 2 * self::BAR_HEIGHT + self::ROW_GAP;
        if ($coverage) {
            $beforeText = number_format($before * 100, 1) . '%';
            $afterText = number_format($after * 100, 1) . '%';
            $title = (string)__(
                'Fetched by AI bots: %1 of similar never-fetched items before, %2 of the fixed items after',
                $beforeText,
                $afterText
            );
        } else {
            $beforeText = $this->presenter->formatMetricValue($metric, $before);
            $afterText = $this->presenter->formatMetricValue($metric, $after);
            $title = (string)__(
                '%1: %2 before, %3 after',
                ucfirst($this->presenter->metricLabel($metric)),
                $beforeText,
                $afterText
            );
        }

        $svg = sprintf(
            '<svg class="panth-aiv-bars" xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" '
            . 'viewBox="0 0 %d %d" role="img" aria-label="%s"><title>%s</title>',
            self::WIDTH,
            $height,
            self::WIDTH,
            $height,
            $this->escaper->escapeHtmlAttr($title),
            $this->escaper->escapeHtml($title)
        );
        foreach ($rows as $i => [$label, $value, $color]) {
            $y = $i * (self::BAR_HEIGHT + self::ROW_GAP);
            $width = $max > 0 ? max(1.0, round($value / $max * $track, 1)) : 1.0;
            $text = $i === 0 ? $beforeText : $afterText;
            $svg .= sprintf(
                '<text x="0" y="%d" font-size="11" fill="#41403c">%s</text>'
                . '<rect x="%d" y="%d" width="%s" height="%d" rx="2" fill="%s"/>'
                . '<text x="%s" y="%d" font-size="11" fill="#1f1f1d">%s</text>',
                $y + 11,
                $this->escaper->escapeHtml($label),
                self::LABEL_WIDTH,
                $y,
                $this->coordinate($width),
                self::BAR_HEIGHT,
                $color,
                $this->coordinate(self::LABEL_WIDTH + $width + 4),
                $y + 11,
                $this->escaper->escapeHtml($text)
            );
        }
        return $svg . '</svg>';
    }

    /**
     * Numeric value as float, null otherwise
     *
     * @param mixed $value
     * @return float|null
     */
    private function number($value): ?float
    {
        return is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
    }

    /**
     * Locale-independent SVG coordinate
     *
     * @param float $value
     * @return string
     */
    private function coordinate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
