<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model\Catalogue;

/**
 * Ordinary browser User-Agents, used to keep human visitors out of Discovery.
 *
 * isPlainBrowser() spots a full browser User-Agent without any bot marker: such a visitor that opens
 * /llms.txt is a person, not an unknown AI bot. sharesBrowserText() tells whether a token also occurs in
 * the reference User-Agents of the common desktop and mobile browsers, so a Discovery rule built on it
 * would count ordinary shoppers as an AI bot.
 */
class BrowserUserAgents
{
    /**
     * Current desktop and mobile browser User-Agents (Chrome, Safari, Firefox, Edge, Opera, Samsung)
     */
    public const REFERENCE = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 '
        . 'Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 '
        . 'Safari/537.36 Edg/130.0.0.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 '
        . 'Safari/537.36 OPR/115.0.0.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',
        'Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; rv:11.0) like Gecko',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 '
        . 'Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 '
        . 'Safari/605.1.15',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:131.0) Gecko/20100101 Firefox/131.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0',
        'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 '
        . 'Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) '
        . 'Version/17.5 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) '
        . 'CriOS/130.0.6723.90 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 '
        . 'Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 '
        . 'Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile '
        . 'Safari/537.36',
        'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0',
    ];

    /**
     * Rendering engines / products a browser User-Agent carries
     */
    private const ENGINE_PATTERN = '#(AppleWebKit/|Gecko/|Trident/|Firefox/|Chrome/|Safari/)#';

    /**
     * Markers of crawlers, tools and agents (any of them makes a User-Agent not a plain browser)
     */
    private const BOT_MARKERS = '#(bot|crawl|spider|agent|fetch|-user\b|gpt|llm|scrap|slurp|preview|monitor|check'
        . '|https?:|www\.|\+|@|python|curl|wget|java/|okhttp|go-http|node|axios|headless|phantom|lighthouse)#i';

    /**
     * Words that every browser User-Agent family uses (checked as whole tokens)
     */
    private const COMMON_FRAGMENTS = '#^(mozilla|compatible|applewebkit|khtml|like|gecko|chrome|chromium|crios|safari'
        . '|firefox|fxios|edge?|edg|opr|opera|version|mobile|windows|win(32|64)?|wow64|nt|x86|x86_64|x64|amd64|arm'
        . '|arm64|aarch64|intel|ppc|mac|macintosh|os|cpu|iphone|ipad|ipod|android|linux|ubuntu|fedora|x11|cros|build'
        . '|wv|samsungbrowser|trident|rv|en|en-us|u|k|http|https|www)$#i';

    /**
     * Whether the User-Agent is an ordinary browser without any crawler or tool marker
     *
     * @param string $userAgent
     * @return bool
     */
    public static function isPlainBrowser(string $userAgent): bool
    {
        $userAgent = trim($userAgent);
        if (!str_starts_with($userAgent, 'Mozilla/5.0 (')) {
            return false;
        }
        return preg_match(self::ENGINE_PATTERN, $userAgent) === 1
            && preg_match(self::BOT_MARKERS, $userAgent) !== 1;
    }

    /**
     * Whether a token is too generic to identify one bot
     *
     * True for tokens shorter than 4 characters or without letters, common User-Agent words and any
     * text that occurs in one of the reference browser User-Agents (case-insensitive).
     *
     * @param string $token
     * @return bool
     */
    public static function sharesBrowserText(string $token): bool
    {
        $token = trim($token);
        if (strlen($token) < 4 || !preg_match('/[A-Za-z]/', $token)) {
            return true;
        }
        foreach (preg_split('#[\s/;(),]+#', $token) ?: [] as $part) {
            if ($part !== '' && preg_match('/[A-Za-z]{2}/', $part) && !preg_match(self::COMMON_FRAGMENTS, $part)) {
                // At least one word of the token is not a common browser word.
                foreach (self::REFERENCE as $reference) {
                    if (stripos($reference, $token) !== false) {
                        return true;
                    }
                }
                return false;
            }
        }
        return true;
    }
}
