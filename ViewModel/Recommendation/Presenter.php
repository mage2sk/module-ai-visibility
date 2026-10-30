<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\ViewModel\Recommendation;

use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;

/**
 * Plain-language wording for recommendations, evidence and verification results.
 *
 * Returns unescaped text: templates escape everything they print. Every phrase is a literal __() call
 * so the i18n tooling finds it.
 */
class Presenter
{
    public const MAX_EXAMPLES = 10;

    public const MAX_BOTS = 5;

    private const MAX_EXAMPLE_LENGTH = 200;

    /**
     * Evidence keys never shown as facts: the subject list, the rule's own fix text (shown as "How to fix")
     * and the date range (shown once as "Dates (UTC)")
     */
    private const HIDDEN_FACT_KEYS = ['how_to_fix', 'from', 'to', 'window'];

    /**
     * Evidence lists that hold bots, not examples
     */
    private const BOT_LIST_KEYS = ['top_bots', 'bots'];

    /**
     * Evidence values that are percentages (0-100)
     */
    private const PERCENT_KEYS = ['share_pct', 'share_percent', 'threshold_percent'];

    /**
     * Subject refs that name a whole site or a combined finding, not an example
     */
    private const PSEUDO_SUBJECT = '/^(site|paths|products|categories)(:[a-z0-9_]*)?$/';

    /**
     * Readable name of a rule
     *
     * @param string $ruleCode
     * @return string
     */
    public function ruleLabel(string $ruleCode): string
    {
        switch ($ruleCode) {
            case 'robots_blocks_ai_search':
                return (string)__('robots.txt blocks AI search bots');
            case 'bot_404':
                return (string)__('Pages answer client errors (4xx) to AI bots');
            case 'bot_errors_or_slow':
                return (string)__('Server errors or slow pages for AI bots');
            case 'never_crawled_products':
                return (string)__('Products AI crawlers never visited');
            case 'live_answers_no_sales':
                return (string)__('AI answers send shoppers but no sales');
            case 'thin_content_ai_read':
                return (string)__('Thin product content read by AI');
            case 'llms_not_served':
                return (string)__('llms.txt is not served');
            case 'parameter_crawl_waste':
                return (string)__('AI crawl wasted on URLs with parameters');
            case 'ai_visitors_out_of_stock':
                return (string)__('AI shoppers land on out-of-stock products');
            case 'discovery_backlog':
                return (string)__('Unknown AI bots and apps wait for review');
            default:
                return $this->humanize($ruleCode);
        }
    }

    /**
     * What to do about a rule's finding ('' when unknown)
     *
     * @param string $ruleCode
     * @return string
     */
    public function fixHint(string $ruleCode): string
    {
        switch ($ruleCode) {
            case 'robots_blocks_ai_search':
                return (string)__(
                    'Allow the AI search and live-answer bots in your robots.txt settings, so AI assistants can read '
                    . 'and cite your pages.'
                );
            case 'bot_404':
                return (string)__(
                    'Redirect these addresses to the closest live page. Suggested targets are listed where a similar '
                    . 'page was found.'
                );
            case 'bot_errors_or_slow':
                return (string)__(
                    'Check these pages for server errors and slow responses. The Crawler Log shows each request with '
                    . 'its status and time.'
                );
            case 'never_crawled_products':
                return (string)__(
                    'Link these products from categories, menus or the XML sitemap so AI crawlers find them.'
                );
            case 'live_answers_no_sales':
                return (string)__(
                    'AI assistants read these pages and send shoppers who do not buy. Check price, stock, delivery '
                    . 'details and the add-to-cart area.'
                );
            case 'thin_content_ai_read':
                return (string)__(
                    'Write a fuller description (150 words or more) and add image labels to these products.'
                );
            case 'llms_not_served':
                return (string)__(
                    'Switch on and publish llms.txt, then check that /llms.txt answers without errors.'
                );
            case 'parameter_crawl_waste':
                return (string)__(
                    'Keep AI bots away from filter and sort URLs (with query strings) in your robots.txt settings, and '
                    . 'make sure those pages have canonical tags.'
                );
            case 'ai_visitors_out_of_stock':
                return (string)__(
                    'Restock these products, or show alternatives on their pages while they are out of stock.'
                );
            case 'discovery_backlog':
                return (string)__(
                    'Review the waiting candidates on the Discovery page and confirm the real AI bots and apps.'
                );
            default:
                return '';
        }
    }

