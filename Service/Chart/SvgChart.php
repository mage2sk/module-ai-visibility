<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Chart;

/**
 * Server-rendered inline SVG bar charts (stacked or single series). No JavaScript.
 *
 * Every text node and attribute value is escaped here, so the returned markup can be printed
 * as is. The chart carries a <title> and <desc> (role="img"), and every bar segment has its
 * own <title> so the browser shows the exact value on hover. The y axis uses "nice" steps,
 * starts at zero and copes with all-zero data, a single day and very large numbers.
 */
class SvgChart
{
    /**
     * Categorical colours in fixed order (validated for colour-vision deficiencies on white)
     */
    public const PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    public const OTHER_COLOR = '#8c8b86';

    public const WIDTH = 720;
    private const MIN_WIDTH = 240;
    private const MAX_WIDTH = 1600;
    private const HEIGHT = 240;
    private const MARGIN_LEFT = 56;
    private const MARGIN_RIGHT = 12;
    private const MARGIN_TOP = 12;
    private const MARGIN_BOTTOM = 30;
    private const MAX_X_LABELS = 8;
    private const MAX_BAR_WIDTH = 48;
    private const GRID_COLOR = '#e6e5e1';
    private const AXIS_COLOR = '#b9b8b2';
    private const TEXT_COLOR = '#52514e';

    /**
     * @var int
     */
    private int $counter = 0;

    /**
     * Render a bar chart; with several series the bars are stacked in the given order
     *
     * @param string[] $labels One label per bar (used in the hover titles)
     * @param array<int, array{name: string, color?: string, values: array}> $series
     * @param string $title Accessible chart title
     * @param string $description Accessible description (summary of what is shown)
     * @param string[] $axisLabels Optional shorter labels for the x axis (same order as $labels)
     * @param int $width Width of the drawing in SVG units (smaller = larger text when scaled to a half-width box)
     * @return string SVG markup
     */
    public function bars(
        array $labels,
        array $series,
        string $title,
        string $description = '',
        array $axisLabels = [],
        int $width = self::WIDTH
    ): string {
        $width = max(self::MIN_WIDTH, min(self::MAX_WIDTH, $width));
        $labels = array_values(array_map('strval', $labels));
        $axisLabels = array_values(array_map('strval', $axisLabels));
        $count = count($labels);
        $series = $this->normalizeSeries($series, $count);
        $id = 'panth-aiv-chart-' . (++$this->counter);

        $totals = array_fill(0, $count, 0.0);
        foreach ($series as $item) {
            foreach ($item['values'] as $i => $value) {
                $totals[$i] += $value;
            }
        }
        $max = $totals === [] ? 0.0 : max($totals);
        [$top, $step] = $this->scale($max);

        $plotWidth = $width - self::MARGIN_LEFT - self::MARGIN_RIGHT;
        $plotHeight = self::HEIGHT - self::MARGIN_TOP - self::MARGIN_BOTTOM;
        $baseline = self::MARGIN_TOP + $plotHeight;

        $out = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" class="panth-aiv-chart" viewBox="0 0 %d %d" role="img"'
            . ' aria-labelledby="%s-title %s-desc" focusable="false">',
            $width,
            self::HEIGHT,
            $id,
            $id
        );
        $out .= sprintf('<title id="%s-title">%s</title>', $id, $this->esc($title));
        $out .= sprintf('<desc id="%s-desc">%s</desc>', $id, $this->esc($description));

        // Horizontal grid lines with y-axis labels.
        $out .= '<g class="panth-aiv-chart__grid" aria-hidden="true">';
        $ticks = (int)round($top / $step);
        for ($t = 0; $t <= $ticks; $t++) {
            $value = $step * $t;
            $y = $baseline - ($top > 0 ? $value / $top : 0) * $plotHeight;
            $out .= sprintf(
                '<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="1"/>',
                $this->num(self::MARGIN_LEFT),
                $this->num($y),
                $this->num($width - self::MARGIN_RIGHT),
                $this->num($y),
                $t === 0 ? self::AXIS_COLOR : self::GRID_COLOR
            );
            $out .= sprintf(
                '<text x="%s" y="%s" text-anchor="end" font-size="11" fill="%s">%s</text>',
                $this->num(self::MARGIN_LEFT - 6),
                $this->num($y + 4),
                self::TEXT_COLOR,
                $this->esc($this->compact($value))
            );
        }
        $out .= '</g>';

