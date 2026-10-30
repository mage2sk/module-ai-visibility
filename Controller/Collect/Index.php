<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Controller\Collect;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\AttributionCookieInterface;
use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\SourceMatch;
use Panth\AiVisibility\Service\RateLimiter;
use Panth\AiVisibility\Service\VisitWriter;
use Psr\Log\LoggerInterface;

/**
 * Storefront visit collector: POST /panth_aiv/collect.
 *
 * Body (text/plain JSON, max 2048 bytes): {t:'ai'|'cand', h, u, p} or {t:'land', p}.
 * h = referrer host only, u = utm_source, p = landing path without query. The server
 * re-classifies every host / utm value; the client decision is only a hint.
 * Always answers 204 with Cache-Control: no-store and no body.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const MAX_BODY = 2048;
    public const TYPE_AI = 'ai';
    public const TYPE_CANDIDATE = 'cand';
    public const TYPE_LANDING = 'land';

    private const MAX_HOST = 255;
    private const MAX_PATH = 512;
    private const HOST_PATTERN = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/';
    private const UTM_PATTERN = '/^[a-z0-9._-]{1,64}$/';
    /**
     * Printable ASCII without space, " # < > ? \ and ` (browsers always percent-encode these in a path)
     */
    private const PATH_PATTERN = '#^/[\x21\x24-\x3B\x3D\x40-\x5B\x5D-\x5F\x61-\x7E]*$#';
    /**
     * Generic crawler / headless browser markers (search engines that run JavaScript are not AI
     * bots, so the classifier alone does not catch them). "bot/" and "bot;" avoid phone models.
     */
    private const GENERIC_BOT_PATTERN = '#(bot/|bot;|crawl|spider|slurp|scrap|headless|lighthouse|\+https?:)#i';

    /**
     * @var array<int, string>|null
     */
    private ?array $storeHosts = null;

    /**
     * @param HttpRequest $request
     * @param RawFactory $rawFactory
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param BotClassifierInterface $botClassifier
     * @param SourceClassifierInterface $sourceClassifier
     * @param RateLimiter $rateLimiter
     * @param VisitWriter $visitWriter
     * @param AttributionCookieInterface $attributionCookie
     * @param RemoteAddress $remoteAddress
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $rawFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly BotClassifierInterface $botClassifier,
        private readonly SourceClassifierInterface $sourceClassifier,
        private readonly RateLimiter $rateLimiter,
        private readonly VisitWriter $visitWriter,
        private readonly AttributionCookieInterface $attributionCookie,
        private readonly RemoteAddress $remoteAddress,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Validate and record one collector beacon; always an empty 204
     *
     * @return Raw
     */
    public function execute()
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(204);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setContents('');

        try {
            $this->collect();
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] collector failed: ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * The collector pipeline; returns silently whenever the beacon is dropped
     *
     * @return void
     */
    private function collect(): void
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        if (!$this->config->isEnabled($storeId) || !$this->config->trackVisits()) {
            return;
        }
        $userAgent = $this->serverValue('HTTP_USER_AGENT');
        // Beacons come from the storefront script in a browser, and every browser sends "Mozilla/".
        if (!str_starts_with($userAgent, 'Mozilla/') || $this->isBot($userAgent)) {
            return;
        }
        if (!$this->isSameOrigin()) {
            return;
        }
        $body = (string)$this->request->getContent();
        if ($body === '' || strlen($body) > self::MAX_BODY) {
            return;
        }
        $ip = (string)$this->remoteAddress->getRemoteAddress();
        if (!$this->rateLimiter->allow($ip, $this->config->rateLimitPerMinute())) {
            return;
        }
        $beacon = $this->parse($body);
        if ($beacon === null) {
            return;
        }
        if ($beacon['t'] === self::TYPE_LANDING) {
            if ($beacon['p'] !== '/' && $this->config->landingSampleRate() > 0) {
                $this->visitWriter->insertLanding($storeId, $beacon['p']);
            }
            return;
        }
        $this->recordVisit($storeId, $beacon['h'], $beacon['u'], $beacon['p']);
    }

    /**
     * Re-classify an 'ai' / 'cand' beacon and record the visit
     *
     * @param int $storeId
     * @param string $host
     * @param string $utm
     * @param string $path
     * @return void
     */
    private function recordVisit(int $storeId, string $host, string $utm, string $path): void
    {
        if ($host === '' && $utm === '') {
            return;
        }
        if ($host !== '' && in_array($host, $this->storeHosts(), true)) {
            return;
        }
        $match = $host !== '' ? $this->sourceClassifier->classifyHost($host) : null;
        $medium = VisitWriter::MEDIUM_REFERRER;
        if ($match === null && $utm !== '') {
            $match = $this->sourceClassifier->classifyUtm($utm);
            $medium = VisitWriter::MEDIUM_UTM;
        }
        if ($match instanceof SourceMatch) {
            $this->visitWriter->insertVisit($storeId, $match->code, $medium, $host !== '' ? $host : null, $path);
            $this->attributionCookie->touch($match, $path);
            return;
        }
        if (!$this->config->discoveryEnabled()) {
            return;
        }
        $candidateHost = (string)preg_replace('/^www\./', '', $host);
        if ($candidateHost !== '' && $this->sourceClassifier->isCandidateHost($candidateHost)) {
            $key = VisitWriter::hostCandidateKey($candidateHost);
            $medium = VisitWriter::MEDIUM_REFERRER;
        } elseif ($utm !== '' && $this->sourceClassifier->isCandidateUtm($utm)) {
            $key = VisitWriter::utmCandidateKey($utm);
            $medium = VisitWriter::MEDIUM_UTM;
        } else {
            return;
        }
        $candidateId = $this->visitWriter->upsertSourceCandidate($key);
        if ($candidateId === null) {
            return;
        }
        $this->visitWriter->insertVisit(
            $storeId,
            VisitWriter::CANDIDATE_PREFIX . $candidateId,
            $medium,
            $host !== '' ? $host : null,
            $path
        );
    }

    /**
     * Decode and validate the beacon body
     *
     * @param string $body
     * @return array{t: string, h: string, u: string, p: string}|null
     */
    private function parse(string $body): ?array
    {
        try {
            $data = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        $type = $data['t'] ?? null;
        if (!in_array($type, [self::TYPE_AI, self::TYPE_CANDIDATE, self::TYPE_LANDING], true)) {
            return null;
        }
        $path = $data['p'] ?? null;
        if (!is_string($path) || strlen($path) > self::MAX_PATH || !preg_match(self::PATH_PATTERN, $path)) {
            return null;
        }
        if ($type === self::TYPE_LANDING) {
            return ['t' => $type, 'h' => '', 'u' => '', 'p' => $path];
        }
        $host = $data['h'] ?? '';
        $utm = $data['u'] ?? '';
        if (!is_string($host) || !is_string($utm)) {
            return null;
        }
        if ($host !== '' && (strlen($host) > self::MAX_HOST || str_contains($host, '..')
            || !preg_match(self::HOST_PATTERN, $host))
        ) {
            return null;
        }
        if ($utm !== '' && !preg_match(self::UTM_PATTERN, $utm)) {
            return null;
        }
        return ['t' => $type, 'h' => $host, 'u' => $utm, 'p' => $path];
    }

    /**
     * Known AI bot, discovery candidate or any generic bot / headless browser
     *
     * @param string $userAgent
     * @return bool
     */
    private function isBot(string $userAgent): bool
    {
        return preg_match(self::GENERIC_BOT_PATTERN, $userAgent) === 1
            || $this->botClassifier->classify($userAgent) !== null
            || $this->botClassifier->isDiscoveryCandidate($userAgent);
    }

    /**
     * Whether the Origin (or Referer) header names one of this installation's store hosts
     *
     * @return bool
     */
    private function isSameOrigin(): bool
    {
        $host = $this->hostFromUrl($this->serverValue('HTTP_ORIGIN'))
            ?? $this->hostFromUrl($this->serverValue('HTTP_REFERER'));
        return $host !== null && in_array($host, $this->storeHosts(), true);
    }

    /**
     * Host names of every store base URL (secure and unsecure)
     *
     * @return array<int, string>
     */
    private function storeHosts(): array
    {
        if ($this->storeHosts !== null) {
            return $this->storeHosts;
        }
        $hosts = [];
        foreach ($this->storeManager->getStores() as $store) {
            foreach ([false, true] as $secure) {
                $host = $this->hostFromUrl((string)$store->getBaseUrl(UrlInterface::URL_TYPE_LINK, $secure));
                if ($host !== null) {
                    $hosts[$host] = true;
                }
            }
        }
        return $this->storeHosts = array_map('strval', array_keys($hosts));
    }

    /**
     * Lower-cased host of a URL, or null
     *
     * @param string $url
     * @return string|null
     */
    private function hostFromUrl(string $url): ?string
    {
        if ($url === '' || !preg_match('#^https?://([^/:?\#@]+)#i', $url, $m)) {
            return null;
        }
        return strtolower($m[1]);
    }

    /**
     * A server variable as a string ('' when missing)
     *
     * @param string $key
     * @return string
     */
    private function serverValue(string $key): string
    {
        $value = $this->request->getServer($key);
        return is_string($value) ? $value : '';
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Exempt from form-key validation: same-origin is checked in execute() and nothing is changed for the visitor
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