    /**
     * Readable name of a metric
     *
     * @param string $metric
     * @return string
     */
    public function metricLabel(string $metric): string
    {
        switch ($metric) {
            case MetricProviderInterface::METRIC_BOT_HITS:
                return (string)__('AI crawler hits');
            case MetricProviderInterface::METRIC_SEARCH_BOT_HITS:
                return (string)__('AI search and live-answer bot hits');
            case MetricProviderInterface::METRIC_LIVE_FETCHES:
                return (string)__('live-answer fetches');
            case MetricProviderInterface::METRIC_BOT_404:
                return (string)__('client errors (4xx) for AI bots');
            case MetricProviderInterface::METRIC_BOT_5XX:
                return (string)__('server errors for AI bots');
            case MetricProviderInterface::METRIC_BOT_AVG_MS:
                return (string)__('average response time for AI bots');
            case MetricProviderInterface::METRIC_AI_VISITS:
                return (string)__('AI visits');
            case MetricProviderInterface::METRIC_AI_ORDERS:
                return (string)__('AI orders');
            case MetricProviderInterface::METRIC_AI_CONVERSION:
                return (string)__('AI conversion rate');
            case MetricProviderInterface::METRIC_CANONICAL_CRAWL_SHARE:
                return (string)__('share of AI crawl on clean URLs');
            case MetricProviderInterface::METRIC_LLMS_FETCHES:
                return (string)__('llms.txt fetches');
            case MetricProviderInterface::METRIC_CANDIDATE_BACKLOG:
                return (string)__('candidates waiting for review');
            default:
                return $this->humanize($metric);
        }
    }

    /**
     * Whether a lower value of the metric is the improvement
     *
     * @param string $metric
     * @return bool
     */
    public function isLessBetter(string $metric): bool
    {
        return in_array($metric, MetricProviderInterface::LESS_IS_BETTER, true);
    }

    /**
     * Short verdict wording; never claims certainty
     *
     * @param string $verdict
     * @return string
     */
    public function verdictLabel(string $verdict): string
    {
        switch ($verdict) {
            case ImpactCalculatorInterface::VERDICT_HELPED:
                return (string)__('Likely helped');
            case ImpactCalculatorInterface::VERDICT_NO_EFFECT:
                return (string)__('No clear effect');
            case ImpactCalculatorInterface::VERDICT_WORSE:
                return (string)__('Likely made it worse');
            default:
                return (string)__('Not enough data yet');
        }
    }

    /**
     * CSS modifier of a verdict: good | bad | neutral | unknown
     *
     * @param string $verdict
     * @return string
     */
    public function verdictTone(string $verdict): string
    {
        switch ($verdict) {
            case ImpactCalculatorInterface::VERDICT_HELPED:
                return 'good';
            case ImpactCalculatorInterface::VERDICT_WORSE:
                return 'bad';
            case ImpactCalculatorInterface::VERDICT_NO_EFFECT:
                return 'neutral';
            default:
                return 'unknown';
        }
    }

    /**
     * Ratio (1.0 = no change) as a signed percent change, e.g. 1.38 => "+38%"
     *
     * @param float $ratio
     * @return string
     */
    public function percentChange(float $ratio): string
    {
        $pct = (int)round(($ratio - 1.0) * 100);
        if ($pct === 0) {
            return '0%';
        }
        return ($pct > 0 ? '+' : '-') . number_format(abs($pct)) . '%';
    }

