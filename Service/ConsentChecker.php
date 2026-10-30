<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Panth\AiVisibility\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Decides whether the attribution cookie may be set for the current visitor.
 *
 * When privacy/respect_cookie_consent is on and Magento cookie restriction mode says the
 * visitor has not accepted cookies, the cookie is not set (the visit itself is still counted).
 */
class ConsentChecker
{
    /**
     * @param Config $config
     * @param CookieHelper $cookieHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly CookieHelper $cookieHelper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether the attribution cookie may be written
     *
     * @return bool
     */
    public function canSetCookie(): bool
    {
        if (!$this->config->respectCookieConsent()) {
            return true;
        }
        try {
            return !$this->cookieHelper->isUserNotAllowSaveCookie();
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] cookie consent could not be checked: ' . $e->getMessage());
            return false;
        }
    }
}
