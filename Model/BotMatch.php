<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model;

/**
 * A known AI bot, as returned by BotClassifierInterface::classify().
 *
 * $category is one of training | search | live | other (see Model\Source\Category).
 *
 * Immutable value object. Not declared final only because the Magento coding standard
 * forbids final classes; do not extend it.
 */
class BotMatch
{
    /**
     * @param string $code Stable bot code, e.g. "gptbot" (stored in panth_aiv_hit.bot_code)
     * @param string $name Display name
     * @param string $company Operator shown on the dashboard (charts are stacked by company)
     * @param string $category training | search | live | other
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $company,
        public readonly string $category
    ) {
    }
}
