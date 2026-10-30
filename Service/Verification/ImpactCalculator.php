<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Verification;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Retention;
use Psr\Log\LoggerInterface;

/**
 * Verifies applied actions: before / after windows of W days around the applied UTC day (the day itself
 * is excluded), compared with matched control subjects.
 *
 * - Site actions (method before_after): Poisson rate ratio of the site value after vs before, exposure =
 *   days (counts), the denominator (ratio metrics) or 1 for the end-of-window backlog snapshot.
 * - Site average response time (method welch_t): an average is not a count, so each UTC day's mean is one
 *   observation; Welch t-test on the log of the daily means after vs before, so effect and range are the
 *   ratio of the geometric means of the daily averages (at least MIN_DAYS days with data per window).
 * - Other actions (method did): difference-in-differences ratio of the treated subjects against up to
 *   verify/max_controls untreated subjects of the same type and store view that had the metric, matched
 *   nearest-neighbour without replacement on their values in BOTH the pre window [applied - 2W,
 *   applied - W - 1] and the before window (details.match_window 'pre+before', details.pre_window).
 *   The rules pick subjects by how extreme they were just before the change, so treated subjects fall
 *   back towards their usual level afterwards; controls alike in both windows fall back the same way.
 *   Matching on the before window alone inflates null effects of randomly chosen subjects (lucky
 *   controls regress), matching on the pre window alone fakes effects for extreme-selected subjects.
 *   Without the pre-window history (raw data past retention, or a daily summary with nothing in the
 *   HISTORY_LOOKBACK_DAYS up to the first pre day, e.g. right after the 1.0 to 1.1 upgrade) the verdict
 *   is insufficient (comparison_history_not_available_yet), never a before-window-only match. 95%
 *   interval by a bootstrap over subjects seeded with the action ID (same data, same answer).
 *   Caliper: a pair counts only when the control is within CALIPER of ln(1 + value) of its treated subject
 *   in EVERY matched window (about 1.65x), so no pair needs extrapolation; with fewer than 3 such pairs
 *   (e.g. pages that only started erroring at the before window while no other page did) the verdict is
 *   insufficient (no_comparable_subjects). A pre-baseline cross-check (controls matched on the before
 *   window, caliper too) must agree: the stored range is the union of both ranges (details.decided_by
 *   'both'). When the cross-check is undefined (treated had nothing in the pre window) the main estimate
 *   decides alone ('main_only'), which needs controls that also had (almost) nothing then.
 *   No regression model adjusts the pairs: calibration showed it extrapolates for recent-onset subjects.
 * Verdict: insufficient when the treated subjects had fewer than verify/min_events events over both
 * windows, when there are no treated subjects (site) / fewer than 3 treated or 3 control subjects
 * (multi-subject), when the ratio is undefined (nothing before) or when the raw data a metric needs is
 * older than its retention (raw tables in days, daily summary tables in months). Otherwise "helped" when the whole interval is beyond 1.05 (0.95 for metrics
 * where less is better), "worse" when it is beyond the opposite bound, else "no_effect".
 * - Never-fetched products / categories (crawl count metric, nothing before for every treated subject;
 *   method coverage): share of treated subjects fetched at least once in the after window vs the
 *   baseline share of other catalogue entities (live URL, not fetched in the NEVER_CRAWLED_DAYS before the
 *   before window) that got a first fetch in the before window [applied - W, applied - 1]. 95% Newcombe
 *   interval of the difference; helped when it is entirely above 0, otherwise no clear effect (never
 *   fetched subjects cannot get worse; details.coverage.below_baseline marks a share below the baseline). Stored effect / range
 *   are relative to the baseline share (1.0 = same share); the difference itself is in details.
 * W is the window the action was logged with (verify_after - applied day - 1). Verifying before
 * verify_after uses the after days so far and leaves the action pending, so the final verdict still
 * comes when the window is complete. Re-verifying replaces the impact row with the same result, except that
 * a final verdict of a verified action is never replaced by "insufficient" (data aged out). The verdict is
 * decided on the stored (4-decimal) bounds. Discovery backlog needs only MIN_ITEM_EVENTS items.
 */
class ImpactCalculator implements ImpactCalculatorInterface
{
    public const MIN_TREATED_MULTI = 3;
    public const MIN_CONTROLS = 3;
    public const MAX_WINDOW_DAYS = 56;
    public const DUE_BATCH = 200;

    public const REASON_TOO_FEW_EVENTS = 'too_few_events';
    public const REASON_TOO_FEW_TREATED = 'too_few_treated_subjects';
    public const REASON_TOO_FEW_CONTROLS = 'too_few_control_subjects';
    public const REASON_NOTHING_BEFORE = 'no_events_before';
    public const REASON_NO_INTERVAL = 'no_stable_interval';
    public const REASON_UNSUPPORTED = 'unsupported_metric_for_subject_type';
    public const REASON_DATA_EXPIRED = 'raw_data_older_than_retention';
    public const REASON_NOT_STARTED = 'after_window_not_started';
    public const REASON_TOO_FEW_DAYS = 'too_few_days_with_data';
    public const REASON_NO_HISTORY = 'comparison_history_not_available_yet';
    public const REASON_NO_COMPARABLE = 'no_comparable_subjects';

    /**
     * Caliper of the control matching: a control must be within this gap of ln(1 + value) of its treated
     * subject in EVERY matched window (0.5 = within about 1.65x), so no pair needs extrapolation
     */
    public const CALIPER = 0.5;

    /**
     * details.match_window of the DiD method: controls are matched on the pre window AND the before window
     */
    public const MATCH_WINDOWS = 'pre+before';

