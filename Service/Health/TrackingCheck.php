<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Health;

use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\HealthResult;

/**
 * Warns when the module, crawler tracking or visit tracking is switched off.
 */
class TrackingCheck extends AbstractCheck
{
    public const CODE = 'tracking';

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritdoc
     */
    public function code(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    protected function execute(): HealthResult
    {
        $active = 0;
        $disabled = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            if (!$store->getIsActive()) {
                continue;
            }
            $active++;
            if (!$this->config->isEnabled((int)$store->getId())) {
                $disabled[] = (string)$store->getCode();
            }
        }
        $crawlers = $this->config->trackCrawlers();
        $visits = $this->config->trackVisits();
        $details = [
            'disabled_stores' => $disabled,
            'track_crawlers' => $crawlers,
            'track_visits' => $visits,
            'discovery' => $this->config->discoveryEnabled(),
        ];

        if ($active > 0 && count($disabled) === $active) {
            return new HealthResult(
                HealthResult::STATUS_WARN,
                (string)__(
                    'AI Visibility is switched off for every store view, so nothing is measured. Fix: set '
                    . '"Enable AI Visibility" to Yes in Stores > Configuration > Panth Infotech > AI Visibility > General.'
                ),
                $details
            );
        }
        $problems = [];
        if ($disabled !== []) {
            $problems[] = (string)__('the module is off for store view(s) %1', implode(', ', $disabled));
        }
        if (!$crawlers) {
            $problems[] = (string)__('"Track AI Crawlers" is off');
        }
        if (!$visits) {
            $problems[] = (string)__('"Track Visits from AI Assistants" is off');
        }
        if ($problems !== []) {
            return new HealthResult(
                HealthResult::STATUS_WARN,
                (string)__(
                    'Part of the tracking is switched off: %1. Fix: switch these on in Stores > Configuration > '
                    . 'Panth Infotech > AI Visibility > General, unless you turned them off on purpose.',
                    implode('; ', $problems)
                ),
                $details
            );
        }
        return new HealthResult(
            HealthResult::STATUS_OK,
            (string)__('Crawler and visit tracking are on for every active store view.'),
            $details
        );
    }
}
