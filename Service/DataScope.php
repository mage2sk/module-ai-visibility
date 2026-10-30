<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

/**
 * Which rows the recommendation rules and the weekly summary read: real rows only (default, always for
 * cron), or real plus simulated rows like the dashboard's "include simulated" switch.
 *
 * Only the CLI previews switch it on (recommend --simulated, which never saves, and email --dry-run
 * --simulated, which never sends), so simulated numbers never reach stored recommendations or e-mails.
 * Shared instance: the command sets it once for the services of the same run.
 */
class DataScope
{
    /**
     * @var bool
     */
    private bool $includeSimulated = false;

    /**
     * Read simulated rows together with real ones (true) or real rows only (false)
     *
     * @param bool $include
     * @return void
     */
    public function includeSimulated(bool $include): void
    {
        $this->includeSimulated = $include;
    }

    /**
     * Whether simulated rows are read as well
     *
     * @return bool
     */
    public function isIncludingSimulated(): bool
    {
        return $this->includeSimulated;
    }

    /**
     * SQL condition on an is_simulated column for the current scope
     *
     * @param string $column Column, optionally with a table alias ("p.is_simulated")
     * @return string
     */
    public function condition(string $column): string
    {
        if (!preg_match('/^([a-z_]+\.)?is_simulated$/', $column)) {
            throw new \InvalidArgumentException('Invalid is_simulated column.');
        }
        return $column . ($this->includeSimulated ? ' IN (0, 1)' : ' = 0');
    }
}