        // Bars.
        if ($count > 0) {
            $slot = $plotWidth / $count;
            $barWidth = max(1.0, min(self::MAX_BAR_WIDTH, $slot * 0.72));
            $gap = $barWidth >= 6 ? 1.0 : 0.0;
            $multi = count($series) > 1;
            $out .= '<g class="panth-aiv-chart__bars">';
            for ($i = 0; $i < $count; $i++) {
                $x = self::MARGIN_LEFT + $i * $slot + ($slot - $barWidth) / 2;
                $y = $baseline;
                foreach ($series as $item) {
                    $value = $item['values'][$i];
                    if ($value <= 0 || $top <= 0) {
                        continue;
                    }
                    $height = $value / $top * $plotHeight;
                    $y -= $height;
                    $label = $labels[$i] . ' - ' . $item['name'] . ': ' . $this->full($value);
                    if ($multi) {
                        $label .= ' (' . $this->full($totals[$i]) . ' in total)';
                    }
                    $out .= sprintf(
                        '<rect x="%s" y="%s" width="%s" height="%s" fill="%s"><title>%s</title></rect>',
                        $this->num($x),
                        $this->num($y),
                        $this->num($barWidth),
                        $this->num(max(0.5, $height - ($height > 2 * $gap ? $gap : 0))),
                        $this->esc($item['color']),
                        $this->esc($label)
                    );
                }
            }
            $out .= '</g>';

            // X-axis labels: at most MAX_X_LABELS, evenly spaced.
            $every = (int)max(1, ceil($count / self::MAX_X_LABELS));
            $out .= '<g class="panth-aiv-chart__x" aria-hidden="true">';
            for ($i = 0; $i < $count; $i += $every) {
                $out .= sprintf(
                    '<text x="%s" y="%s" text-anchor="middle" font-size="11" fill="%s">%s</text>',
                    $this->num(self::MARGIN_LEFT + ($i + 0.5) * $slot),
                    $this->num($baseline + 18),
                    self::TEXT_COLOR,
                    $this->esc($axisLabels[$i] ?? $labels[$i])
                );
            }
            $out .= '</g>';
        }

        if ($max <= 0) {
            $out .= sprintf(
                '<text x="%s" y="%s" text-anchor="middle" font-size="13" fill="%s">%s</text>',
                $this->num(self::MARGIN_LEFT + $plotWidth / 2),
                $this->num(self::MARGIN_TOP + $plotHeight / 2),
                self::TEXT_COLOR,
                $this->esc((string)__('No data for this period'))
            );
        }
        return $out . '</svg>';
    }

    /**
     * Upper bound and step of the y axis: "nice" whole-number steps (1, 2, 2.5, 5 x 10^n)
     *
     * @param float $max Largest bar total
     * @param int $ticks Wanted number of steps
     * @return array{0: float, 1: float} [top, step]
     */
    public function scale(float $max, int $ticks = 4): array
    {
        if (!is_finite($max) || $max <= 0) {
            return [(float)$ticks, 1.0];
        }
        $raw = $max / max(1, $ticks);
        $magnitude = 10 ** floor(log10($raw));
        $normalized = $raw / $magnitude;
        if ($normalized <= 1) {
            $nice = 1;
        } elseif ($normalized <= 2) {
            $nice = 2;
        } elseif ($normalized <= 2.5) {
            $nice = 2.5;
        } elseif ($normalized <= 5) {
            $nice = 5;
        } else {
            $nice = 10;
        }
        $step = max(1.0, $nice * $magnitude);
        return [ceil($max / $step) * $step, $step];
    }

    /**
     * Short number for axis labels: 950, 1.2K, 3.4M, 5B
     *
     * @param float $value
     * @return string
     */
    public function compact(float $value): string
    {
        $abs = abs($value);
        foreach ([1000000000000 => 'T', 1000000000 => 'B', 1000000 => 'M', 1000 => 'K'] as $size => $suffix) {
            if ($abs >= $size) {
                $short = $value / $size;
                $text = number_format($short, $short >= 100 || floor($short) == $short ? 0 : 1, '.', '');
                return $text . $suffix;
            }
        }
        return number_format($value, floor($value) == $value ? 0 : 1, '.', ',');
    }

    /**
     * Cleaned series: valid colour, exactly $count non-negative finite values
     *
     * @param array $series
     * @param int $count
     * @return array<int, array{name: string, color: string, values: float[]}>
     */
    private function normalizeSeries(array $series, int $count): array
    {
        $result = [];
        foreach (array_values($series) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $color = (string)($item['color'] ?? '');
            if (!preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color)) {
                $color = self::PALETTE[$index % count(self::PALETTE)];
            }
            $values = array_values(is_array($item['values'] ?? null) ? $item['values'] : []);
            $clean = [];
            for ($i = 0; $i < $count; $i++) {
                $value = isset($values[$i]) && is_numeric($values[$i]) ? (float)$values[$i] : 0.0;
                $clean[] = is_finite($value) && $value > 0 ? $value : 0.0;
            }
            $result[] = ['name' => (string)($item['name'] ?? ''), 'color' => $color, 'values' => $clean];
        }
        return $result;
    }

    /**
     * Full number with thousands separators for hover titles
     *
     * @param float $value
     * @return string
     */
    private function full(float $value): string
    {
        return number_format($value, floor($value) == $value ? 0 : 2, '.', ',');
    }

    /**
     * Coordinate with at most two decimals
     *
     * @param float|int $value
     * @return string
     */
    private function num($value): string
    {
        $text = number_format((float)$value, 2, '.', '');
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }
        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /**
     * Escape text for SVG text nodes and attribute values
     *
     * @param string $value
     * @return string
     */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }
}
