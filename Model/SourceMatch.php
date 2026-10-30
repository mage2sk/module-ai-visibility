<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model;

/**
 * A known AI referrer source, as returned by SourceClassifierInterface.
 *
 * Immutable value object. Not declared final only because the Magento coding standard
 * forbids final classes; do not extend it.
 */
class SourceMatch
{
    /**
     * @param string $code Stable source code, e.g. "chatgpt" (stored in panth_aiv_visit.source_code)
     * @param string $name Display name
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name
    ) {
    }
}
