<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

use Panth\AiVisibility\Model\HealthResult;

/**
 * One data-health self-check.
 */
interface HealthCheckInterface
{
    /**
     * Stable code (stored in panth_aiv_health.check_code)
     *
     * @return string
     */
    public function code(): string;

    /**
     * Admin label
     *
     * @return string
     */
    public function label(): string;

    /**
     * Run the check; must not throw
     *
     * @return HealthResult
     */
    public function run(): HealthResult;
}
