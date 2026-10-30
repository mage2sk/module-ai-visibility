<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Health;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Service\AttributionCookie;

/**
 * Explains how Magento's cookie restriction mode affects order attribution, and reports an error
 * when the deployment has no crypt key (the signed attribution cookie is then never set).
 */
class ConsentCheck extends AbstractCheck
{
    public const CODE = 'consent';

    /**
     * Magento\Cookie\Helper\Cookie::XML_PATH_COOKIE_RESTRICTION
     */
    public const XML_PATH_COOKIE_RESTRICTION = 'web/cookie/cookie_restriction';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Config $config
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Config $config,
        private readonly DeploymentConfig $deploymentConfig
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
        $restriction = $this->scopeConfig->isSetFlag(self::XML_PATH_COOKIE_RESTRICTION);
        $respect = $this->config->respectCookieConsent();
        $hasKey = AttributionCookie::newestCryptKey(
            (string)$this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY)
        ) !== '';
        $details = [
            'cookie_restriction' => $restriction,
            'respect_cookie_consent' => $respect,
            'crypt_key' => $hasKey,
        ];
        if (!$hasKey) {
            return new HealthResult(
                HealthResult::STATUS_ERROR,
                (string)__(
                    'No encryption key is configured in app/etc/env.php (crypt/key), so the signed attribution '
                    . 'cookie is never set and no order can be attributed to an AI source. Visits are still '
                    . 'counted. Fix: configure an encryption key for this installation.'
                ),
                $details
            );
        }
        if (!$restriction) {
            return new HealthResult(
                HealthResult::STATUS_OK,
                (string)__(
                    'Cookie restriction mode is off, so the attribution cookie is set for every visitor from an AI '
                    . 'assistant. If your privacy policy requires consent, turn on Stores > Configuration > General > '
                    . 'Web > Default Cookie Settings > Cookie Restriction Mode.'
                ),
                $details
            );
        }
        if ($respect) {
            return new HealthResult(
                HealthResult::STATUS_OK,
                (string)__(
                    'Cookie restriction mode is on and respected: visits are always counted, but orders are only '
                    . 'attributed for visitors who allowed cookies, so attributed orders can be lower than real.'
                ),
                $details
            );
        }
        return new HealthResult(
            HealthResult::STATUS_WARN,
            (string)__(
                'Cookie restriction mode is on, but AI Visibility is set to ignore it and sets its attribution cookie '
                . 'before consent. Fix: set "Respect Cookie Consent" to Yes in the AI Visibility configuration '
                . '(Privacy and Data Retention), unless your privacy review allows this cookie without consent.'
            ),
            $details
        );
    }
}
