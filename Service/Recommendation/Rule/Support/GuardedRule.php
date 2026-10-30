<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation\Rule\Support;

use Panth\AiVisibility\Api\RecommendationRuleInterface;
use Panth\AiVisibility\Model\Finding;
use Psr\Log\LoggerInterface;

/**
 * Base of the read-only rules: negative store IDs count as 0 (all store views).
 *
 * A failing query is not swallowed: the exception reaches the engine, which keeps the open recommendations
 * of the rule (a failed run is not "not found again"), logs the failure and reports it in the run's
 * error list (CLI exit code 1, warning in the admin).
 */
abstract class GuardedRule implements RecommendationRuleInterface
{
    /**
     * @param RuleSupport $support
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected readonly RuleSupport $support,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function evaluate(int $storeId, \DateTimeImmutable $today): array
    {
        return $this->find(max(0, $storeId), $today);
    }

    /**
     * Findings of the rule for one store view (0 = all) as of a UTC day
     *
     * @param int $storeId
     * @param \DateTimeImmutable $today
     * @return Finding[]
     */
    abstract protected function find(int $storeId, \DateTimeImmutable $today): array;
}
