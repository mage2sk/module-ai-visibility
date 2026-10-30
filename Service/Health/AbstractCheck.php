<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Health;

use Panth\AiVisibility\Api\HealthCheckInterface;
use Panth\AiVisibility\Model\HealthResult;

/**
 * Base class of the built-in checks: run() never throws.
 */
abstract class AbstractCheck implements HealthCheckInterface
{
    /**
     * @inheritdoc
     */
    public function label(): string
    {
        return HealthRepository::labelFor($this->code());
    }

    /**
     * @inheritdoc
     */
    public function run(): HealthResult
    {
        try {
            return $this->execute();
        } catch (\Throwable $e) {
            return new HealthResult(
                HealthResult::STATUS_UNKNOWN,
                (string)__(
                    'This check could not read the data it needs (%1). Run the checks again; '
                    . 'if it stays, look in var/log/system.log.',
                    mb_substr($e->getMessage(), 0, 200)
                )
            );
        }
    }

    /**
     * The actual check; may throw
     *
     * @return HealthResult
     */
    abstract protected function execute(): HealthResult;

    /**
     * Seconds as a short human text ("3 h 5 min", "12 min", "40 s")
     *
     * @param int $seconds
     * @return string
     */
    protected function ago(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds >= 86400) {
            return (string)__('%1 days', intdiv($seconds, 86400));
        }
        if ($seconds >= 3600) {
            return (string)__('%1 h %2 min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }
        if ($seconds >= 60) {
            return (string)__('%1 min', intdiv($seconds, 60));
        }
        return (string)__('%1 s', $seconds);
    }

    /**
     * Unix timestamp of a 'Y-m-d H:i:s' UTC value, or null
     *
     * @param mixed $value
     * @return int|null
     */
    protected function utcTimestamp($value): ?int
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