    /**
     * Days up to the first pre-window day in which a daily summary must have data for the pre window to
     * count as available (any subject, the metric's own filter)
     */
    public const HISTORY_LOOKBACK_DAYS = 90;

    /**
     * Days with data each window needs for the daily-means test
     */
    public const MIN_DAYS = 3;

    /**
     * Horizon (days) of "never crawled" for the coverage baseline (same as the never-crawled products rule)
     */
    public const NEVER_CRAWLED_DAYS = 90;

    /**
     * Minimum events for metrics that count items at the end of a window (discovery backlog): a backlog
     * of a few candidates is the whole signal, so verify/min_events (made for hits) would never be reached
     */
    public const MIN_ITEM_EVENTS = 5;

    /**
     * Crawl count metrics the coverage method applies to (more is better)
     */
    public const COVERAGE_METRICS = [
        MetricProviderInterface::METRIC_BOT_HITS,
        MetricProviderInterface::METRIC_SEARCH_BOT_HITS,
        MetricProviderInterface::METRIC_LIVE_FETCHES,
    ];

    private const DECIMAL_14_4 = 9999999999.9999;
    private const DECIMAL_10_4 = 999999.9999;
    private const INT_UNSIGNED = 4294967295;
    private const MAX_LISTED_CONTROLS = 50;

    /**
     * Subject type of the starter rules, used when the recommendation of an action is gone
     */
    private const RULE_SUBJECT_TYPES = [
        'robots_blocks_ai_search' => Finding::SUBJECT_SITE,
        'bot_404' => Finding::SUBJECT_PATH,
        'bot_errors_or_slow' => Finding::SUBJECT_PATH,
        'never_crawled_products' => Finding::SUBJECT_PRODUCT,
        'live_answers_no_sales' => Finding::SUBJECT_PATH,
        'thin_content_ai_read' => Finding::SUBJECT_PRODUCT,
        'llms_not_served' => Finding::SUBJECT_SITE,
        'parameter_crawl_waste' => Finding::SUBJECT_SITE,
        'ai_visitors_out_of_stock' => Finding::SUBJECT_PRODUCT,
        'discovery_backlog' => Finding::SUBJECT_SITE,
    ];

