<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Api;

use Panth\AiVisibility\Model\Attribution;
use Panth\AiVisibility\Model\SourceMatch;

/**
 * Signed first-party attribution cookie (name panth_aiv).
 *
 * Value: base64url(json{f,ft,l,lt,lp}) . '.' . base64url(hmac_sha256(payload, key)).
 */
interface AttributionCookieInterface
{
    public const COOKIE_NAME = 'panth_aiv';

    /**
     * Valid, untampered and unexpired attribution from the request cookie, or null
     *
     * @return Attribution|null
     */
    public function read(): ?Attribution;

    /**
     * Create or update the cookie: the first touch is kept, the last touch is updated
     *
     * @param SourceMatch $source
     * @param string $landingPath
     * @return void
     */
    public function touch(SourceMatch $source, string $landingPath): void;

    /**
     * Encode and sign a payload (pure)
     *
     * @param array $payload
     * @return string
     */
    public function sign(array $payload): string;

    /**
     * Verify a cookie value and return its payload, or null when malformed or tampered (pure)
     *
     * @param string $value
     * @return array|null
     */
    public function verify(string $value): ?array;
}
