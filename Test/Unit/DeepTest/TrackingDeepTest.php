<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Laminas\Stdlib\Parameters;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\PathInfo;
use Magento\Framework\App\Request\PathInfoProcessorInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\Route\ConfigInterface as RouteConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieReaderInterface;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\HitWriterInterface;
use Panth\AiVisibility\Model\BotMatch;
use Panth\AiVisibility\Model\Catalogue\BotCatalogue;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Observer\TrackRequest;
use Panth\AiVisibility\Service\BotClassifier;
use Panth\AiVisibility\Service\HitWriter;
use Panth\AiVisibility\Service\PageTypeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep test D1 (tracking and performance): real-world User-Agents, path normalisation, skips, hostile
 * input, the raw cap boundary and UTC day handling.
 *
 * Tests named "testFixed..." prove the fix of a defect found in the deep test (they failed on the first build).
 */
class TrackingDeepTest extends TestCase
{
    /**
     * @var array<int, array>
     */
    private array $hits = [];

    /**
     * @var array<int, array>
     */
    private array $candidateHits = [];

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $queries = [];

    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $inserts = [];

    /**
     * @var int
     */
    private int $counter = 0;

    /**
     * @var string
     */
    private string $timezone = 'UTC';

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    // ------------------------------------------------------------------ helpers

    private function config(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('trackCrawlers')->willReturn(true);
        $config->method('discoveryEnabled')->willReturn(true);
        $config->method('customBotPatterns')->willReturn([]);
        $config->method('crawlerSource')->willReturn(Config::CRAWLER_SOURCE_MAGENTO);
        return $config;
    }

    private function classifier(): BotClassifier
    {
        $rules = $this->createStub(RuleProvider::class);
        $rules->method('botRules')->willReturn([]);
        return new BotClassifier($this->config(), $rules);
    }

