<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Plugin;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Session\SessionStartChecker;

/**
 * Keeps the visit collector (POST /panth_aiv/collect) session-free.
 *
 * The collector is a normal storefront action, so the customer context plugin would start (and lock) a
 * PHP session and send a session cookie for every beacon, even before cookie consent. Returning false
 * here makes Magento skip the session for that path only (same pattern as the core PayPal and GraphQL
 * session checkers).
 */
class CollectorSessionChecker
{
    /**
     * Collector route in a request URI (with or without store code / index.php prefix)
     */
    private const COLLECTOR_PATTERN = '#/panth_aiv/collect(/|\?|$)#i';

    /**
     * @param Http $request
     */
    public function __construct(
        private readonly Http $request
    ) {
    }

    /**
     * No session for the collector route
     *
     * @param SessionStartChecker $subject
     * @param bool $result
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCheck(SessionStartChecker $subject, bool $result): bool
    {
        if (!$result) {
            return false;
        }
        return !self::isCollectorRequest($this->request);
    }

    /**
     * Whether the request is a collector beacon
     *
     * @param Http $request
     * @return bool
     */
    public static function isCollectorRequest(Http $request): bool
    {
        try {
            $uri = (string)$request->getRequestUri();
        } catch (\Throwable $e) {
            return false;
        }
        return $uri !== '' && preg_match(self::COLLECTOR_PATTERN, $uri) === 1;
    }
}
