<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Cron;

use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Discovery\CandidateRepository;
use Panth\AiVisibility\Service\SourceEvidence;
use Psr\Log\LoggerInterface;

/**
 * Hourly evidence scoring of new AI referrer source candidates (skipped when discovery is off), and
 * retirement of bot candidates whose User-Agent is an ordinary browser (marked "not AI").
 */
class Discovery
{
    /**
     * @param Config $config
     * @param SourceEvidence $sourceEvidence
     * @param LoggerInterface $logger
     * @param CandidateRepository|null $candidates Set in di.xml
     */
    public function __construct(
        private readonly Config $config,
        private readonly SourceEvidence $sourceEvidence,
        private readonly LoggerInterface $logger,
        private readonly ?CandidateRepository $candidates = null
    ) {
    }

    /**
     * Cron entry point
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->discoveryEnabled()) {
            return;
        }
        try {
            $this->sourceEvidence->update();
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] source candidate evidence failed: ' . $e->getMessage());
        }
        try {
            $this->candidates?->retireBrowserCandidates();
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] browser candidates could not be retired: ' . $e->getMessage());
        }
    }
}
