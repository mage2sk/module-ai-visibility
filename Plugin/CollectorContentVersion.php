<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Plugin;

use Magento\Framework\App\PageCache\Version;
use Magento\Framework\App\Request\Http;

/**
 * Keeps the private content version unchanged for collector beacons.
 *
 * The page cache renews the private_content_version cookie on every POST, which tells the storefront
 * to reload all customer sections. A beacon changes no customer data, so the renewal is skipped there.
 */
class CollectorContentVersion
{
    /**
     * @param Http $request
     */
    public function __construct(
        private readonly Http $request
    ) {
    }

    /**
     * Skip the cookie renewal for the collector route
     *
     * @param Version $subject
     * @param callable $proceed
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundProcess(Version $subject, callable $proceed)
    {
        if (CollectorSessionChecker::isCollectorRequest($this->request)) {
            return null;
        }
        return $proceed();
    }
}