    /**
     * Plain sentence for an impact row: "Likely helped: +38% (range +12% to +70%)"
     *
     * Coverage results (never-fetched products) compare two shares, so they read
     * "Likely helped: 60% of fixed products got crawled vs 15% normally (difference +45 points, range ...)".
     *
     * @param array|null $impact Impact row, or null while not verified
     * @param string $subjectType Subject type of the action (product | category ...), for coverage wording
     * @return string
     */
    public function effectSentence(?array $impact, string $subjectType = ''): string
    {
        if ($impact === null) {
            return '';
        }
        $verdict = (string)($impact['verdict'] ?? ImpactCalculatorInterface::VERDICT_INSUFFICIENT);
        if (!in_array($verdict, ImpactCalculatorInterface::VERDICTS, true)) {
            $verdict = ImpactCalculatorInterface::VERDICT_INSUFFICIENT;
        }
        $label = $this->verdictLabel($verdict);
        if ((string)($impact['method'] ?? '') === ImpactCalculatorInterface::METHOD_COVERAGE) {
            return $this->coverageSentence($impact, $verdict, $label, $subjectType);
        }
        $effect = $this->decimal($impact['effect'] ?? null);
        $low = $this->decimal($impact['ci_low'] ?? null);
        $high = $this->decimal($impact['ci_high'] ?? null);

        if ($effect === null) {
            return $label;
        }
        if ($low === null || $high === null) {
            return $verdict === ImpactCalculatorInterface::VERDICT_INSUFFICIENT
                ? (string)__('%1 (rough estimate %2, no reliable range)', $label, $this->percentChange($effect))
                : (string)__('%1: %2 (no reliable range)', $label, $this->percentChange($effect));
        }
        if ($verdict === ImpactCalculatorInterface::VERDICT_INSUFFICIENT) {
            return (string)__(
                '%1 (rough estimate %2, range %3 to %4)',
                $label,
                $this->percentChange($effect),
                $this->percentChange(min($low, $high)),
                $this->percentChange(max($low, $high))
            );
        }
        return (string)__(
            '%1: %2 (range %3 to %4)',
            $label,
            $this->percentChange($effect),
            $this->percentChange(min($low, $high)),
            $this->percentChange(max($low, $high))
        );
    }

    /**
     * Fetched shares of a coverage result: [fixed share, baseline share] as fractions, null when unknown
     *
     * Read from the counts in details.coverage, else from treat_after / control_after.
     *
     * @param array $impact
     * @return array{0: float, 1: float}|null
     */
    public function coverageShares(array $impact): ?array
    {
        $details = is_array($impact['details'] ?? null) ? $impact['details'] : [];
        $coverage = is_array($details['coverage'] ?? null) ? $details['coverage'] : [];
        $treated = (int)($coverage['treated'] ?? 0);
        $baseline = (int)($coverage['baseline'] ?? 0);
        if ($treated > 0 && $baseline > 0) {
            return [
                min(1.0, max(0.0, (int)($coverage['treated_fetched'] ?? 0) / $treated)),
                min(1.0, max(0.0, (int)($coverage['baseline_fetched'] ?? 0) / $baseline)),
            ];
        }
        $fixed = $this->decimal($impact['treat_after'] ?? null);
        $normal = $this->decimal($impact['control_after'] ?? null);
        if ($fixed === null || $normal === null || $fixed < 0 || $normal < 0) {
            return null;
        }
        return [min(1.0, $fixed), min(1.0, $normal)];
    }

    /**
     * How the effect was measured, in plain words
     *
     * @param array $impact
     * @return string
     */
    public function methodSentence(array $impact): string
    {
        $days = (int)($impact['window_days'] ?? 0);
        if ((string)($impact['method'] ?? '') === ImpactCalculatorInterface::METHOD_COVERAGE) {
            $details = is_array($impact['details'] ?? null) ? $impact['details'] : [];
            $coverage = is_array($details['coverage'] ?? null) ? $details['coverage'] : [];
            return (string)__(
                '%1 of %2 fixed items were fetched by AI bots in the %3 days after, compared with %4 of %5 similar '
                . 'never-fetched items in the %3 days before.',
                (int)($coverage['treated_fetched'] ?? 0),
                (int)($coverage['treated'] ?? 0),
                $days,
                (int)($coverage['baseline_fetched'] ?? 0),
                (int)($coverage['baseline'] ?? 0)
            );
        }
        if ((string)($impact['method'] ?? '') === ImpactCalculatorInterface::METHOD_DAILY_MEANS) {
            return (string)__(
                'Daily average over %1 days before vs %1 days after the change, allowing for normal day-to-day swings.',
                $days
            );
        }
        if ((string)($impact['method'] ?? '') === ImpactCalculatorInterface::METHOD_DID) {
            return (string)__(
                '%1 days before vs %1 days after, compared with %2 similar items you did not change.',
                $days,
                (int)($impact['subjects_control'] ?? 0)
            );
        }
        return (string)__('%1 days before vs %1 days after the change.', $days);
    }