    /**
     * @param ImpactStore $store
     * @param MetricProviderInterface $metrics
     * @param SubjectPool $subjectPool
     * @param ControlMatcher $matcher
     * @param Bootstrap $bootstrap
     * @param PoissonRateTest $poisson
     * @param ProportionDifference $proportions
     * @param WelchTTest $welch
     * @param Config $config
     * @param DateTime $dateTime
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ImpactStore $store,
        private readonly MetricProviderInterface $metrics,
        private readonly SubjectPool $subjectPool,
        private readonly ControlMatcher $matcher,
        private readonly Bootstrap $bootstrap,
        private readonly PoissonRateTest $poisson,
        private readonly ProportionDifference $proportions,
        private readonly WelchTTest $welch,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function verify(int $actionId, bool $dryRun = false): array
    {
        $action = $this->store->getAction($actionId);
        if ($action === null) {
            throw new LocalizedException(__('Action %1 does not exist.', $actionId));
        }
        if (($action['status'] ?? '') === ActionLogInterface::STATUS_UNDONE || !empty($action['undone_at'])) {
            throw new LocalizedException(__('Action %1 was undone and is not verified.', $actionId));
        }
        $now = (int)$this->dateTime->gmtTimestamp();
        $row = $this->compute($action, $now);
        $final = (bool)$row['details']['final'];
        if (($action['status'] ?? '') === ActionLogInterface::STATUS_VERIFIED
            && $row['verdict'] === self::VERDICT_INSUFFICIENT
        ) {
            // A final verdict is never replaced by "not enough data" (e.g. after the data aged out).
            $previous = $this->store->getImpact($actionId);
            if ($previous !== null) {
                $previous['details'] = $this->decodeDetails($previous['details'] ?? null);
                $previous['details']['kept_previous_verdict'] = true;
                return $previous;
            }
            $row['details']['kept_previous_verdict'] = true;
            return $row;
        }
        if (!$dryRun) {
            $stored = $row;
            $stored['details'] = (string)$this->json->serialize($row['details']);
            $this->store->saveImpact($stored);
            if ($final) {
                $this->store->setActionStatus(
                    $actionId,
                    $row['verdict'] === self::VERDICT_INSUFFICIENT
                        ? ActionLogInterface::STATUS_INSUFFICIENT
                        : ActionLogInterface::STATUS_VERIFIED
                );
            }
        }
        return $row;
    }

    /**
     * @inheritdoc
     */
    public function verifyDue(bool $dryRun = false): int
    {
        $today = gmdate('Y-m-d', (int)$this->dateTime->gmtTimestamp());
        $done = 0;
        foreach ($this->store->dueActionIds($today, self::DUE_BATCH) as $actionId) {
            try {
                $this->verify($actionId, $dryRun);
                $done++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf('Panth AI Visibility: verifying action %d failed: %s', $actionId, $e->getMessage())
                );
            }
        }
        return $done;
    }

    /**
     * Verdict for a 95% interval of the effect ratio
     *
     * @param bool $lessIsBetter
     * @param float $low
     * @param float $high
     * @return string helped | worse | no_effect
     */
    public static function verdict(bool $lessIsBetter, float $low, float $high): string
    {
        if ($lessIsBetter) {
            if ($high < self::HELPED_BELOW) {
                return self::VERDICT_HELPED;
            }
            return $low > self::HELPED_ABOVE ? self::VERDICT_WORSE : self::VERDICT_NO_EFFECT;
        }
        if ($low > self::HELPED_ABOVE) {
            return self::VERDICT_HELPED;
        }
        return $high < self::HELPED_BELOW ? self::VERDICT_WORSE : self::VERDICT_NO_EFFECT;
    }

    /**
     * Minimum events for a metric: verify/min_events, at most MIN_ITEM_EVENTS for item-count metrics
     *
     * @param string $metric
     * @param int $configured
     * @return int
     */
    public static function minEvents(string $metric, int $configured): int
    {
        return $metric === MetricProviderInterface::METRIC_CANDIDATE_BACKLOG
            ? min($configured, self::MIN_ITEM_EVENTS)
            : $configured;
    }

    /**
     * Subject type of an action: the recommendation's, else the rule's, else guessed from the refs
     *
     * @param array $action
     * @param string[] $subjects
     * @return string
     */
    public static function subjectType(array $action, array $subjects): string
    {
        $type = (string)($action['rec_subject_type'] ?? '');
        if (in_array($type, Finding::SUBJECT_TYPES, true)) {
            return $type;
        }
        $rule = (string)($action['rule_code'] ?? '');
        if (isset(self::RULE_SUBJECT_TYPES[$rule])) {
            return self::RULE_SUBJECT_TYPES[$rule];
        }
        if ($subjects === [] || $subjects === [Finding::SUBJECT_SITE]) {
            return Finding::SUBJECT_SITE;
        }
        [$guess] = MetricProvider::parseRef((string)$subjects[0], (string)($action['metric'] ?? ''));
        return $guess;
    }

    /**
     * Compute the impact row of an action at a point in time
     *
     * @param array $action
     * @param int $now Unix time
     * @return array
     */
    private function compute(array $action, int $now): array
    {
        $actionId = (int)$action['action_id'];
        $metric = (string)($action['metric'] ?? '');
        $storeId = (int)($action['store_id'] ?? 0);
        $simulated = !empty($action['is_simulated']);
        $subjects = $this->subjects($action['subjects'] ?? null);
        $type = self::subjectType($action, $subjects);

        $appliedTs = (int)strtotime(substr((string)$action['applied_at'], 0, 10) . ' 00:00:00 UTC');
        $window = $this->window($appliedTs, (string)($action['verify_after'] ?? ''));
        $todayTs = (int)strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC');
        $before = [self::day($appliedTs - $window * 86400), self::day($appliedTs - 86400)];
        $afterEndTs = min($appliedTs + $window * 86400, $todayTs - 86400);
        $after = [self::day($appliedTs + 86400), self::day($afterEndTs)];
        $afterDays = (int)round(($afterEndTs - $appliedTs) / 86400);

        $lessIsBetter = in_array($metric, MetricProviderInterface::LESS_IS_BETTER, true);
        $site = $type === Finding::SUBJECT_SITE;
        $row = [
            'action_id' => $actionId,
            'window_days' => $window,
            'method' => $site ? self::METHOD_BEFORE_AFTER : self::METHOD_DID,
            'treat_before' => 0.0,
            'treat_after' => 0.0,
            'control_before' => null,
            'control_after' => null,
            'effect' => null,
            'ci_low' => null,
            'ci_high' => null,
            'events' => 0,
            'subjects_treated' => $site ? ($subjects === [] ? 0 : 1) : count($subjects),
            'subjects_control' => 0,
            'verdict' => self::VERDICT_INSUFFICIENT,
            'details' => [
                'reason' => null,
                'metric' => $metric,
                'subject_type' => $type,
                'direction' => $lessIsBetter ? 'less_is_better' : 'more_is_better',
                'before' => $before,
                'after' => $afterDays >= 1 ? $after : null,
                'after_days' => max(0, $afterDays),
                'final' => $todayTs >= $appliedTs + ($window + 1) * 86400,
                'min_events' => self::minEvents($metric, $this->config->verifyMinEvents()),
            ],
            'computed_at' => gmdate('Y-m-d H:i:s', $now),
            'is_simulated' => $simulated ? 1 : 0,
        ];

        if (!$this->metrics->supports($metric) || MetricProvider::sources($metric, $type) === []) {
            return $this->insufficient($row, self::REASON_UNSUPPORTED);
        }
        if ($afterDays < 1) {
            return $this->insufficient($row, self::REASON_NOT_STARTED);
        }
        $expired = $this->expiredSource($metric, $type, $before[0], $todayTs);
        if ($expired !== null) {
            $row['details']['retention_days'] = $expired;
            return $this->insufficient($row, self::REASON_DATA_EXPIRED);
        }
        if ($site) {
            return $this->beforeAfter($row, $metric, $storeId, $before, $after, $afterDays, $simulated, $subjects);
        }
        return $this->differenceInDifferences(
            $row,
            [
                'metric' => $metric,
                'type' => $type,
                'store' => $storeId,
                'sim' => $simulated,
                'seed' => $actionId,
                'today' => $todayTs,
            ],
            $before,
            $after,
            $subjects
        );
    }

    /**
     * Single-subject (site) verification with the Poisson rate ratio
     *
     * @param array $row
     * @param string $metric
     * @param int $storeId
     * @param string[] $before
     * @param string[] $after
     * @param int $afterDays
     * @param bool $simulated
     * @param string[] $subjects
     * @return array
     */
    private function beforeAfter(
        array $row,
        string $metric,
        int $storeId,
        array $before,
        array $after,
        int $afterDays,
        bool $simulated,
        array $subjects
    ): array {
        if ($subjects === []) {
            return $this->insufficient($row, self::REASON_TOO_FEW_TREATED);
        }
        $ratio = MetricProvider::isRatio($metric);
        $site = [Finding::SUBJECT_SITE];
        $b = $this->metrics->value($metric, $storeId, $site, $before[0], $before[1], $simulated)['site']
            ?? ['num' => 0.0, 'den' => $ratio ? 0.0 : 1.0];
        $a = $this->metrics->value($metric, $storeId, $site, $after[0], $after[1], $simulated)['site']
            ?? ['num' => 0.0, 'den' => $ratio ? 0.0 : 1.0];
        $row['treat_before'] = self::clamp($ratio ? self::div($b['num'], $b['den']) : $b['num'], self::DECIMAL_14_4);
        $row['treat_after'] = self::clamp($ratio ? self::div($a['num'], $a['den']) : $a['num'], self::DECIMAL_14_4);
        $events = $ratio ? $a['den'] + $b['den'] : $a['num'] + $b['num'];
        $row['events'] = (int)min(self::INT_UNSIGNED, round($events));

        if ($events < $row['details']['min_events']) {
            return $this->insufficient($row, self::REASON_TOO_FEW_EVENTS);
        }
        if ($metric === MetricProviderInterface::METRIC_BOT_AVG_MS) {
            // An average response time is not a Poisson count: compare the daily means instead.
            return $this->dailyMeans($row, $storeId, $before, $after, $simulated);
        }
        if ($ratio) {
            $test = $this->poisson->rateRatio($a['num'], $a['den'], $b['num'], $b['den']);
        } elseif ($metric === MetricProviderInterface::METRIC_CANDIDATE_BACKLOG) {
            $test = $this->poisson->rateRatio($a['num'], 1.0, $b['num'], 1.0);
        } else {
            $test = $this->poisson->rateRatio($a['num'], (float)$afterDays, $b['num'], (float)$row['window_days']);
        }
        if ($test === null) {
            return $this->insufficient($row, self::REASON_NOTHING_BEFORE);
        }
        $row['details']['ci_method'] = 'poisson_' . $test['method'];
        return $this->decide($row, $test['ratio'], $test['low'], $test['high']);
    }

    /**
     * Site average response time: Welch t-test on the log of the daily means
     *
     * @param array $row
     * @param int $storeId
     * @param string[] $before
     * @param string[] $after
     * @param bool $simulated
     * @return array
     */
    private function dailyMeans(array $row, int $storeId, array $before, array $after, bool $simulated): array
    {
        $row['method'] = self::METHOD_DAILY_MEANS;
        $series = [];
        foreach (['before' => $before, 'after' => $after] as $name => $range) {
            $series[$name] = [];
            $end = (int)strtotime($range[1] . ' 00:00:00 UTC');
            for ($ts = (int)strtotime($range[0] . ' 00:00:00 UTC'); $ts <= $end; $ts += 86400) {
                $day = self::day($ts);
                $value = $this->metrics->value(
                    MetricProviderInterface::METRIC_BOT_AVG_MS,
                    $storeId,
                    [Finding::SUBJECT_SITE],
                    $day,
                    $day,
                    $simulated
                )[Finding::SUBJECT_SITE] ?? null;
                $num = (float)($value['num'] ?? 0.0);
                $den = (float)($value['den'] ?? 0.0);
                if ($num > 0.0 && $den > 0.0) {
                    $series[$name][] = log($num / $den);
                }
            }
        }
        $row['details']['days_before'] = count($series['before']);
        $row['details']['days_after'] = count($series['after']);
        if (count($series['before']) < self::MIN_DAYS || count($series['after']) < self::MIN_DAYS) {
            return $this->insufficient($row, self::REASON_TOO_FEW_DAYS);
        }
        $test = $this->welch->interval($series['after'], $series['before']);
        if ($test === null) {
            return $this->insufficient($row, self::REASON_NO_INTERVAL);
        }
        $row['details']['ci_method'] = 'welch_t_log_daily_means';
        $row['details']['df'] = round($test['df'], 2);
        return $this->decide($row, exp($test['diff']), exp($test['low']), exp($test['high']));
    }

    /**
     * Multi-subject verification: DiD ratio against matched controls with a bootstrap interval
     *
     * @param array $row
     * @param array $ctx metric, type, store, sim, seed
     * @param string[] $before
     * @param string[] $after
     * @param string[] $subjects
     * @return array
     */
    private function differenceInDifferences(
        array $row,
        array $ctx,
        array $before,
        array $after,
        array $subjects
    ): array {
        $metric = $ctx['metric'];
        $ratio = MetricProvider::isRatio($metric);
        $typed = [];
        foreach ($subjects as $subject) {
            $typed[MetricProvider::typedRef($ctx['type'], $subject)] = $subject;
        }
        $treatedRefs = array_map('strval', array_keys($typed));
        $treatedBefore = $this->values($ctx, $treatedRefs, $before);
        $treatedAfter = $this->values($ctx, $treatedRefs, $after);
        if (self::usesCoverage($metric, $ctx['type'], $treatedBefore)) {
            return $this->coverage($row, $ctx, $before, $treatedRefs, $treatedAfter);
        }

        // Controls are matched on the window before the before window AND on the before window: the rules
        // pick subjects by how extreme they were just before the change, so only controls that were alike
        // in both windows fall back to their usual level like the treated subjects do afterwards.
        $beforeTs = (int)strtotime($before[0] . ' 00:00:00 UTC');
        $window = (int)$row['window_days'];
        $pre = [self::day($beforeTs - $window * 86400), self::day($beforeTs - 86400)];
        $row['details']['match_window'] = self::MATCH_WINDOWS;
        $row['details']['pre_window'] = $pre;
        $history = $this->hasHistory($ctx, $pre);
        $match = $history
            ? $this->matchControls($ctx, $typed, $treatedRefs, $pre, $before, $treatedBefore)
            : ['pairs' => [], 'before_pairs' => [], 'pre' => [], 'before' => [], 'eligible' => 0];
        $poolAfter = $match['before'] === []
            ? []
            : $this->values($ctx, array_map('strval', array_keys($match['before'])), $after);

        $treatedRows = [];
        $events = 0.0;
        foreach ($treatedRefs as $ref) {
            $treatedRows[$ref] = self::subjectRow($treatedBefore[$ref] ?? null, $treatedAfter[$ref] ?? null, $ratio);
            $events += $ratio
                ? (float)($treatedBefore[$ref]['den'] ?? 0) + (float)($treatedAfter[$ref]['den'] ?? 0)
                : (float)($treatedBefore[$ref]['num'] ?? 0) + (float)($treatedAfter[$ref]['num'] ?? 0);
        }
        $controls = array_column($match['pairs'], 0);
        $controlRows = [];
        foreach ($controls as $ref) {
            $controlRows[] = self::subjectRow($match['before'][$ref] ?? null, $poolAfter[$ref] ?? null, $ratio);
        }
        $row['details']['caliper'] = self::CALIPER;
        $treatedRows = array_values($treatedRows);
        [$tBefore, $tAfter] = self::pooled($treatedRows);
        // Every treated subject had nothing after: effect and range are both -100%, worded as such in the UI.
        $row['details']['treated_after_zero'] = $tBefore > 0.0 && $tAfter <= 0.0;
        $row['treat_before'] = self::clamp($ratio ? $tBefore : $tBefore * count($treatedRows), self::DECIMAL_14_4);
        $row['treat_after'] = self::clamp($ratio ? $tAfter : $tAfter * count($treatedRows), self::DECIMAL_14_4);
        if ($controlRows !== []) {
            [$cBefore, $cAfter] = self::pooled($controlRows);
            $row['control_before'] = self::clamp($ratio ? $cBefore : $cBefore * count($controlRows), self::DECIMAL_14_4);
            $row['control_after'] = self::clamp($ratio ? $cAfter : $cAfter * count($controlRows), self::DECIMAL_14_4);
        }
        $row['events'] = (int)min(self::INT_UNSIGNED, round($events));
        $row['subjects_control'] = count($controls);
        $row['details']['controls'] = array_slice(
            array_map(static fn ($ref): string => (string)$ref, $controls),
            0,
            self::MAX_LISTED_CONTROLS
        );

        if (count($treatedRows) < self::MIN_TREATED_MULTI) {
            return $this->insufficient($row, self::REASON_TOO_FEW_TREATED);
        }
        if (!$history) {
            // Never match on the before window alone (see the class comment): wait for the history.
            return $this->insufficient($row, self::REASON_NO_HISTORY);
        }
        if ($tBefore <= 0.0) {
            // A ratio against nothing is undefined, whatever the controls.
            return $this->insufficient($row, self::REASON_NOTHING_BEFORE);
        }
        if (count($controlRows) < self::MIN_CONTROLS) {
            // Candidates exist but too few are alike in both windows: no comparison without extrapolating.
            return $this->insufficient(
                $row,
                $match['eligible'] >= self::MIN_CONTROLS ? self::REASON_NO_COMPARABLE : self::REASON_TOO_FEW_CONTROLS
            );
        }
        if ($events < $this->config->verifyMinEvents()) {
            return $this->insufficient($row, self::REASON_TOO_FEW_EVENTS);
        }
        $effect = $this->bootstrap->ratio($treatedRows, $controlRows);
        if ($effect === null) {
            return $this->insufficient($row, self::REASON_NOTHING_BEFORE);
        }
        $interval = $this->bootstrap->interval(
            $treatedRows,
            $controlRows,
            $ctx['seed'],
            self::BOOTSTRAP_RESAMPLES
        );
        $row['details']['ci_method'] = 'bootstrap';
        $row['details']['seed'] = $ctx['seed'];
        $row['details']['resamples'] = self::BOOTSTRAP_RESAMPLES;
        if ($interval === null) {
            $row['effect'] = self::clamp($effect, self::DECIMAL_10_4);
            return $this->insufficient($row, self::REASON_NO_INTERVAL);
        }
        $row['details']['valid_resamples'] = $interval['valid'];
        $low = $interval['low'];
        $high = $interval['high'];
        $check = $this->preBaselineCheck($match, $poolAfter, $treatedRefs, $treatedAfter, $ratio, $ctx['seed']);
        $row['details']['cross_check'] = $check;
        $row['details']['decided_by'] = $check !== null ? 'both' : 'main_only';
        if ($check !== null) {
            // Both identifications must agree: the reported range covers both 95% ranges.
            $row['details']['ci_method'] = 'bootstrap_union';
            $low = min($low, $check['ci_low']);
            $high = max($high, $check['ci_high']);
        }
        return $this->decide($row, $effect, $low, $high);
    }

    /**
     * Cross-check of the DiD: the pre window as baseline, controls matched on the before window only
     *
     * The main estimate (controls alike, within the caliper, in the pre AND the before window) is right when
     * the subjects were picked on what the windows show (the rules); it cannot see a pick on something the
     * windows do not show, e.g. a store's best sellers: a control with the same counts is then on average
     * less popular and falls back. This check is right in that case and whenever the pick only used days
     * of the before window (pre and after values are then untouched by it), wrong when the pick also used
     * the pre window (30-day rules). The verdict needs both, so the stored range is the union of the two
     * 95% ranges. Null when the check has fewer than 3 pairs within the caliper or no defined ratio or
     * interval (then the main estimate decides alone; its pairs are alike in the pre window by the caliper).
     *
     * @param array $match matchControls() result
     * @param array $poolAfter Candidate ref => after value
     * @param string[] $treatedRefs
     * @param array $treatedAfter Treated ref => after value
     * @param bool $ratio
     * @param int $seed
     * @return array|null [method, effect, ci_low, ci_high, controls]
     */
    private function preBaselineCheck(
        array $match,
        array $poolAfter,
        array $treatedRefs,
        array $treatedAfter,
        bool $ratio,
        int $seed
    ): ?array {
        if (count($match['before_pairs']) < self::MIN_CONTROLS) {
            return null;
        }
        $treated = [];
        foreach ($treatedRefs as $ref) {
            $treated[] = self::subjectRow($match['pre'][$ref] ?? null, $treatedAfter[$ref] ?? null, $ratio);
        }
        $controls = [];
        foreach ($match['before_pairs'] as [$control]) {
            $controls[] = self::subjectRow($match['pre'][$control] ?? null, $poolAfter[$control] ?? null, $ratio);
        }
        $effect = $this->bootstrap->ratio($treated, $controls);
        if ($effect === null) {
            return null;
        }
        $interval = $this->bootstrap->interval($treated, $controls, $seed, self::BOOTSTRAP_RESAMPLES);
        if ($interval === null) {
            return null;
        }
        return [
            'method' => 'pre_baseline_before_matched',
            'effect' => self::clamp($effect, self::DECIMAL_10_4),
            'ci_low' => self::clamp($interval['low'], self::DECIMAL_10_4),
            'ci_high' => self::clamp($interval['high'], self::DECIMAL_10_4),
            'controls' => count($controls),
        ];
    }

    /**
     * Whether the comparison history (the pre window) exists for the metric
     *
     * Not when a raw table it needs no longer covers the pre window, nor when a daily summary it reads
     * has nothing for any subject in the HISTORY_LOOKBACK_DAYS up to the first pre day (the store started
     * collecting, or the 1.0 to 1.1 upgrade rebuilt fewer days, after the pre window began).
     *
     * @param array $ctx metric, type, store, sim, today
     * @param string[] $pre [from, to]
     * @return bool
     */
    private function hasHistory(array $ctx, array $pre): bool
    {
        if ($this->expiredSource($ctx['metric'], $ctx['type'], $pre[0], $ctx['today']) !== null) {
            return false;
        }
        $sources = MetricProvider::sources($ctx['metric'], $ctx['type']);
        if (array_intersect($sources, MetricProvider::DAILY_SOURCES) === []) {
            return true;
        }
        $preTs = (int)strtotime($pre[0] . ' 00:00:00 UTC');
        return $this->subjectPool->candidates(
            $ctx['metric'],
            $ctx['type'],
            $ctx['store'],
            self::day($preTs - self::HISTORY_LOOKBACK_DAYS * 86400),
            $pre[0],
            $ctx['sim'],
            1
        ) !== [];
    }

    /**
     * Controls matched on the pre and the before window together, closest over both windows first
     *
     * Candidates are the subjects with events in either window (each list bounded by SubjectPool).
     *
     * @param array $ctx metric, type, store, sim
     * @param array<string, string> $typed Treated typed ref => stored ref
     * @param string[] $treatedRefs
     * @param string[] $pre [from, to]
     * @param string[] $before [from, to]
     * @param array $treatedBefore Treated values of the before window
     * @return array{pairs: array, pre: array, before: array} Matched [control, treated] pairs, pre values of
     *         the treated and candidate subjects, before values of the candidates
     */
    private function matchControls(
        array $ctx,
        array $typed,
        array $treatedRefs,
        array $pre,
        array $before,
        array $treatedBefore
    ): array {
        $none = ['pairs' => [], 'before_pairs' => [], 'pre' => [], 'before' => [], 'eligible' => 0];
        $pool = [];
        foreach ([$pre, $before] as $range) {
            foreach ($this->subjectPool->candidates(
                $ctx['metric'],
                $ctx['type'],
                $ctx['store'],
                $range[0],
                $range[1],
                $ctx['sim']
            ) as $candidate) {
                $ref = MetricProvider::typedRef($ctx['type'], (string)$candidate);
                if (!isset($typed[$ref])) {
                    $pool[$ref] = true;
                }
            }
        }
        if ($pool === []) {
            return $none;
        }
        $pool = array_map('strval', array_keys($pool));
        $ratio = MetricProvider::isRatio($ctx['metric']);
        $empty = ['num' => 0.0, 'den' => $ratio ? 0.0 : 1.0];
        $treatedPre = $this->values($ctx, $treatedRefs, $pre);
        $poolPre = $this->values($ctx, $pool, $pre);
        $poolBefore = $this->values($ctx, $pool, $before);
        $treatedWindows = [[], []];
        $preValues = [];
        $beforeValues = [];
        foreach ($treatedRefs as $ref) {
            $treatedWindows[0][$ref] = $treatedPre[$ref] ?? $empty;
            $treatedWindows[1][$ref] = $treatedBefore[$ref] ?? $empty;
            $preValues[$ref] = $treatedWindows[0][$ref];
        }
        $eligible = 0;
        foreach ($pool as $ref) {
            $preValues[$ref] = $poolPre[$ref] ?? $empty;
            $beforeValues[$ref] = $poolBefore[$ref] ?? $empty;
            $axis = $ratio ? 'den' : 'num';
            $eligible += (float)$preValues[$ref][$axis] + (float)$beforeValues[$ref][$axis] > 0.0 ? 1 : 0;
        }
        return [
            'pairs' => $this->matcher->matchPairs(
                $treatedWindows,
                [$poolPre, $poolBefore],
                $this->config->verifyMaxControls(),
                $ratio,
                self::CALIPER
            ),
            'before_pairs' => $this->matcher->matchPairs(
                [$treatedWindows[1]],
                [$poolBefore],
                $this->config->verifyMaxControls(),
                $ratio,
                self::CALIPER
            ),
            'pre' => $preValues,
            'before' => $beforeValues,
            'eligible' => $eligible,
        ];
    }

    /**
     * Whether an action is verified with the coverage method: crawl counts of products / categories that
     * all had nothing in the before window (a ratio against zero is undefined)
     *
     * @param string $metric
     * @param string $type
     * @param array $treatedBefore ref => [num, den]
     * @return bool
     */
    public static function usesCoverage(string $metric, string $type, array $treatedBefore): bool
    {
        if (!in_array($metric, self::COVERAGE_METRICS, true)
            || !in_array($type, [Finding::SUBJECT_PRODUCT, Finding::SUBJECT_CATEGORY], true)
        ) {
            return false;
        }
        foreach ($treatedBefore as $value) {
            if ((float)($value['num'] ?? 0.0) > 0.0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Coverage verification: treated share fetched after vs the baseline share of first fetches before
     *
     * @param array $row
     * @param array $ctx metric, type, store, sim, seed
     * @param string[] $before [applied - W, applied - 1]
     * @param string[] $treatedRefs Typed refs
     * @param array $treatedAfter ref => [num, den]
     * @return array
     */
    private function coverage(array $row, array $ctx, array $before, array $treatedRefs, array $treatedAfter): array
    {
        $beforeTs = (int)strtotime($before[0] . ' 00:00:00 UTC');
        // Baseline = never crawled over the never-crawled rule's horizon, like the treated products.
        $lookback = [self::day($beforeTs - self::NEVER_CRAWLED_DAYS * 86400), self::day($beforeTs - 86400)];
        $ids = [];
        $fetched = 0;
        foreach ($treatedRefs as $ref) {
            [, $key] = MetricProvider::parseRef($ref, $ctx['metric']);
            $ids[] = (int)$key;
            if ((float)($treatedAfter[$ref]['num'] ?? 0.0) > 0.0) {
                $fetched++;
            }
        }
        $treated = count($treatedRefs);
        $baseline = $this->subjectPool->coverageBaseline(
            $ctx['metric'],
            $ctx['type'],
            $ctx['store'],
            $ids,
            $lookback,
            $before,
            $ctx['sim']
        );
        $baseN = max(0, (int)$baseline['n']);
        $baseX = min($baseN, max(0, (int)$baseline['x']));
        $p1 = $treated > 0 ? $fetched / $treated : 0.0;
        $p0 = $baseN > 0 ? $baseX / $baseN : 0.0;

        $row['method'] = self::METHOD_COVERAGE;
        $row['treat_before'] = 0.0;
        $row['treat_after'] = self::clamp($p1, self::DECIMAL_14_4);
        $row['control_before'] = null;
        $row['control_after'] = $baseN > 0 ? self::clamp($p0, self::DECIMAL_14_4) : null;
        $row['subjects_control'] = (int)min(self::INT_UNSIGNED, $baseN);
        $row['events'] = (int)min(self::INT_UNSIGNED, $treated + $baseN);
        $row['details']['coverage'] = [
            'treated_fetched' => $fetched,
            'treated' => $treated,
            'baseline_fetched' => $baseX,
            'baseline' => $baseN,
            'baseline_lookback' => $lookback,
            'baseline_window' => $before,
        ];

        if ($treated < self::MIN_TREATED_MULTI) {
            return $this->insufficient($row, self::REASON_TOO_FEW_TREATED);
        }
        if ($baseN < self::MIN_CONTROLS) {
            return $this->insufficient($row, self::REASON_TOO_FEW_CONTROLS);
        }
        if ($treated + $baseN < $this->config->verifyMinEvents()) {
            return $this->insufficient($row, self::REASON_TOO_FEW_EVENTS);
        }
        $test = $this->proportions->newcombe($fetched, $treated, $baseX, $baseN);
        if ($test === null) {
            return $this->insufficient($row, self::REASON_NO_INTERVAL);
        }
        $row['details']['ci_method'] = 'newcombe_wilson';
        $diffLow = round($test['low'], 6);
        $diffHigh = round($test['high'], 6);
        $row['details']['coverage'] += [
            'diff' => round($test['diff'], 6),
            'diff_low' => $diffLow,
            'diff_high' => $diffHigh,
        ];
        if ($p0 > 0.0) {
            $row['effect'] = self::clamp($p1 / $p0, self::DECIMAL_10_4);
            $row['ci_low'] = self::clamp(max(0.0, ($p0 + $test['low']) / $p0), self::DECIMAL_10_4);
            $row['ci_high'] = self::clamp(max(0.0, ($p0 + $test['high']) / $p0), self::DECIMAL_10_4);
        }
        // Never-crawled subjects cannot be fetched less than not at all, so a share below the baseline
        // means "no clear effect", never "made it worse".
        $row['details']['coverage']['below_baseline'] = $diffHigh < 0.0;
        $row['verdict'] = $diffLow > 0.0 ? self::VERDICT_HELPED : self::VERDICT_NO_EFFECT;
        return $row;
    }

    /**
     * Metric values with the action's store and simulation flag
     *
     * @param array $ctx
     * @param string[] $refs
     * @param string[] $range
     * @return array
     */
    private function values(array $ctx, array $refs, array $range): array
    {
        $values = $this->metrics->value($ctx['metric'], $ctx['store'], $refs, $range[0], $range[1], $ctx['sim']);
        $out = [];
        foreach ($values as $ref => $value) {
            $out[(string)$ref] = $value;
        }
        return $out;
    }

    /**
     * Fill effect, interval and verdict
     *
     * @param array $row
     * @param float $effect
     * @param float $low
     * @param float $high
     * @return array
     */
    private function decide(array $row, float $effect, float $low, float $high): array
    {
        $row['effect'] = self::clamp($effect, self::DECIMAL_10_4);
        $row['ci_low'] = self::clamp($low, self::DECIMAL_10_4);
        $row['ci_high'] = self::clamp($high, self::DECIMAL_10_4);
        // Decided on the stored (rounded) bounds, so a stored row always supports its own verdict.
        $row['verdict'] = self::verdict(
            $row['details']['direction'] === 'less_is_better',
            (float)$row['ci_low'],
            (float)$row['ci_high']
        );
        return $row;
    }

    /**
     * Mark a row insufficient with a reason
     *
     * @param array $row
     * @param string $reason
     * @return array
     */
    private function insufficient(array $row, string $reason): array
    {
        $row['verdict'] = self::VERDICT_INSUFFICIENT;
        $row['ci_low'] = null;
        $row['ci_high'] = null;
        $row['details']['reason'] = $reason;
        return $row;
    }

    /**
     * Retention (days) of a raw table the metric needs, when the before window starts before it
     *
     * @param string $metric
     * @param string $type
     * @param string $beforeFrom
     * @param int $todayTs
     * @return int|null
     */
    private function expiredSource(string $metric, string $type, string $beforeFrom, int $todayTs): ?int
    {
        $fromTs = (int)strtotime($beforeFrom . ' 00:00:00 UTC');
        foreach (MetricProvider::sources($metric, $type) as $source) {
            $days = match ($source) {
                MetricProvider::SOURCE_RAW_HIT => $this->config->hitRetentionDays(),
                MetricProvider::SOURCE_RAW_VISIT => $this->config->rawVisitRetentionDays(),
                default => 0,
            };
            // Retention removes rows older than N days, so the oldest complete day is today - N + 1.
            if ($days > 0 && $fromTs < $todayTs - ($days - 1) * 86400) {
                return $days;
            }
            if (in_array($source, MetricProvider::DAILY_SOURCES, true)) {
                $months = $this->config->dailyRetentionMonths();
                if ($months > 0) {
                    // Daily rows before this day are deleted by the daily summary retention.
                    $cutoff = Retention::monthsCutoffDay($months, $todayTs);
                    if ($beforeFrom < $cutoff) {
                        return (int)round(($todayTs - (int)strtotime($cutoff . ' 00:00:00 UTC')) / 86400);
                    }
                }
            }
        }
        return null;
    }

    /**
     * Window the action was logged with, else the configured one
     *
     * @param int $appliedTs
     * @param string $verifyAfter
     * @return int
     */
    private function window(int $appliedTs, string $verifyAfter): int
    {
        $ts = strtotime(substr($verifyAfter, 0, 10) . ' 00:00:00 UTC');
        if ($ts !== false) {
            $window = (int)round(($ts - $appliedTs) / 86400) - 1;
            if ($window >= 1 && $window <= self::MAX_WINDOW_DAYS) {
                return $window;
            }
        }
        return $this->config->verifyWindowDays();
    }

    /**
     * Subject refs from the stored JSON (a list, or an object with a "refs" / "subjects" list)
     *
     * @param mixed $raw
     * @return string[]
     */
    private function subjects($raw): array
    {
        $list = [];
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = $this->json->unserialize($raw);
            } catch (\InvalidArgumentException $e) {
                $decoded = [];
            }
            if (is_array($decoded)) {
                $list = $decoded['refs'] ?? $decoded['subjects'] ?? $decoded;
            }
        }
        $out = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (is_scalar($item) && trim((string)$item) !== '') {
                $out[trim((string)$item)] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    /**
     * Details JSON of a stored impact row as an array
     *
     * @param mixed $raw
     * @return array
     */
    private function decodeDetails($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Bootstrap row of one subject
     *
     * @param array|null $before
     * @param array|null $after
     * @param bool $ratio
     * @return array
     */
    private static function subjectRow(?array $before, ?array $after, bool $ratio): array
    {
        $den = $ratio ? 0.0 : 1.0;
        return [
            'bn' => (float)($before['num'] ?? 0.0),
            'bd' => (float)($before['den'] ?? $den),
            'an' => (float)($after['num'] ?? 0.0),
            'ad' => (float)($after['den'] ?? $den),
        ];
    }

    /**
     * Pooled before / after values (Σnum / Σden) of subject rows
     *
     * @param array[] $rows
     * @return float[]
     */
    private static function pooled(array $rows): array
    {
        $bn = $bd = $an = $ad = 0.0;
        foreach ($rows as $row) {
            $bn += $row['bn'];
            $bd += $row['bd'];
            $an += $row['an'];
            $ad += $row['ad'];
        }
        return [self::div($bn, $bd), self::div($an, $ad)];
    }

    /**
     * Division that gives 0 for a zero denominator
     *
     * @param float $num
     * @param float $den
     * @return float
     */
    private static function div(float $num, float $den): float
    {
        return $den > 0.0 ? $num / $den : 0.0;
    }

    /**
     * Round to 4 decimals and keep inside a DECIMAL column
     *
     * @param float $value
     * @param float $max
     * @return float
     */
    private static function clamp(float $value, float $max): float
    {
        if (is_nan($value)) {
            return 0.0;
        }
        return round(max(-$max, min($max, $value)), 4);
    }

    /**
     * 'Y-m-d' of a Unix time
     *
     * @param int $ts
     * @return string
     */
    private static function day(int $ts): string
    {
        return gmdate('Y-m-d', $ts);
    }
}