    private function observer(): TrackRequest
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn('frontend');
        $writer = $this->createStub(HitWriterInterface::class);
        $writer->method('recordHit')->willReturnCallback(function (...$args) {
            $this->hits[] = $args;
        });
        $writer->method('recordCandidateHit')->willReturnCallback(function (...$args) {
            $this->candidateHits[] = $args;
        });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->warnings[] = (string)$message;
        });
        return new TrackRequest(
            $this->config(),
            $storeManager,
            $state,
            $this->classifier(),
            new PageTypeResolver(),
            $writer,
            $logger
        );
    }

    /**
     * Event carrying a REAL Magento request object, so headers are parsed exactly like on the storefront
     */
    private function realRequestEvent(string $userAgent, string $uri, int $status = 200): Observer
    {
        $request = new HttpRequest(
            $this->createStub(CookieReaderInterface::class),
            $this->createStub(StringUtils::class),
            $this->createStub(RouteConfigInterface::class),
            $this->createStub(PathInfoProcessorInterface::class),
            $this->createStub(ObjectManagerInterface::class),
            null,
            [],
            $this->createStub(PathInfo::class)
        );
        $request->setServer(new Parameters([
            'HTTP_USER_AGENT' => $userAgent,
            'HTTP_HOST' => 'shop.test',
            'REQUEST_URI' => $uri,
            'REQUEST_METHOD' => 'GET',
            'REQUEST_TIME_FLOAT' => microtime(true) - 0.05,
        ]));
        $response = $this->createStub(HttpResponse::class);
        $response->method('getHttpResponseCode')->willReturn($status);
        return new Observer(['event' => new Event(['request' => $request, 'response' => $response])]);
    }

    private function writer(int $cap): HitWriter
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(fn ($name) => '`' . $name . '`');
        $connection->method('query')->willReturnCallback(function ($sql, $bind = []) {
            $this->queries[] = [(string)$sql, (array)$bind];
            if (str_contains((string)$sql, 'panth_aiv_counter')) {
                $this->counter++;
            }
            return null;
        });
        $connection->method('fetchOne')->willReturnCallback(fn () => (string)$this->counter);
        $connection->method('fetchRow')->willReturn(['candidate_id' => '7', 'status' => 'new']);
        $connection->method('insert')->willReturnCallback(function ($table, array $data) {
            $this->inserts[] = [(string)$table, $data];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(fn ($name) => (string)$name);
        $config = $this->createStub(Config::class);
        $config->method('rawDailyCap')->willReturn($cap);
        return new HitWriter($resource, $config, new PageTypeResolver());
    }

    private function queriesOn(string $table): array
    {
        return array_values(array_filter($this->queries, fn ($q) => str_contains($q[0], '`' . $table . '`')));
    }

    // ------------------------------------------------------------------ TC1.01 built-in bots, real UAs

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function builtInBotProvider(): array
    {
        $wk = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko';
        return [
            'gptbot' => [$wk . '); compatible; GPTBot/1.1; +https://openai.com/gptbot', 'gptbot', 'training'],
            'oai' => [$wk . '); compatible; OAI-SearchBot/1.3; robots.txt; +https://openai.com/searchbot',
                'oai_searchbot', 'search'],
            'chatgpt-user' => [$wk . '); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 'chatgpt_user', 'live'],
            'claudebot' => [$wk . '; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'claudebot', 'training'],
            'claude-search' => [$wk . '; compatible; Claude-SearchBot/1.0; +https://www.anthropic.com)',
                'claude_searchbot', 'search'],
            'claude-user' => [$wk . '; compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 'claude_user', 'live'],
            'anthropic-ai' => ['Mozilla/5.0 (compatible; anthropic-ai/1.0; +http://www.anthropic.com/bot.html)',
                'anthropic_ai', 'training'],
            'claude-web' => ['Mozilla/5.0 (compatible; Claude-Web/1.0; +http://www.anthropic.com/bot.html)',
                'claude_web', 'live'],
            'perplexitybot' => [$wk . '; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
                'perplexitybot', 'search'],
            'perplexity-user' => [$wk . '; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)',
                'perplexity_user', 'live'],
            'meta-agent' => ['meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
                'meta_externalagent', 'training'],
            'meta-fetcher' => ['meta-externalfetcher/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
                'meta_externalfetcher', 'live'],
            'amazonbot' => [$wk . '; compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot) '
                . 'Chrome/119.0.6045.214 Safari/537.36', 'amazonbot', 'search'],
            'applebot' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)', 'applebot', 'search'],
            'duckassist' => ['DuckAssistBot/1.2; (+http://duckduckgo.com/duckassistbot.html)', 'duckassistbot', 'live'],
            'mistral' => [$wk . '; compatible; MistralAI-User/1.0; +https://docs.mistral.ai/robots)',
                'mistralai_user', 'live'],
            'youbot' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/91.0.4472.114 Safari/537.36 (compatible; YouBot (+http://www.you.com))', 'youbot', 'search'],
            'bytespider' => ['Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile '
                . 'Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)', 'bytespider', 'training'],
            'ccbot' => ['CCBot/2.0 (https://commoncrawl.org/faq/)', 'ccbot', 'training'],
            'cohere-ai' => ['Mozilla/5.0 (compatible; cohere-ai/1.0; +http://www.cohere.ai/bot.html)',
                'cohere_ai', 'training'],
            'cohere-crawler' => ['cohere-training-data-crawler/1.0 (+https://cohere.com)', 'cohere_crawler', 'training'],
            'diffbot' => ['Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/49.0.2623.112 Safari/537.36 (compatible; Diffbot/0.1; +http://www.diffbot.com)', 'diffbot', 'other'],
            'upper-case' => ['MOZILLA/5.0 (COMPATIBLE; GPTBOT/1.1)', 'gptbot', 'training'],
            'padded' => ["  \tCCBot/2.0  ", 'ccbot', 'training'],
        ];
    }

    /**
     * @dataProvider builtInBotProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('builtInBotProvider')]
    public function testBuiltInBotRealWorldUserAgent(string $userAgent, string $code, string $category): void
    {
        $classifier = $this->classifier();
        $match = $classifier->classify($userAgent);
        $this->assertInstanceOf(BotMatch::class, $match);
        $this->assertSame($code, $match->code);
        $this->assertSame($category, $match->category);
        $this->assertFalse($classifier->isDiscoveryCandidate($userAgent), 'A known bot is never a candidate');
    }

    // ------------------------------------------------------------------ TC1.03 humans and non-AI bots

    /**
     * @return array<string, array{0: string}>
     */
    public static function humanProvider(): array
    {
        $ios = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) ';
        return [
            'chrome-win' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/130.0.0.0 Safari/537.36'],
            'firefox' => ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0'],
            'safari-mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/18.0 Safari/605.1.15'],
            'edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/130.0.0.0 Safari/537.36 Edg/130.0.2849.56'],
            'samsung' => ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S928B) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36'],
            'iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like '
                . 'Gecko) Version/18.0 Mobile/15E148 Safari/604.1'],
            'cubot-phone' => ['Mozilla/5.0 (Linux; Android 11; CUBOT X50) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/120.0.6099.144 Mobile Safari/537.36'],
            'cubot-kingkong' => ['Mozilla/5.0 (Linux; Android 12; KINGKONG 7) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/119.0.0.0 Mobile Safari/537.36'],
            'facebook-iab' => [$ios . 'Mobile/15E148 [FBAN/FBIOS;FBAV/470.0.0.40.97;FBDV/iPhone15,2;FBSN/iOS]'],
            'instagram' => ['Mozilla/5.0 (Linux; Android 13; SM-G991B Build/TP1A.220624.014; wv) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Version/4.0 Chrome/120.0.6099.193 Mobile Safari/537.36 Instagram 312.1.0.34.111'],
            'tiktok' => [$ios . 'Mobile/15E148 musical_ly_34.1.0 JsSdk/2.0 ByteLocale/en BytedanceWebview/d8a21c6'],
            'snapchat' => [$ios . 'Mobile/15E148 Snapchat/13.0.0.39 (like Safari/8617.2.4.10.8, panda)'],
            'linkedin-app' => [$ios . 'Mobile/15E148 [LinkedInApp]/9.29.8562'],
            'pinterest-app' => [$ios . 'Mobile/15E148 [Pinterest/iOS]'],
            'wechat' => ['Mozilla/5.0 (Linux; Android 12; V2134A Build/SP1A.210812.003; wv) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Version/4.0 Chrome/107.0.5304.141 Mobile Safari/537.36 MicroMessenger/8.0.33'],
            'huawei' => ['Mozilla/5.0 (Linux; Android 10; HarmonyOS; ELS-NX9; HMSCore 6.13.0.302) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/114.0.5735.196 HuaweiBrowser/14.0.6.300 Mobile Safari/537.36'],
            'google-app' => [$ios . 'GSA/330.0.660815880 Mobile/15E148 Safari/604.1'],
            'silk' => ['Mozilla/5.0 (Linux; Android 9; KFTRWI) AppleWebKit/537.36 (KHTML, like Gecko) Silk/119.4.1 '
                . 'like Chrome/119.0.6045.193 Safari/537.36'],
            'xbox' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; Xbox; Xbox One) AppleWebKit/537.36 (KHTML, like '
                . 'Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0'],
            'kaios' => ['Mozilla/5.0 (Mobile; Nokia 8000 4G; rv:48.0) Gecko/48.0 Firefox/48.0 KAIOS/2.5.4'],
        ];
    }

    /**
     * @dataProvider humanProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('humanProvider')]
    public function testHumanBrowserIsNeitherBotNorCandidate(string $userAgent): void
    {
        $classifier = $this->classifier();
        $this->assertNull($classifier->classify($userAgent));
        $this->assertFalse($classifier->isDiscoveryCandidate($userAgent));
        $this->observer()->execute($this->realRequestEvent($userAgent, '/gear/bags.html?p=2'));
        $this->assertSame([], $this->hits);
        $this->assertSame([], $this->candidateHits);
        $this->assertSame([], $this->warnings);
    }

    /**
     * Non-AI crawlers on the spec's exclusion list
     *
     * @return array<string, array{0: string}>
     */
    public static function listedNonAiProvider(): array
    {
        return [
            'googlebot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; '
                . '+http://www.google.com/bot.html) Chrome/130.0.6723.69 Safari/537.36'],
            'google-inspection' => ['Mozilla/5.0 (compatible; Google-InspectionTool/1.0;)'],
            'storebot' => ['Mozilla/5.0 (X11; Linux x86_64; Storebot-Google/1.0) AppleWebKit/537.36'],
            'adsbot' => ['AdsBot-Google (+http://www.google.com/adsbot.html)'],
            'feedfetcher' => ['FeedFetcher-Google; (+http://www.google.com/feedfetcher.html)'],
            'bingbot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; '
                . '+http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36'],
            'yandexbot' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'],
            'baidu' => ['Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)'],
            'duckduckbot' => ['DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)'],
            'sogou' => ['Sogou web spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm#07)'],
            'petal' => ['Mozilla/5.0 (Linux; Android 7.0;) AppleWebKit/537.36 (compatible; PetalBot;'
                . '+https://webmaster.petalsearch.com/site/petalbot)'],
            'fb-hit' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
            'slack' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)'],
            'telegram' => ['TelegramBot (like TwitterBot)'],
            'ahrefs' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
            'semrush' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)'],
            'uptimerobot' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'],
            'pingdom' => ['Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)'],
            'lighthouse' => ['Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like '
                . 'Gecko) Chrome/119.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse'],
            'headless' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'HeadlessChrome/120.0.0.0 Safari/537.36'],
        ];
    }

    /**
     * @dataProvider listedNonAiProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('listedNonAiProvider')]
    public function testListedNonAiCrawlerIsNotRecordedEvenOnLlmsTxt(string $userAgent): void
    {
        $this->assertFalse($this->classifier()->isDiscoveryCandidate($userAgent));
        $this->observer()->execute($this->realRequestEvent($userAgent, '/llms.txt'));
        $this->observer()->execute($this->realRequestEvent($userAgent, '/gear.html'));
        $this->assertSame([], $this->hits);
        $this->assertSame([], $this->candidateHits);
    }

    /**
     * Common SEO / search / uptime crawlers that are NOT on the exclusion list (TC1.03 expects "not candidates")
     *
     * @return array<string, array{0: string}>
     */
    public static function unlistedNonAiProvider(): array
    {
        return [
            'yandex-mobile' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 8_1 like Mac OS X) AppleWebKit/600.1.4 (KHTML, '
                . 'like Gecko) Version/8.0 Mobile/12B411 Safari/600.1.4 (compatible; YandexMobileBot/3.0; '
                . '+http://yandex.com/bots)'],
            'ddg-favicons' => ['DuckDuckGo-Favicons-Bot/1.0'],
            'facebot' => ['Facebot'],
            'pinterest-old' => ['Pinterest/0.2 (+http://www.pinterest.com/bot.html)'],
            'semrush-audit' => ['Mozilla/5.0 (compatible; SiteAuditBot/0.97; +http://www.semrush.com/bot.html)'],
            'screaming-frog' => ['Screaming Frog SEO Spider/20.0'],
            'better-uptime' => ['Better Uptime Bot Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
            'redditbot' => ['Mozilla/5.0 (compatible; redditbot/1.0; +http://www.reddit.com/feedback)'],
        ];
    }

    /**
     * Fixed (minor); on the first build these crawlers become discovery candidates, write raw rows and use up the raw cap.
     *
     * @dataProvider unlistedNonAiProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unlistedNonAiProvider')]
    public function testFixedCommonNonAiCrawlerIsNotADiscoveryCandidate(string $userAgent): void
    {
        $this->assertFalse(
            $this->classifier()->isDiscoveryCandidate($userAgent),
            'Well-known non-AI crawler treated as an unknown AI bot candidate: ' . $userAgent
        );
    }

    // ------------------------------------------------------------------ TC1.10 hostile User-Agent bytes

    public function testCandidateWithPlainHostilePayloadIsRecordedThroughTheRealRequest(): void
    {
        $ua = 'AivTest-D1Sql/1.0 bot\'; DROP TABLE x; -- <script>alert(1)</script> " onmouseover=alert(1)';
        $this->observer()->execute($this->realRequestEvent($ua, '/aivtest-d1-x'));
        $this->assertCount(1, $this->candidateHits, 'Control: the real request object passes a normal UA through');
        $this->assertSame($ua, $this->candidateHits[0][1]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidHeaderByteProvider(): array
    {
        return [
            'candidate with 0xFF' => ["AivTest-D1Xff/1.0 bot \xff end", 'candidate'],
            'known bot with 0xFF' => ["Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot) \xff", 'hit'],
            'known bot with DEL' => ["Mozilla/5.0 (compatible; GPTBot/1.1) \x7f", 'hit'],
        ];
    }

    /**
     * Fixed (minor); on the first build TrackRequest reads the UA via Request::getHeader(), which throws "Invalid header
     * value detected" (laminas HeaderValue) for bytes 0xFF / DEL / control characters. The hit is silently lost
     * and one warning per request is written to system.log (reproduced live on shop.test with a raw socket).
     *
     * @dataProvider invalidHeaderByteProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidHeaderByteProvider')]
    public function testFixedUserAgentWithInvalidHeaderByteIsStillRecorded(string $userAgent, string $kind): void
    {
        $this->observer()->execute($this->realRequestEvent($userAgent, '/aivtest-d1-bytes'));
        $this->assertSame([], $this->warnings, 'No warning per request for an odd User-Agent');
        $this->assertCount(1, $kind === 'hit' ? $this->hits : $this->candidateHits);
    }

    public function testCandidateKeyIsSanitisedAndCut(): void
    {
        $ua = "AivTest-D1Big/1.0 bot \x01\x1b[31m" . str_repeat('A', 700) . "\xfe";
        $this->writer(1000)->recordCandidateHit(1, $ua, '/llms.txt', false, 200, 5, 'php', 1700000000);
        $candidate = $this->queriesOn('panth_aiv_candidate')[0];
        [$type, $keyHash, $keyValue] = $candidate[1];
        $this->assertSame('bot', $type);
        $this->assertSame(sha1(strtolower(trim($ua))), $keyHash);
        $this->assertSame(512, mb_strlen($keyValue, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($keyValue, 'UTF-8'));
        $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $keyValue));
        $this->assertSame(1, $candidate[1][5], 'llms_fetches + 1 on insert');
        $this->assertSame('cand:7', $this->inserts[0][1]['bot_code']);
        $this->assertSame('unknown', $this->inserts[0][1]['category']);
    }

    // ------------------------------------------------------------------ TC1.07 path handling

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function pathProvider(): array
    {
        return [
            'query' => ['/a/b.html?x=1&y=2', '/a/b.html', true],
            'empty query' => ['/a?', '/a', false],
            'fragment' => ['/a#top', '/a', false],
            'absolute form' => ['https://shop.test/a/b?x', '/a/b', true],
            'double slashes' => ['//a//b///c', '/a/b/c', false],
            'utf8 encoded' => ['/caf%C3%A9', '/café', false],
            'utf8 raw' => ['/café', '/café', false],
            'invalid utf8 stays encoded' => ['/bad%FF%FE', '/bad%FF%FE', false],
            'nul stays encoded' => ['/nul%00x', '/nul%00x', false],
            'percent decoded once' => ['/pct%25', '/pct%', false],
            'no double decoding' => ['/dbl%253F', '/dbl%3F', false],
            'empty' => ['', '/', false],
            'relative' => ['relative', '/relative', false],
            'index.php kept' => ['/index.php/gear.html', '/index.php/gear.html', false],
            'xss decoded as data' => ['/x%3Cscript%3E', '/x<script>', false],
        ];
    }

    /**
     * @dataProvider pathProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pathProvider')]
    public function testPathNormalisation(string $uri, string $path, bool $hasQuery): void
    {
        [$actualPath, $actualQuery] = TrackRequest::splitUri($uri);
        $this->assertSame($path, HitWriter::cleanPath($actualPath));
        $this->assertSame($hasQuery, $actualQuery);
    }

    public function testLongMultibytePathIsCutTo512CharactersOnACharacterBoundary(): void
    {
        [$path] = TrackRequest::splitUri('/' . str_repeat('%C3%A9', 600) . '?q=1');
        $stored = HitWriter::cleanPath($path);
        $this->assertSame(512, mb_strlen($stored, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function reservedCharacterProvider(): array
    {
        return [
            'encoded question mark' => ['/aivtest-encq%3Fsecret%3D1', 'secret'],
            'encoded hash' => ['/aivtest-ench%23frag', 'frag'],
            'raw invalid byte' => ["/aivtest-raw\xffsegment", 'segment'],
        ];
    }

    /**
     * Fixed (minor); on the first build splitUri() decodes %3F / %23 (or turns an invalid raw byte into "?"), then
     * HitWriter::cleanPath() treats that character as the start of a query or fragment and cuts the path.
     * Distinct URLs collapse into one path_hash and the tail of the real path is lost.
     *
     * @dataProvider reservedCharacterProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reservedCharacterProvider')]
    public function testFixedEncodedReservedCharacterDoesNotCutTheStoredPath(string $uri, string $tail): void
    {
        [$path, $hasQuery] = TrackRequest::splitUri($uri);
        $this->assertFalse($hasQuery);
        $this->assertStringContainsString($tail, HitWriter::cleanPath($path));
    }

    // ------------------------------------------------------------------ TC1.08 skips

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function skipProvider(): array
    {
        return [
            'collector' => ['/panth_aiv/collect', true],
            'collector upper' => ['/PANTH_AIV/collect', true],
            'collector index.php' => ['/index.php/panth_aiv/collect/index', true],
            'collector store code' => ['/luma/panth_aiv/collect', true],
            'collector bare' => ['/panth_aiv', true],
            'static' => ['/static/version1/frontend/x.js', true],
            'pub static' => ['/pub/static/x.css', true],
            'media image' => ['/media/catalog/product/x.jpg', true],
            'media sitemap' => ['/media/sitemap.xml', false],
            'media sitemap gz in folder' => ['/media/sitemap/sitemap-1-1.xml.gz', false],
            'pub media sitemap' => ['/pub/media/sitemap_products.xml', false],
            'media non sitemap xml' => ['/media/feed.xml', true],
            'page' => ['/gear.html', false],
            'static-like page' => ['/static-page.html', false],
            'media-like page' => ['/mediakit', false],
        ];
    }

    /**
     * @dataProvider skipProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('skipProvider')]
    public function testSkippedPaths(string $path, bool $skipped): void
    {
        $this->assertSame($skipped, TrackRequest::isSkippedPath($path));
    }

    // ------------------------------------------------------------------ TC1.09 cap boundary, TC1.06 statuses

    public function testCapBoundaryIsExactAndOverflowKeepsTheStatusClass(): void
    {
        $writer = $this->writer(3);
        $bot = new BotMatch('diffbot', 'Diffbot', 'Diffbot', 'other');
        $statuses = [200, 404, 301, 200, 404, 503, 0, 302];
        foreach ($statuses as $status) {
            $writer->recordHit(1, $bot, '/aivtest-d1-cap', false, $status, 10, 'php', 1700000000);
        }
        $this->assertCount(3, $this->inserts, 'Counter values 1..cap are raw rows');
        $overflow = $this->queriesOn('panth_aiv_overflow_daily');
        $this->assertCount(5, $overflow, 'Counter values above the cap go to overflow');
        $this->assertSame([2, 4, 5, 0, 3], array_map(fn ($q) => $q[1][4], $overflow));
        $this->assertSame(8, $this->counter, 'Every hit bumps the counter exactly once');
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function statusClassProvider(): array
    {
        return [
            'unknown' => [0, 0], 'informational' => [100, 0], '199' => [199, 0], 'ok' => [200, 2],
            'redirect' => [308, 3], 'not found' => [404, 4], 'unavailable' => [503, 5], '599' => [599, 5],
            '600' => [600, 0], '999' => [999, 0],
        ];
    }

    /**
     * @dataProvider statusClassProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('statusClassProvider')]
    public function testStatusClass(int $status, int $class): void
    {
        $this->assertSame($class, HitWriter::statusClass($status));
    }

    public function testStatusFromTheRealResponsePath(): void
    {
        $ua = 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)';
        foreach ([200, 301, 302, 404, 410, 500, 503] as $status) {
            $this->observer()->execute($this->realRequestEvent($ua, '/aivtest-d1-s', $status));
        }
        $this->assertSame([200, 301, 302, 404, 410, 500, 503], array_map(fn ($h) => $h[4], $this->hits));
        foreach ($this->hits as $hit) {
            $this->assertGreaterThanOrEqual(40, $hit[5], 'duration from REQUEST_TIME_FLOAT');
        }
    }

    // ------------------------------------------------------------------ day boundaries / time zones

    public function testUtcDayAndTimestampIgnoreThePhpTimezone(): void
    {
        date_default_timezone_set('Pacific/Kiritimati');
        $writer = $this->writer(1000);
        $bot = new BotMatch('gptbot', 'GPTBot', 'OpenAI', 'training');
        $writer->recordHit(1, $bot, '/a', false, 200, 1, 'php', gmmktime(23, 59, 59, 12, 31, 2026));
        $writer->recordHit(1, $bot, '/a', false, 200, 1, 'php', gmmktime(0, 0, 0, 1, 1, 2027));
        $this->assertSame('2026-12-31 23:59:59', $this->inserts[0][1]['created_at']);
        $this->assertSame('2027-01-01 00:00:00', $this->inserts[1][1]['created_at']);
        $counters = $this->queriesOn('panth_aiv_counter');
        $this->assertSame(['raw_hits', '2026-12-31'], $counters[0][1]);
        $this->assertSame(['raw_hits', '2027-01-01'], $counters[1][1]);
    }

    public function testCatalogueTokensAreLowerCaseSafeAndExclusionsCoverListedBots(): void
    {
        foreach (BotCatalogue::NON_AI_TOKENS as $token) {
            $this->assertSame(strtolower($token), $token);
        }
        foreach (BotCatalogue::BOTS as $code => $bot) {
            $this->assertFalse(BotCatalogue::isNonAi($bot[3]), 'Built-in AI token hits an exclusion: ' . $code);
        }
    }
}
