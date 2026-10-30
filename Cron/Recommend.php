<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Cron;

use Panth\AiVisibility\Api\RecommendationEngineInterface;
use Panth\AiVisibility\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Runs the recommendation engine once a day (cron job panth_aiv_recommend).
 *
 * Does nothing while recommendations/enabled is off. Never throws: a failure is logged as a warning.
 */
class Recommend
{
    /**
     * @param RecommendationEngineInterface $engine
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RecommendationEngineInterface $engine,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run the job; never throws
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            if (!$this->config->recommendationsEnabled()) {
                return;
            }
            // Failing rules and store views are logged one by one by the engine; their open
            // recommendations are kept. One summary line makes a partial run visible in the log.
            $errors = (array)($this->engine->run()['errors'] ?? []);
            if ($errors !== []) {
                $this->logger->warning(sprintf(
                    'Panth AI Visibility: recommendation cron finished with %d failed rule(s) or store view(s): %s',
                    count($errors),
                    implode('; ', array_map('strval', $errors))
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth AI Visibility: recommendation cron failed: ' . $e->getMessage());
        }
    }
}
