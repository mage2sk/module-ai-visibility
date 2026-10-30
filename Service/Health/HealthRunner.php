<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Health;

use Panth\AiVisibility\Api\HealthCheckInterface;
use Panth\AiVisibility\Api\HealthRepositoryInterface;
use Panth\AiVisibility\Model\HealthResult;
use Psr\Log\LoggerInterface;

/**
 * Runs every data-health check and stores the results.
 *
 * Each check is isolated: an exception (or a broken result) in one check is stored as
 * "unknown" for that check and the remaining checks still run.
 */
class HealthRunner
{
    /**
     * @var HealthCheckInterface[]
     */
    private array $checks = [];

    /**
     * @param HealthRepositoryInterface $repository
     * @param LoggerInterface $logger
     * @param array $checks name => HealthCheckInterface (items of another type are skipped)
     */
    public function __construct(
        private readonly HealthRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        array $checks = []
    ) {
        foreach ($checks as $name => $check) {
            if ($check instanceof HealthCheckInterface) {
                $this->checks[(string)$name] = $check;
            }
        }
    }

    /**
     * Run all checks, save each result and return code => HealthResult
     *
     * @return array<string, HealthResult>
     */
    public function runAll(): array
    {
        $results = [];
        foreach ($this->checks as $name => $check) {
            $code = $this->codeOf($check, $name);
            $result = $this->runOne($check, $code);
            $results[$code] = $result;
            try {
                $this->repository->save($code, $result);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf('[Panth_AiVisibility] health result "%s" could not be saved: %s', $code, $e->getMessage())
                );
            }
        }
        return $results;
    }

    /**
     * Configured checks: name => check
     *
     * @return HealthCheckInterface[]
     */
    public function getChecks(): array
    {
        return $this->checks;
    }

    /**
     * Run a single check and never throw
     *
     * @param HealthCheckInterface $check
     * @param string $code
     * @return HealthResult
     */
    private function runOne(HealthCheckInterface $check, string $code): HealthResult
    {
        try {
            $result = $check->run();
            if (!in_array($result->status, HealthRepository::STATUSES, true)) {
                return new HealthResult(
                    HealthResult::STATUS_UNKNOWN,
                    (string)__('This check returned an unexpected status. Run the checks again; if it stays, '
                        . 'reinstall the module files.')
                );
            }
            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[Panth_AiVisibility] health check "%s" failed: %s', $code, $e->getMessage())
            );
            return new HealthResult(
                HealthResult::STATUS_UNKNOWN,
                (string)__(
                    'This check could not run (%1). The other checks are not affected. '
                    . 'Look in var/log/system.log for details and run the checks again.',
                    mb_substr($e->getMessage(), 0, 200)
                )
            );
        }
    }

    /**
     * Check code, falling back to the configured item name when code() fails
     *
     * @param HealthCheckInterface $check
     * @param string $name
     * @return string
     */
    private function codeOf(HealthCheckInterface $check, string $name): string
    {
        try {
            $code = trim($check->code());
        } catch (\Throwable $e) {
            $code = '';
        }
        if ($code === '') {
            $code = $name;
        }
        return mb_substr($code, 0, 64);
    }
}
