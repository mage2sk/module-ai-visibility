<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

use Panth\AiVisibility\Model\Finding;

/**
 * One recommendation rule (injected into the engine through the di.xml argument "rules").
 *
 * Rules only read data. They ignore findings below Config::recommendationsMinVolume() and never throw for
 * missing optional modules or empty tables (they return [] instead).
 */
interface RecommendationRuleInterface
{
    /**
     * Stable rule code stored in panth_aiv_recommendation.rule_code (max 32), e.g. "bot_404"
     *
     * @return string
     */
    public function code(): string;

    /**
     * Admin label of the rule
     *
     * @return string
     */
    public function label(): string;

    /**
     * Metric used to verify an applied fix (MetricProviderInterface::METRIC_*)
     *
     * @return string
     */
    public function metric(): string;

    /**
     * Find problems for one store view as of a UTC day
     *
     * @param int $storeId Store view ID
     * @param \DateTimeImmutable $today UTC day the evaluation runs for (time part ignored)
     * @return Finding[]
     */
    public function evaluate(int $storeId, \DateTimeImmutable $today): array;
}
