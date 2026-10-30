<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

/**
 * Before / after verification of applied actions (panth_aiv_impact).
 *
 * Multi-subject actions: difference-in-differences ratio against matched control subjects with a
 * deterministic bootstrap CI (seed = action_id). Single-subject (site) actions: Poisson rate ratio.
 * Undone actions are never verified.
 */
interface ImpactCalculatorInterface
{
    public const TABLE = 'panth_aiv_impact';

    public const METHOD_DID = 'did';
    public const METHOD_BEFORE_AFTER = 'before_after';

    /**
     * Never-fetched items (nothing before by definition): share of treated items fetched in the after
     * window vs the share of other never-fetched items that got a first fetch in the before window
     * (two-proportion difference, Newcombe interval); helped when the whole interval is above 0
     */
    public const METHOD_COVERAGE = 'coverage';

    /**
     * Site average response time: Welch t-test across the daily means (log scale) before vs after
     */
    public const METHOD_DAILY_MEANS = 'welch_t';

    public const VERDICT_HELPED = 'helped';
    public const VERDICT_NO_EFFECT = 'no_effect';
    public const VERDICT_WORSE = 'worse';
    public const VERDICT_INSUFFICIENT = 'insufficient';

    public const VERDICTS = [
        self::VERDICT_HELPED,
        self::VERDICT_NO_EFFECT,
        self::VERDICT_WORSE,
        self::VERDICT_INSUFFICIENT,
    ];

    /**
     * Helped when ci_low > HELPED_ABOVE (more-is-better metrics) or ci_high < HELPED_BELOW
     * (less-is-better metrics); worse when the opposite bound crosses the other way
     */
    public const HELPED_ABOVE = 1.05;
    public const HELPED_BELOW = 0.95;

    public const BOOTSTRAP_RESAMPLES = 1000;

    /**
     * Verify one action now and store (upsert) its impact row
     *
     * @param int $actionId
     * @param bool $dryRun Compute only; write nothing
     * @return array Impact row (panth_aiv_impact columns; "details" decoded to an array)
     * @throws \Magento\Framework\Exception\LocalizedException unknown or undone action
     */
    public function verify(int $actionId, bool $dryRun = false): array;

    /**
     * Verify every pending action whose verify_after UTC day has been reached
     *
     * @param bool $dryRun Compute only; write nothing
     * @return int Number of actions verified
     */
    public function verifyDue(bool $dryRun = false): int;
}
