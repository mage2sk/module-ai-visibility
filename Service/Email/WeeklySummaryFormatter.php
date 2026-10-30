<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Email;

use Magento\Framework\Escaper;
use Panth\AiVisibility\Api\ImpactCalculatorInterface;

/**
 * Turns a WeeklySummaryBuilder result into the email subject, a plain-text summary and the template variables.
 *
 * Every stored value (paths, titles, bot user agents, source names, store name) is escaped here with the
 * Magento escaper before it goes into an HTML fragment; the template prints scalar values with {{var}}
 * (escaped by Magento) and the fragments built here with |raw. No images, no tracking.
 */
class WeeklySummaryFormatter
{
    private const FONT = 'font-family:Arial,Helvetica,sans-serif;';
    private const CELL = 'padding:8px 10px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;';
    private const NUM = 'text-align:right;white-space:nowrap;';
    private const MUTED = 'color:#64748b;font-size:12px;';
    private const MAX_TEXT = 120;

    private const VERDICT_COLOURS = [
        ImpactCalculatorInterface::VERDICT_HELPED => '#15803d',
        ImpactCalculatorInterface::VERDICT_NO_EFFECT => '#475569',
        ImpactCalculatorInterface::VERDICT_WORSE => '#b91c1c',
        ImpactCalculatorInterface::VERDICT_INSUFFICIENT => '#475569',
    ];

    /**
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly Escaper $escaper
    ) {
    }

    /**
     * Email subject (a mail header: plain text, line breaks and control characters removed)
     *
     * @param array $summary
     * @param string $siteName
     * @return string
     */
    public function subject(array $summary, string $siteName): string
    {
        return self::plain((string)__(
            'AI Visibility weekly summary for %1: %2 to %3',
            self::cut(self::plain($siteName), 80),
            (string)($summary['from'] ?? ''),
            (string)($summary['to'] ?? '')
        ));
    }