    /**
     * Number formatted for the metric (percent for ratio metrics, ms for response time)
     *
     * @param string $metric
     * @param float $value
     * @return string
     */
    public function formatMetricValue(string $metric, float $value): string
    {
        if ($metric === MetricProviderInterface::METRIC_BOT_AVG_MS) {
            return number_format($value) . ' ms';
        }
        if (in_array($metric, MetricProviderInterface::RATIO_METRICS, true)) {
            return number_format($value * 100, 1) . '%';
        }
        return abs($value - round($value)) < 0.05 ? number_format($value) : number_format($value, 1);
    }

    /**
     * Scalar evidence values as label / value pairs, the date range first
     *
     * Left out: the subject list, nested lists (shown as examples / bots), the rule's own "how to fix" text
     * (the page shows its own) and empty values.
     *
     * @param array $evidence
     * @param string $ruleCode
     * @return array<int, array{label: string, value: string}>
     */
    public function evidenceFacts(array $evidence, string $ruleCode = ''): array
    {
        $facts = [];
        $window = $this->evidenceWindow($evidence);
        if ($window !== '') {
            $facts[] = ['label' => (string)__('Dates (UTC)'), 'value' => $window];
        }
        foreach ($evidence as $key => $value) {
            $key = (string)$key;
            if ($key === RecommendationRepositoryInterface::EVIDENCE_SUBJECTS
                || in_array($key, self::HIDDEN_FACT_KEYS, true)
                || !is_scalar($value) || $value === ''
            ) {
                continue;
            }
            $facts[] = [
                'label' => $this->factLabel($key, $ruleCode),
                'value' => $this->cut($this->factValue($key, $value)),
            ];
        }
        return $facts;
    }

