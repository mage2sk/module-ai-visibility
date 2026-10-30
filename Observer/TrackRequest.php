<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Observer;

use Magento\Framework\App\Area;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\HttpInterface as HttpResponse;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Api\HitWriterInterface;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Model\Catalogue\BotCatalogue;
use Panth\AiVisibility\Model\Catalogue\BrowserUserAgents;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\TextFilter;
use Psr\Log\LoggerInterface;

/**
 * Records AI crawler hits on controller_front_send_response_before (storefront only).
 *
 * The event also fires for built-in full page cache hits. For a human visitor only cached
 * configuration flags and in-memory string checks run; the database writer is injected as a
 * Proxy and is only constructed when a bot hit is written. Nothing here may break a response:
 * every error is caught and logged as a warning.
 */
class TrackRequest implements ObserverInterface
{
    private const MAX_PATH_LENGTH = 512;

    /**
     * Path prefixes that are never tracked (the sitemap files below /media/ are the exception)
     */
    private const SKIPPED_PREFIXES = ['/static/', '/media/', '/pub/static/', '/pub/media/'];

    /**
     * Web API front names (never the storefront area, so PHP tracking never sees them)
     */
    private const WEB_API_PATTERN = '#^(/index\.php)?(/[a-z0-9_]+)?/(rest|soap|graphql)(/|$)#i';

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param State $appState
     * @param BotClassifierInterface $botClassifier
     * @param PageTypeResolverInterface $pageTypeResolver
     * @param HitWriterInterface $hitWriter
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $appState,
        private readonly BotClassifierInterface $botClassifier,
        private readonly PageTypeResolverInterface $pageTypeResolver,
        private readonly HitWriterInterface $hitWriter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        try {
            $this->track($observer);
        } catch (\Throwable $e) {
            try {
                $this->logger->warning('[Panth_AiVisibility] crawler hit not recorded: ' . $e->getMessage());
            } catch (\Throwable $ignored) {
                return;
            }
        }
    }

    /**
     * Split a request URI into the stored path and the "has query" flag
     *
     * The path has no scheme, host, query or fragment, single slashes, decoded percent-escapes
     * (kept encoded when decoding would give invalid UTF-8 or control characters; "%3F" and "%23"
     * always stay encoded so they are never read as a query or fragment later), invalid raw bytes
     * percent-encoded, and at most 512 characters. An empty path becomes "/".
     *
     * @param string $uri
     * @return array{0: string, 1: bool}
     */
    public static function splitUri(string $uri): array
    {
        $uri = trim($uri);
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/?\#]*#i', $uri, $match)) {
            $uri = substr($uri, strlen($match[0]));
        }
        $hash = strpos($uri, '#');
        if ($hash !== false) {
            $uri = substr($uri, 0, $hash);
        }
        $hasQuery = false;
        $question = strpos($uri, '?');
        if ($question !== false) {
            $hasQuery = $question < strlen($uri) - 1;
            $uri = substr($uri, 0, $question);
        }
        $path = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $uri);
        if (str_contains($path, '%')) {
            $decoded = (string)preg_replace_callback(
                '/%([0-9A-Fa-f]{2})/',
                static function (array $m): string {
                    $hex = strtoupper($m[1]);
                    return $hex === '3F' || $hex === '23' ? '%' . $hex : chr((int)hexdec($hex));
                },
                $path
            );
            if (mb_check_encoding($decoded, 'UTF-8') && !preg_match('/[\x00-\x1F\x7F]/', $decoded)) {
                $path = $decoded;
            }
        }
        $path = TextFilter::encodeInvalidUtf8($path);
        $path = (string)preg_replace('#/{2,}#', '/', $path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return [mb_substr($path, 0, self::MAX_PATH_LENGTH, 'UTF-8'), $hasQuery];
    }

    /**
     * Whether a path is never tracked: the collector route, static files and media (except sitemaps)
     *
     * @param string $path
     * @return bool
     */
    public static function isSkippedPath(string $path): bool
    {
        $lower = strtolower($path);
        if (str_contains($lower . '/', '/panth_aiv/')) {
            return true;
        }
        foreach (self::SKIPPED_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return !preg_match('#/sitemap[^/]*\.xml(\.gz)?$#', $lower);
            }
        }
        return self::isWebApiPath($lower);
    }

    /**
     * Whether a path is a REST, SOAP or GraphQL request ("/rest/V1/...", "/rest/default/V1/...", "/graphql")
     *
     * PHP tracking never counts them (not the storefront area); the log import uses this to agree with it.
     *
     * @param string $path
     * @return bool
     */
    public static function isWebApiPath(string $path): bool
    {
        return preg_match(self::WEB_API_PATTERN, $path) === 1;
    }

    /**
     * User-Agent of the request, read from the server variables (never throws on odd bytes)
     *
     * Request::getHeader() validates header values and throws for bytes such as 0xFF or DEL, which
     * would lose the hit; the raw server value is cleaned instead.
     *
     * @param HttpRequest $request
     * @return string
     */
    public static function userAgent(HttpRequest $request): string
    {
        $userAgent = $request->getServerValue('HTTP_USER_AGENT');
        if (!is_string($userAgent) || trim($userAgent) === '') {
            try {
                $userAgent = $request->getHeader('User-Agent');
            } catch (\Throwable $e) {
                $userAgent = '';
            }
        }
        return is_string($userAgent) ? TextFilter::userAgent($userAgent) : '';
    }

    /**
     * Classify the request and write the hit
     *
     * @param Observer $observer
     * @return void
     */
    private function track(Observer $observer): void
    {
        $event = $observer->getEvent();
        $request = $event->getData('request');
        if (!$request instanceof HttpRequest) {
            return;
        }
        $userAgent = self::userAgent($request);
        if ($userAgent === '' || !$this->isFrontend()) {
            return;
        }
        $storeId = (int)$this->storeManager->getStore()->getId();
        if (!$this->config->isEnabled($storeId) || !$this->config->trackCrawlers()) {
            return;
        }
        $uri = $request->getServerValue('REQUEST_URI');
        if (!is_string($uri) || $uri === '') {
            $uri = (string)$request->getRequestUri();
        }
        [$path, $hasQuery] = self::splitUri($uri);
        if (self::isSkippedPath($path)) {
            return;
        }

        $bot = $this->botClassifier->classify($userAgent);
        if ($bot === null && !$this->isCandidate($userAgent, $path)) {
            return;
        }
        if ($this->config->crawlerSource($storeId) === Config::CRAWLER_SOURCE_LOG) {
            return;
        }
        $status = $this->status($event->getData('response'));
        $duration = $this->durationMs($request);
        if ($bot !== null) {
            $this->hitWriter->recordHit($storeId, $bot, $path, $hasQuery, $status, $duration);
            return;
        }
        $this->hitWriter->recordCandidateHit($storeId, $userAgent, $path, $hasQuery, $status, $duration);
    }

    /**
     * Unknown bot-like User-Agent, or a non-browser client fetching llms.txt (discovery must be on)
     *
     * An ordinary browser that opens /llms.txt is a person and never becomes a candidate.
     *
     * @param string $userAgent
     * @param string $path
     * @return bool
     */
    private function isCandidate(string $userAgent, string $path): bool
    {
        if (!$this->config->discoveryEnabled()) {
            return false;
        }
        if ($this->botClassifier->isDiscoveryCandidate($userAgent)) {
            return true;
        }
        return self::isLlmsCandidate($userAgent, $this->pageTypeResolver->resolve($path));
    }

    /**
     * Whether a client that is not bot-like still becomes a candidate: it fetched llms.txt and is neither
     * a known non-AI client nor an ordinary browser
     *
     * @param string $userAgent
     * @param string $pageType
     * @return bool
     */
    public static function isLlmsCandidate(string $userAgent, string $pageType): bool
    {
        return $pageType === PageTypeResolverInterface::TYPE_LLMS
            && !BotCatalogue::isNonAi($userAgent)
            && !BrowserUserAgents::isPlainBrowser($userAgent);
    }

    /**
     * Whether the current area is the storefront
     *
     * @return bool
     */
    private function isFrontend(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_FRONTEND;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * HTTP status of the response, 0 when unknown
     *
     * @param mixed $response
     * @return int
     */
    private function status($response): int
    {
        if (!$response instanceof HttpResponse) {
            return 0;
        }
        $status = (int)$response->getHttpResponseCode();
        return $status >= 100 && $status <= 999 ? $status : 0;
    }

    /**
     * Milliseconds since REQUEST_TIME_FLOAT (0 when unknown, never negative)
     *
     * @param HttpRequest $request
     * @return int
     */
    private function durationMs(HttpRequest $request): int
    {
        $start = $request->getServerValue('REQUEST_TIME_FLOAT');
        if (!is_numeric($start) || (float)$start <= 0) {
            return 0;
        }
        $elapsed = (int)round((microtime(true) - (float)$start) * 1000);
        return max(0, min(86400000, $elapsed));
    }
}