    /**
     * Plain-text summary (printed by bin/magento panth:aivisibility:email --dry-run)
     *
     * @param array $summary
     * @param string $siteName
     * @return string
     */
    public function text(array $summary, string $siteName): string
    {
        $lines = [$this->subject($summary, $siteName), $this->periodLabel($summary), ''];
        foreach ($this->metricRows($summary) as $row) {
            $lines[] = sprintf('%-22s %14s   %s', $row['label'] . ':', $row['current'], $row['change']);
        }
        if (!empty($summary['mixed_currencies'])) {
            $lines[] = $this->mixedCurrencyNote();
        }

        $lines[] = '';
        $lines[] = (string)__('Top pages AI reads:');
        foreach ($summary['top_pages'] ?? [] as $i => $page) {
            $lines[] = sprintf('  %d. %s (%s) - %d', $i + 1, self::line($page['label']), self::line($page['path']), $page['hits']);
        }
        $lines = $this->noneLine($lines, $summary['top_pages'] ?? []);

        $lines[] = (string)__('Top AI sources:');
        foreach ($summary['top_sources'] ?? [] as $i => $source) {
            $lines[] = sprintf('  %d. %s - %d', $i + 1, self::line($source['name']), $source['visits']);
        }
        $lines = $this->noneLine($lines, $summary['top_sources'] ?? []);

        $lines[] = (string)__('Top open recommendations:');
        foreach ($summary['recommendations'] ?? [] as $i => $rec) {
            $lines[] = sprintf('  %d. %s (impact %d)', $i + 1, self::line($rec['title']), $rec['impact_score']);
        }
        $lines = $this->noneLine($lines, $summary['recommendations'] ?? []);

        $lines[] = (string)__('Verdicts reached this week:');
        foreach ($summary['verdicts'] ?? [] as $verdict) {
            $lines[] = '  - ' . self::line($verdict['title']) . ': ' . $this->verdictText($verdict);
        }
        $lines = $this->noneLine($lines, $summary['verdicts'] ?? []);

        $candidates = $summary['candidates'] ?? ['count' => 0, 'items' => []];
        $lines[] = (string)__('New discovery candidates: %1', (int)$candidates['count']);
        foreach ($candidates['items'] as $item) {
            $lines[] = sprintf('  - [%s] %s - %d', $item['type'], self::line($item['key']), $item['hits']);
        }
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Variables for the email template
     *
     * @param array $summary
     * @param string $siteName
     * @param string $dashboardUrl Admin dashboard URL, '' when it could not be built
     * @return array<string, string>
     */
    public function templateVars(array $summary, string $siteName, string $dashboardUrl): array
    {
        return [
            'subject' => $this->subject($summary, $siteName),
            'site_name' => self::cut(self::plain($siteName), 80),
            'period_label' => $this->periodLabel($summary),
            'metrics_html' => $this->metricsHtml($summary),
            'pages_html' => $this->pagesHtml($summary['top_pages'] ?? []),
            'sources_html' => $this->sourcesHtml($summary['top_sources'] ?? []),
            'recommendations_html' => $this->recommendationsHtml($summary['recommendations'] ?? []),
            'verdicts_html' => $this->verdictsHtml($summary['verdicts'] ?? []),
            'candidates_html' => $this->candidatesHtml($summary['candidates'] ?? ['count' => 0, 'items' => []]),
            'dashboard_link_html' => $this->dashboardLinkHtml($dashboardUrl),
        ];
    }

    /**
     * Change against the previous week: "+12%", "-5%", "0%", "new" or "no change"
     *
     * @param int|float $current
     * @param int|float $previous
     * @return string
     */
    public static function change($current, $previous): string
    {
        if ((float)$previous <= 0.0) {
            return (float)$current > 0.0 ? (string)__('new') : (string)__('no change');
        }
        $pct = (int)round(((float)$current - (float)$previous) / (float)$previous * 100);
        return ($pct > 0 ? '+' : '') . $pct . '%';
    }

    /**
     * Effect ratio as a percentage change: 1.23 => "+23%"
     *
     * @param float $ratio
     * @return string
     */
    public static function effectPercent(float $ratio): string
    {
        $pct = (int)round(($ratio - 1.0) * 100);
        return ($pct > 0 ? '+' : '') . $pct . '%';
    }

    /**
     * Verdict wording with effect and range, e.g. "likely helped: +23% (range +10% to +38%)"
     *
     * @param array $verdict
     * @return string
     */
    public function verdictText(array $verdict): string
    {
        $label = self::verdictLabel((string)($verdict['verdict'] ?? ''));
        if (!isset($verdict['effect'])) {
            return $label;
        }
        $text = $label . ': ' . self::effectPercent((float)$verdict['effect']);
        if (isset($verdict['ci_low'], $verdict['ci_high'])) {
            $text .= ' ' . (string)__(
                '(range %1 to %2)',
                self::effectPercent((float)$verdict['ci_low']),
                self::effectPercent((float)$verdict['ci_high'])
            );
        }
        return $text;
    }

    /**
     * Verdict wording; never claims certainty
     *
     * @param string $verdict
     * @return string
     */
    public static function verdictLabel(string $verdict): string
    {
        switch ($verdict) {
            case ImpactCalculatorInterface::VERDICT_HELPED:
                return (string)__('likely helped');
            case ImpactCalculatorInterface::VERDICT_NO_EFFECT:
                return (string)__('no clear effect');
            case ImpactCalculatorInterface::VERDICT_WORSE:
                return (string)__('likely made it worse');
            default:
                return (string)__('not enough data yet');
        }
    }

    /**
     * "2026-10-19 to 2026-10-25 (UTC), compared with 2026-10-12 to 2026-10-18"
     *
     * @param array $summary
     * @return string
     */
    private function periodLabel(array $summary): string
    {
        return (string)__(
            '%1 to %2 (UTC), compared with %3 to %4',
            (string)($summary['from'] ?? ''),
            (string)($summary['to'] ?? ''),
            (string)($summary['prev_from'] ?? ''),
            (string)($summary['prev_to'] ?? '')
        );
    }

    /**
     * Headline numbers with formatted values and change
     *
     * @param array $summary
     * @return array<int, array{label: string, current: string, previous: string, change: string}>
     */
    private function metricRows(array $summary): array
    {
        $labels = [
            'crawler_hits' => (string)__('AI crawler hits'),
            'live_fetches' => (string)__('Live-answer fetches'),
            'ai_visits' => (string)__('AI visits'),
            'ai_orders' => (string)__('AI orders'),
            'ai_revenue' => (string)__('AI revenue'),
        ];
        $rows = [];
        foreach ($labels as $key => $label) {
            $metric = $summary['metrics'][$key] ?? ['current' => 0, 'previous' => 0];
            if ($key === 'ai_revenue') {
                $current = (string)($summary['money']['current'] ?? number_format((float)$metric['current'], 2));
                $previous = (string)($summary['money']['previous'] ?? number_format((float)$metric['previous'], 2));
            } else {
                $current = number_format((int)$metric['current']);
                $previous = number_format((int)$metric['previous']);
            }
            $rows[] = [
                'label' => $label,
                'current' => $current,
                'previous' => $previous,
                'change' => self::change($metric['current'], $metric['previous']),
            ];
        }
        return $rows;
    }

    /**
     * Headline numbers table
     *
     * @param array $summary
     * @return string
     */
    private function metricsHtml(array $summary): string
    {
        $head = $this->headRow([
            (string)__('Metric'),
            (string)__('This week'),
            (string)__('Week before'),
            (string)__('Change'),
        ], [false, true, true, true]);
        $body = '';
        foreach ($this->metricRows($summary) as $row) {
            $colour = str_starts_with($row['change'], '-') ? '#b91c1c' : '#15803d';
            if (!str_starts_with($row['change'], '-') && !str_starts_with($row['change'], '+')) {
                $colour = '#475569';
            }
            $body .= '<tr>'
                . '<td style="' . self::CELL . self::FONT . '">' . $this->e($row['label']) . '</td>'
                . '<td style="' . self::CELL . self::FONT . self::NUM . 'font-weight:bold;">'
                . $this->e($row['current']) . '</td>'
                . '<td style="' . self::CELL . self::FONT . self::NUM . 'color:#64748b;">'
                . $this->e($row['previous']) . '</td>'
                . '<td style="' . self::CELL . self::FONT . self::NUM . 'color:' . $colour . ';">'
                . $this->e($row['change']) . '</td>'
                . '</tr>';
        }
        $html = $this->table($head . $body);
        if (!empty($summary['mixed_currencies'])) {
            $html .= '<p style="margin:8px 0 0;' . self::MUTED . self::FONT . '">'
                . $this->e($this->mixedCurrencyNote()) . '</p>';
        }
        return $html;
    }

    /**
     * Note shown when revenue of store views with different base currencies is summed
     *
     * @return string
     */
    private function mixedCurrencyNote(): string
    {
        return (string)__(
            'Your store views use different base currencies, so AI revenue adds up amounts in different '
            . 'currencies. Open the dashboard and pick one store view for exact revenue figures.'
        );
    }

    /**
     * Top pages table
     *
     * @param array $pages
     * @return string
     */
    private function pagesHtml(array $pages): string
    {
        if ($pages === []) {
            return $this->none((string)__('No AI bot reads this week.'));
        }
        $body = $this->headRow([(string)__('Page'), (string)__('Reads')], [false, true]);
        foreach ($pages as $page) {
            $label = self::cut((string)$page['label'], self::MAX_TEXT);
            $path = self::cut((string)$page['path'], self::MAX_TEXT);
            $body .= '<tr><td style="' . self::CELL . self::FONT . '">' . $this->e($label)
                . '<br><span style="' . self::MUTED . '">' . $this->e((string)$page['type']) . ' &middot; '
                . $this->e($path) . '</span></td>'
                . '<td style="' . self::CELL . self::FONT . self::NUM . '">' . $this->e(number_format((int)$page['hits']))
                . '</td></tr>';
        }
        return $this->table($body);
    }

    /**
     * Top sources table
     *
     * @param array $sources
     * @return string
     */
    private function sourcesHtml(array $sources): string
    {
        if ($sources === []) {
            return $this->none((string)__('No visits from AI assistants this week.'));
        }
        $body = $this->headRow([(string)__('Source'), (string)__('Visits')], [false, true]);
        foreach ($sources as $source) {
            $body .= '<tr><td style="' . self::CELL . self::FONT . '">'
                . $this->e(self::cut((string)$source['name'], self::MAX_TEXT)) . '</td>'
                . '<td style="' . self::CELL . self::FONT . self::NUM . '">'
                . $this->e(number_format((int)$source['visits'])) . '</td></tr>';
        }
        return $this->table($body);
    }

    /**
     * Open recommendations list
     *
     * @param array $recommendations
     * @return string
     */
    private function recommendationsHtml(array $recommendations): string
    {
        if ($recommendations === []) {
            return $this->none((string)__('No open recommendations.'));
        }
        $body = $this->headRow([(string)__('Recommendation'), (string)__('Impact')], [false, true]);
        foreach ($recommendations as $rec) {
            $body .= '<tr><td style="' . self::CELL . self::FONT . '">'
                . $this->e(self::cut((string)$rec['title'], 200)) . '</td>'
                . '<td style="' . self::CELL . self::FONT . self::NUM . '">'
                . $this->e((string)(int)$rec['impact_score']) . ' / 100</td></tr>';
        }
        return $this->table($body);
    }

    /**
     * Verdicts list
     *
     * @param array $verdicts
     * @return string
     */
    private function verdictsHtml(array $verdicts): string
    {
        if ($verdicts === []) {
            return $this->none((string)__('No verdicts this week.'));
        }
        $body = $this->headRow([(string)__('Applied fix'), (string)__('Result')], [false, false]);
        foreach ($verdicts as $verdict) {
            $colour = self::VERDICT_COLOURS[$verdict['verdict'] ?? ''] ?? '#475569';
            $body .= '<tr><td style="' . self::CELL . self::FONT . '">'
                . $this->e(self::cut((string)$verdict['title'], 200)) . '</td>'
                . '<td style="' . self::CELL . self::FONT . 'color:' . $colour . ';">'
                . $this->e($this->verdictText($verdict)) . '</td></tr>';
        }
        return $this->table($body);
    }

    /**
     * New discovery candidates
     *
     * @param array $candidates
     * @return string
     */
    private function candidatesHtml(array $candidates): string
    {
        $count = (int)($candidates['count'] ?? 0);
        if ($count === 0) {
            return $this->none((string)__('No new unknown bots or AI sources were found.'));
        }
        $intro = '<p style="margin:0 0 8px;font-size:14px;color:#0f172a;' . self::FONT . '">'
            . $this->e((string)__('%1 new candidate(s) are waiting for review.', $count)) . '</p>';
        $body = $this->headRow([(string)__('Type'), (string)__('Seen as'), (string)__('Requests')], [false, false, true]);
        foreach ($candidates['items'] ?? [] as $item) {
            $body .= '<tr><td style="' . self::CELL . self::FONT . '">' . $this->e((string)$item['type']) . '</td>'
                . '<td style="' . self::CELL . self::FONT . 'word-break:break-all;">'
                . $this->e(self::cut((string)$item['key'], self::MAX_TEXT)) . '</td>'
                . '<td style="' . self::CELL . self::FONT . self::NUM . '">'
                . $this->e(number_format((int)$item['hits'])) . '</td></tr>';
        }
        return $intro . $this->table($body);
    }

    /**
     * Button to the admin dashboard
     *
     * @param string $url
     * @return string
     */
    private function dashboardLinkHtml(string $url): string
    {
        // Only http(s) URLs: no javascript:/data: links. escapeHtml (quotes included) makes it attribute-safe.
        if (!preg_match('#^https?://[^\s]+$#i', $url)) {
            return '';
        }
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 0;">'
            . '<tr><td style="background:#0f172a;border-radius:4px;">'
            . '<a href="' . $this->e($url) . '" style="display:inline-block;padding:10px 18px;'
            . 'color:#ffffff;text-decoration:none;font-size:14px;font-weight:bold;' . self::FONT . '">'
            . $this->e((string)__('Open the AI Visibility dashboard')) . '</a></td></tr></table>';
    }

    /**
     * Header row
     *
     * @param string[] $labels
     * @param bool[] $numeric
     * @return string
     */
    private function headRow(array $labels, array $numeric): string
    {
        $html = '<tr>';
        foreach ($labels as $i => $label) {
            $html .= '<th style="padding:6px 10px;border-bottom:2px solid #cbd5e1;font-size:12px;color:#475569;'
                . 'text-transform:uppercase;' . self::FONT . (($numeric[$i] ?? false) ? self::NUM : 'text-align:left;')
                . '">' . $this->e($label) . '</th>';
        }
        return $html . '</tr>';
    }

    /**
     * Wrap rows in a full-width table
     *
     * @param string $rows
     * @return string
     */
    private function table(string $rows): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:collapse;width:100%;">' . $rows . '</table>';
    }

