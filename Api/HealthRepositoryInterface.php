<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

use Panth\AiVisibility\Model\HealthResult;

/**
 * Stores the latest result of every health check (panth_aiv_health).
 */
interface HealthRepositoryInterface
{
    /**
     * Save (replace) the latest result of a check
     *
     * @param string $code
     * @param HealthResult $result
     * @return void
     */
    public function save(string $code, HealthResult $result): void;

    /**
     * Latest results: code => ['label', 'status', 'message', 'details', 'checked_at']
     *
     * @return array
     */
    public function latest(): array;
}
