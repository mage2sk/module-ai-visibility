<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model;

/**
 * Outcome of one data-health check.
 *
 * Immutable value object. Not declared final only because the Magento coding standard
 * forbids final classes; do not extend it.
 */
class HealthResult
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARN = 'warn';
    public const STATUS_ERROR = 'error';
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * @param string $status ok | warn | error | unknown
     * @param string $message Short, admin-readable summary (max 512 characters are stored)
     * @param array $details Extra data shown on the health card (stored as JSON)
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly array $details = []
    ) {
    }
}