    /**
     * Date range of the evidence ("2026-10-23 to 2026-10-29"), '' when it has none
     *
     * @param array $evidence
     * @return string
     */
    public function evidenceWindow(array $evidence): string
    {
        $window = is_array($evidence['window'] ?? null) ? $evidence['window'] : $evidence;
        $from = $window['from'] ?? null;
        $to = $window['to'] ?? null;
        if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
            return '';
        }
        return (string)__('%1 to %2', $this->cut($from), $this->cut($to));
    }

    /**
     * Bots named in the evidence, e.g. "GPTBot (36 hits)" (max MAX_BOTS)
     *
     * @param array $evidence
     * @return string[]
     */
    public function evidenceBots(array $evidence): array
    {
        $bots = [];
        foreach ($evidence as $key => $value) {
            if (!$this->isBotList((string)$key, $value)) {
                continue;
            }
            foreach ($value as $bot) {
                $text = $this->botText($bot);
                if ($text !== '') {
                    $bots[] = $text;
                }
                if (count($bots) >= self::MAX_BOTS) {
                    return $bots;
                }
            }
        }
        return $bots;
    }

    /**
     * Top examples: the first list in the evidence that is not a bot list (max MAX_EXAMPLES)
     *
     * When the evidence has an example list, an empty one means "no examples". Only evidence without any
     * list falls back to the subjects, leaving out refs that name the whole site or a combined finding.
     *
     * @param array $evidence
     * @return string[]
     */
    public function evidenceExamples(array $evidence): array
    {
        $list = null;
        foreach ($evidence as $key => $value) {
            $key = (string)$key;
            if ($key === RecommendationRepositoryInterface::EVIDENCE_SUBJECTS || !is_array($value)
                || !array_is_list($value) || $this->isBotList($key, $value)
            ) {
                continue;
            }
            $list = $value;
            break;
        }
        if ($list === null) {
            $list = array_values(array_filter(
                $this->subjects($evidence),
                static fn (string $subject): bool => preg_match(self::PSEUDO_SUBJECT, $subject) !== 1
            ));
        }
        $examples = [];
        foreach ($list as $item) {
            $text = $this->exampleText($item);
            if ($text !== '') {
                $examples[] = $text;
            }
            if (count($examples) >= self::MAX_EXAMPLES) {
                break;
            }
        }
        return $examples;
    }

    /**
     * Subject list of a recommendation's evidence
     *
     * @param array $evidence
     * @return string[]
     */
    public function subjects(array $evidence): array
    {
        $subjects = $evidence[RecommendationRepositoryInterface::EVIDENCE_SUBJECTS] ?? [];
        if (!is_array($subjects)) {
            return [];
        }
        return array_values(array_map('strval', array_filter($subjects, 'is_scalar')));
    }

    /**
     * "snake_case_key" => "Snake case key"
     *
     * @param string $key
     * @return string
     */
    public function humanize(string $key): string
    {
        $text = trim((string)preg_replace('/[_\s]+/', ' ', $key));
        return $text === '' ? '' : ucfirst($text);
    }

    /**
     * Coverage verdict: fixed share vs baseline share and the difference in percentage points
     *
     * @param array $impact
     * @param string $verdict
     * @param string $label
     * @param string $subjectType
     * @return string
     */
    private function coverageSentence(array $impact, string $verdict, string $label, string $subjectType): string
    {
        $shares = $this->coverageShares($impact);
        if ($shares === null) {
            return $label;
        }
        [$fixed, $normal] = $shares;
        $details = is_array($impact['details'] ?? null) ? $impact['details'] : [];
        $coverage = is_array($details['coverage'] ?? null) ? $details['coverage'] : [];
        $diff = $this->decimal($coverage['diff'] ?? null) ?? ($fixed - $normal);
        $low = $this->decimal($coverage['diff_low'] ?? null);
        $high = $this->decimal($coverage['diff_high'] ?? null);
        $fixedText = $this->percent($fixed);
        $normalText = $this->percent($normal);
        $shareText = str_contains($subjectType, 'categor')
            ? (string)__('%1 of fixed categories got crawled vs %2 normally', $fixedText, $normalText)
            : (string)__('%1 of fixed products got crawled vs %2 normally', $fixedText, $normalText);

        if ($verdict === ImpactCalculatorInterface::VERDICT_INSUFFICIENT) {
            return (string)__('%1 (so far %2)', $label, $shareText);
        }
        if ($low === null || $high === null) {
            return (string)__(
                '%1: %2 (difference %3 points, no reliable range)',
                $label,
                $shareText,
                $this->points($diff)
            );
        }
        return (string)__(
            '%1: %2 (difference %3 points, range %4 to %5 points)',
            $label,
            $shareText,
            $this->points($diff),
            $this->points(min($low, $high)),
            $this->points(max($low, $high))
        );
    }

    /**
     * Fraction as a whole percent, e.g. 0.6 => "60%"
     *
     * @param float $share
     * @return string
     */
    private function percent(float $share): string
    {
        return number_format((int)round($share * 100)) . '%';
    }

    /**
     * Difference of two fractions as signed percentage points, e.g. 0.45 => "+45"
     *
     * @param float $diff
     * @return string
     */
    private function points(float $diff): string
    {
        $points = (int)round($diff * 100);
        if ($points === 0) {
            return '0';
        }
        return ($points > 0 ? '+' : '-') . number_format(abs($points));
    }

    /**
     * Readable label of an evidence value
     *
     * @param string $key
     * @param string $ruleCode
     * @return string
     */
    private function factLabel(string $key, string $ruleCode): string
    {
        switch ($key) {
            case 'window_days':
                return (string)__('Days checked');
            case 'share_pct':
                return $ruleCode === 'never_crawled_products'
                    ? (string)__('Share of products')
                    : (string)__('Share of all AI bot hits');
            case 'share_percent':
                return (string)__('Share of AI crawl');
            case 'threshold_percent':
                return (string)__('Recommended when above');
            case 'hits_404':
            case 'hits_4xx':
                return (string)__('Client-error hits');
            case 'paths_404':
            case 'paths_4xx':
                return (string)__('Pages with client errors');
            case 'bots':
                return (string)__('AI bots');
            case 'all_bot_hits':
                return (string)__('All AI bot hits');
            case 'redirect_guesses':
                return (string)__('Redirect suggestions');
            case 'errors_5xx':
                return (string)__('Server errors (5xx)');
            case 'paths':
            case 'pages':
                return (string)__('Pages');
            case 'slow_paths':
                return (string)__('Slow pages');
            case 'slow_hits':
                return (string)__('Slow hits');
            case 'threshold_ms':
                return (string)__('Slow above (ms)');
            case 'min_hits_per_path':
                return (string)__('Minimum hits per page');
            case 'crawl_data_since':
                return (string)__('Crawl data since');
            case 'never_crawled':
                return (string)__('Never fetched');
            case 'eligible':
                return (string)__('Products checked');
            case 'listed':
                return (string)__('Listed');
            case 'reason':
                return (string)__('Reason');
            case 'module_enabled':
                return (string)__('Panth_LlmsTxt enabled');
            case 'setting_enabled':
                return (string)__('llms.txt switched on');
            case 'ai_crawler_hits':
                return (string)__('AI crawler hits');
            case 'llms_requests':
                return (string)__('llms.txt requests');
            case 'llms_2xx':
                return (string)__('llms.txt answered OK');
            case 'llms_4xx':
                return (string)__('llms.txt client errors (4xx)');
            case 'llms_5xx':
                return (string)__('llms.txt server errors (5xx)');
            case 'hits_with_parameters':
                return (string)__('Hits on URLs with parameters');
            case 'products':
                return (string)__('Products');
            case 'ai_visits':
                return (string)__('AI visits');
            case 'ai_orders':
                return (string)__('AI orders');
            case 'ai_reads':
                return (string)__('AI reads');
            case 'live_fetches':
                return (string)__('Live-answer fetches');
            case 'stock_source':
                return (string)__('Stock source');
            case 'min_per_page':
                return (string)__('Minimum per page');
            case 'thin_description':
                return (string)__('Thin descriptions');
            case 'images_without_label':
                return (string)__('Images without label');
            case 'min_words':
                return (string)__('Minimum words');
            case 'wait_days':
                return (string)__('Waiting longer than (days)');
            case 'candidates':
                return (string)__('Candidates');
            case 'sources':
                return (string)__('AI sources');
            case 'hits':
                return (string)__('Hits');
            case 'min_hits':
                return (string)__('Minimum hits');
            case 'blocked_count':
                return (string)__('Blocked bots');
            default:
                return $this->humanize($key);
        }
    }

    /**
     * Display text of an evidence value
     *
     * @param string $key
     * @param bool|int|float|string $value
     * @return string
     */
    private function factValue(string $key, $value): string
    {
        if (is_bool($value)) {
            return $value ? (string)__('yes') : (string)__('no');
        }
        if (in_array($key, self::PERCENT_KEYS, true) && is_numeric($value)) {
            $percent = round((float)$value, 1);
            return ($percent === round($percent) ? number_format($percent) : number_format($percent, 1)) . '%';
        }
        if ($key === 'reason') {
            switch ((string)$value) {
                case 'never_fetched':
                    return (string)__('never fetched');
                case 'disabled':
                    return (string)__('switched off');
                case 'errors':
                    return (string)__('answers with errors');
                default:
                    return strtolower($this->humanize((string)$value));
            }
        }
        return $this->scalarText($value);
    }

    /**
     * Whether an evidence list holds bots
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    private function isBotList(string $key, $value): bool
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            return false;
        }
        if (in_array($key, self::BOT_LIST_KEYS, true)) {
            return true;
        }
        return is_array($value[0]) && array_key_exists('bot', $value[0]);
    }

    /**
     * One bot as "Name (12 hits)"
     *
     * @param mixed $bot
     * @return string
     */
    private function botText($bot): string
    {
        if (is_scalar($bot)) {
            return $this->cut((string)$bot);
        }
        if (!is_array($bot)) {
            return '';
        }
        $name = '';
        foreach (['name', 'bot', 'code'] as $field) {
            if (is_scalar($bot[$field] ?? null) && (string)$bot[$field] !== '') {
                $name = $this->cut((string)$bot[$field]);
                break;
            }
        }
        if ($name === '') {
            return '';
        }
        if (is_numeric($bot['hits'] ?? null)) {
            return (string)__('%1 (%2 hits)', $name, $this->scalarText((int)$bot['hits']));
        }
        if (is_numeric($bot['requests'] ?? null)) {
            return (string)__('%1 (%2 requests)', $name, $this->scalarText((int)$bot['requests']));
        }
        return $name;
    }

    /**
     * One example as text: scalars as they are, rows as "main value - key value, key value"
     *
     * The main value is the path, name, bot or value of the row (else its first value).
     *
     * @param mixed $item
     * @return string
     */
    private function exampleText($item): string
    {
        if (is_scalar($item)) {
            return $this->cut($this->scalarText($item));
        }
        if (!is_array($item)) {
            return '';
        }
        $mainKey = null;
        foreach (['path', 'name', 'bot', 'value'] as $field) {
            if (is_scalar($item[$field] ?? null) && (string)$item[$field] !== '') {
                $mainKey = $field;
                break;
            }
        }
        $main = $mainKey === null ? null : $this->scalarText($item[$mainKey]);
        $rest = [];
        foreach ($item as $key => $value) {
            if ($key === $mainKey || !is_scalar($value) || $value === '') {
                continue;
            }
            if (in_array($key, ['setting', 'entity_type'], true) || ($key === 'code' && $mainKey !== null)) {
                continue;
            }
            if ($main === null) {
                $main = $this->scalarText($value);
                continue;
            }
            $rest[] = is_int($key) ? $this->scalarText($value) : $this->examplePart((string)$key, $value);
        }
        if ($main === null) {
            return '';
        }
        return $this->cut($rest === [] ? $main : $main . ' - ' . implode(', ', $rest));
    }

    /**
     * "key value" part of an example row
     *
     * @param string $key
     * @param bool|int|float|string $value
     * @return string
     */
    private function examplePart(string $key, $value): string
    {
        $text = $this->scalarText($value);
        switch ($key) {
            case 'product_id':
            case 'candidate_id':
            case 'entity_id':
                return (string)__('ID %1', (string)$value);
            case 'last_seen':
                return (string)__('last seen %1 UTC', $text);
            case 'first_seen':
                return (string)__('first seen %1 UTC', $text);
            case 'avg_ms':
                return (string)__('average %1 ms', $text);
            case 'max_ms':
                return (string)__('slowest %1 ms', $text);
            case 'redirect_to':
                return (string)__('suggested redirect %1', $text);
            case 'hits_30d':
                return (string)__('%1 hits in 30 days', $text);
            case 'bots':
                return (int)$value === 1 ? (string)__('1 bot') : (string)__('%1 bots', $text);
            case 'ai_visits':
                return (string)__('%1 AI visits', $text);
            case 'ai_orders':
                return (string)__('%1 AI orders', $text);
            case 'live_fetches':
                return (string)__('%1 live-answer fetches', $text);
            case 'llms_fetches':
                return (string)__('%1 llms.txt fetches', $text);
            default:
                return strtolower($this->humanize($key)) . ' ' . $text;
        }
    }

    /**
     * Scalar as display text (numbers with thousands separators)
     *
     * @param mixed $value
     * @return string
     */
    private function scalarText($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return number_format($value);
        }
        if (is_float($value)) {
            return abs($value - round($value)) < 0.005 ? number_format($value) : number_format($value, 2);
        }
        return (string)$value;
    }

    /**
     * Decimal column value as float, null when empty
     *
     * @param mixed $value
     * @return float|null
     */
    private function decimal($value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }

    /**
     * Cut long text for display
     *
     * @param string $text
     * @return string
     */
    private function cut(string $text): string
    {
        return mb_strlen($text) > self::MAX_EXAMPLE_LENGTH
            ? mb_substr($text, 0, self::MAX_EXAMPLE_LENGTH - 1) . '…'
            : $text;
    }
}