    /**
     * Muted "nothing here" line
     *
     * @param string $text
     * @return string
     */
    private function none(string $text): string
    {
        return '<p style="margin:0;' . self::MUTED . 'font-size:14px;' . self::FONT . '">' . $this->e($text) . '</p>';
    }

    /**
     * Append "none" to a text section when it has no rows, then a blank line
     *
     * @param string[] $lines
     * @param array $rows
     * @return string[]
     */
    private function noneLine(array $lines, array $rows): array
    {
        if ($rows === []) {
            $lines[] = '  ' . (string)__('none');
        }
        $lines[] = '';
        return $lines;
    }

    /**
     * Escape for HTML text and attribute values
     *
     * @param string $value
     * @return string
     */
    private function e(string $value): string
    {
        return (string)$this->escaper->escapeHtml($value);
    }

    /**
     * Remove line breaks and control characters
     *
     * @param string $value
     * @return string
     */
    private static function plain(string $value): string
    {
        return trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }

    /**
     * One plain-text line, shortened
     *
     * @param mixed $value
     * @return string
     */
    private static function line($value): string
    {
        return self::cut(self::plain((string)$value), self::MAX_TEXT);
    }

    /**
     * Shorten to a maximum number of characters
     *
     * @param string $value
     * @param int $max
     * @return string
     */
    private static function cut(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
    }
}
