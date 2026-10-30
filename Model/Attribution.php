<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model;

/**
 * First and last AI touch read from the signed attribution cookie.
 *
 * Times are unix timestamps (UTC).
 *
 * Immutable value object. Not declared final only because the Magento coding standard
 * forbids final classes; do not extend it.
 */
class Attribution
{
    /**
     * @param string $firstSource Source code of the first touch
     * @param int $firstAt Unix time of the first touch
     * @param string $lastSource Source code of the latest touch
     * @param int $lastAt Unix time of the latest touch
     * @param string $landingPath Landing path of the latest touch
     */
    public function __construct(
        public readonly string $firstSource,
        public readonly int $firstAt,
        public readonly string $lastSource,
        public readonly int $lastAt,
        public readonly string $landingPath
    ) {
    }
}
