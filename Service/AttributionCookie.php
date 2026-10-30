<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AiVisibility\Api\AttributionCookieInterface;
use Panth\AiVisibility\Model\Attribution;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\SourceMatch;
use Psr\Log\LoggerInterface;

/**
 * Signed first-party attribution cookie.
 *
 * Value: base64url(json{f,ft,l,lt,lp}) . '.' . base64url(hmac_sha256(encoded payload, key)) where
 * key = sha256('panth_aiv|' . newest deployment crypt key). Only source codes, times and the landing
 * path are stored: no personal data. HttpOnly, SameSite=Lax, Secure on https, path '/'.
 */
class AttributionCookie implements AttributionCookieInterface
{
    private const MAX_SOURCE = 64;
    private const MAX_PATH = 512;

    /**
     * @var string|null
     */
    private ?string $key = null;

    /**
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     * @param DeploymentConfig $deploymentConfig
     * @param Config $config
     * @param ConsentChecker $consentChecker
     * @param RequestInterface $request
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly Config $config,
        private readonly ConsentChecker $consentChecker,
        private readonly RequestInterface $request,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function read(): ?Attribution
    {
        try {
            $value = $this->cookieManager->getCookie(self::COOKIE_NAME);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $payload = $this->verify($value);
        if ($payload === null) {
            return null;
        }
        $now = $this->now();
        if ($payload['lt'] + $this->windowSeconds() < $now) {
            return null;
        }
        return new Attribution($payload['f'], $payload['ft'], $payload['l'], $payload['lt'], $payload['lp']);
    }

    /**
     * @inheritDoc
     */
    public function touch(SourceMatch $source, string $landingPath): void
    {
        try {
            if ($this->key() === '' || !$this->consentChecker->canSetCookie()) {
                return;
            }
            $code = substr($source->code, 0, self::MAX_SOURCE);
            if ($code === '') {
                return;
            }
            $path = $this->cleanPath($landingPath);
            $now = $this->now();
            $current = $this->read();
            // The first touch only counts inside the attribution window, even when later touches keep the
            // cookie alive; an older first touch is replaced by this one.
            $keepFirst = $current !== null && $current->firstAt + $this->windowSeconds() >= $now;
            $payload = [
                'f' => $keepFirst ? $current->firstSource : $code,
                'ft' => $keepFirst ? $current->firstAt : $now,
                'l' => $code,
                'lt' => $now,
                'lp' => $path,
            ];
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setDuration($this->windowSeconds())
                ->setPath('/')
                ->setHttpOnly(true)
                ->setSecure($this->request->isSecure())
                ->setSameSite('Lax');
            $this->cookieManager->setPublicCookie(self::COOKIE_NAME, $this->sign($payload), $metadata);
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] attribution cookie could not be set: ' . $e->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function sign(array $payload): string
    {
        $key = $this->key();
        if ($key === '') {
            return '';
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '';
        }
        $encoded = $this->base64UrlEncode($json);
        return $encoded . '.' . $this->base64UrlEncode(hash_hmac('sha256', $encoded, $key, true));
    }

    /**
     * @inheritDoc
     */
    public function verify(string $value): ?array
    {
        $key = $this->key();
        if ($key === '' || strlen($value) > 4096 || substr_count($value, '.') !== 1) {
            return null;
        }
        [$encoded, $signature] = explode('.', $value);
        if ($encoded === '' || $signature === '') {
            return null;
        }
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $key, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        $json = $this->base64UrlDecode($encoded);
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        return $this->validPayload($data);
    }

    /**
     * Typed payload, or null when a field is missing or has the wrong type
     *
     * @param array $data
     * @return array{f: string, ft: int, l: string, lt: int, lp: string}|null
     */
    private function validPayload(array $data): ?array
    {
        foreach (['f', 'l'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === ''
                || strlen($data[$field]) > self::MAX_SOURCE
            ) {
                return null;
            }
        }
        foreach (['ft', 'lt'] as $field) {
            if (!isset($data[$field]) || !is_int($data[$field]) || $data[$field] <= 0) {
                return null;
            }
        }
        if (!isset($data['lp']) || !is_string($data['lp']) || strlen($data['lp']) > self::MAX_PATH) {
            return null;
        }
        return [
            'f' => $data['f'],
            'ft' => $data['ft'],
            'l' => $data['l'],
            'lt' => $data['lt'],
            'lp' => $data['lp'] !== '' ? $data['lp'] : '/',
        ];
    }

    /**
     * Landing path cut to 512 characters, without query or fragment
     *
     * @param string $path
     * @return string
     */
    private function cleanPath(string $path): string
    {
        $path = (string)preg_replace('/[?#].*$/s', '', $path);
        if ($path === '' || $path[0] !== '/') {
            return '/';
        }
        return substr($path, 0, self::MAX_PATH);
    }

    /**
     * Attribution window in seconds
     *
     * @return int
     */
    private function windowSeconds(): int
    {
        return $this->config->attributionWindowDays() * 86400;
    }

    /**
     * Current unix time (UTC)
     *
     * @return int
     */
    private function now(): int
    {
        return (int)$this->dateTime->gmtTimestamp();
    }

    /**
     * HMAC key derived from the newest deployment crypt key ('' when none is configured)
     *
     * @return string
     */
    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        $newest = self::newestCryptKey(
            (string)$this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY)
        );
        return $this->key = $newest !== '' ? hash('sha256', 'panth_aiv|' . $newest) : '';
    }

    /**
     * Newest key of the deployment crypt/key value (keys are separated by line breaks), '' when none
     *
     * Without a key the attribution cookie is never set; the consent health check reports that.
     *
     * @param string $raw
     * @return string
     */
    public static function newestCryptKey(string $raw): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
        return $lines !== [] ? (string)end($lines) : '';
    }

    /**
     * URL-safe base64 without padding
     *
     * @param string $value
     * @return string
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Decode URL-safe base64; null when invalid
     *
     * @param string $value
     * @return string|null
     */
    private function base64UrlDecode(string $value): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}
