<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Helper\Context as HelperContext;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Panth\AiVisibility\Api\AttributionCookieInterface;
use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Block\Collector;
use Panth\AiVisibility\Controller\Collect\Index;
use Panth\AiVisibility\Model\Catalogue\SourceCatalogue;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Model\SourceMatch;
use Panth\AiVisibility\Service\AttributionCookie;
use Panth\AiVisibility\Service\ConsentChecker;
use Panth\AiVisibility\Service\RateLimiter;
use Panth\AiVisibility\Service\SourceClassifier;
use Panth\AiVisibility\Service\VisitWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep test chunk D2: collector, attribution cookie, consent, rate limit, snippet.
 *
 * Two tests in this class prove the fix of a defect found during the deep test (both failed on the first build):
 *  - testCollectorRouteDoesNotStartAPhpSession (TC2.13, every beacon starts and locks a PHP session)
 *  - testOneFloodingClientCannotExhaustTheGlobalBudget (one client can switch collection off for everyone)
 */
class CollectorDeepTest extends TestCase
{
    private const NOW = 1793346000;
    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/129.0 Safari/537.36';

    /**
     * @var array<int, array>
     */
    private array $calls = [];

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

    /**
     * @var array<string, int>
     */
    private array $rows = [];

    /**
     * @var int
     */
    private int $lastId = 0;

    /**
     * @var string|null
     */
    private ?string $cookieValue = null;

    /**
     * @var array<int, array>
     */
    private array $written = [];

    // ------------------------------------------------------------------------------------------------------------
    // TC2.13 - the collector must not start (and lock) a PHP session
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Found live on a test store (shop.test, 2026-10-30): on the first build every POST /panth_aiv/collect/index answers with
     * "Set-Cookie: PHPSESSID=...; Max-Age=3600" (plus a new private_content_version), also for dropped beacons.
     * Cause: the collector is dispatched as a normal frontend action, so Magento\Customer\Model\App\Action\
     * ContextPlugin::beforeExecute (plugin on ActionInterface) reads the customer session, which starts it
     * (redis, locking on). Visitors with a session get it locked for the beacon; visitors without one get a new
     * session (and a cookie set before consent). Expected: a SessionStartChecker afterCheck plugin for the
     * collector path (like Magento_Paypal's TransparentSessionChecker) or a front-controller short-circuit.
     */
    public function testCollectorRouteDoesNotStartAPhpSession(): void
    {
        $this->assertTrue(
            is_subclass_of(Index::class, ActionInterface::class),
            'precondition: the collector is dispatched as a frontend action'
        );
        $moduleDir = dirname(__DIR__, 3);
        $guarded = false;
        foreach (['etc/di.xml', 'etc/frontend/di.xml'] as $file) {
            if (!is_file($moduleDir . '/' . $file)) {
                continue;
            }
            $xml = simplexml_load_file($moduleDir . '/' . $file);
            foreach ($xml->xpath('//type') ?: [] as $type) {
                $name = ltrim((string)$type['name'], '\\');
                if (in_array($name, [
                    'Magento\Framework\Session\SessionStartChecker',
                    'Magento\Framework\App\FrontControllerInterface',
                    'Magento\Framework\App\FrontController',
                ], true) && count($type->plugin) > 0) {
                    $guarded = true;
                }
            }
        }
        $this->assertTrue(
            $guarded,
            'POST /panth_aiv/collect starts a PHP session on every beacon (customer ContextPlugin on ActionInterface);'
            . ' no SessionStartChecker / FrontController plugin keeps the collector session-free'
        );
    }

    // ------------------------------------------------------------------------------------------------------------
    // TC2.05 - rate limiter
    // ------------------------------------------------------------------------------------------------------------

    private function limiter(): RateLimiter
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(fn ($v) => '`' . $v . '`');
        $connection->method('query')->willReturnCallback(function (string $sql, array $bind) {
            $this->rows[$bind[0]] = ($this->rows[$bind[0]] ?? 0) + 1;
            $this->lastId = $this->rows[$bind[0]];
            return $this->createStub(\Zend_Db_Statement_Interface::class);
        });
        $connection->method('fetchOne')->willReturnCallback(fn () => (string)$this->lastId);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturn("old-test-key\nnew-test-key");
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);
        return new RateLimiter($resource, $this->createStub(CacheInterface::class), $deployment, $dateTime);
    }

    public function testTwentyFirstRequestInAMinuteIsDropped(): void
    {
        $limiter = $this->limiter();
        $allowed = 0;
        for ($i = 0; $i < 21; $i++) {
            $allowed += $limiter->allow('192.0.2.10', 20) ? 1 : 0;
        }
        $this->assertSame(20, $allowed);
        foreach (array_keys($this->rows) as $bucket) {
            $this->assertStringNotContainsString('192.0.2.10', $bucket);
        }
    }

    /**
     * Fixed: the first build counted the global bucket BEFORE the per-IP check, so requests the per-IP limit
     * already rejected still used up the global budget (limit x 50) and one flooding client blinded the
     * collector for every visitor. The per-IP bucket is now checked first; only allowed requests count globally.
     */
    public function testOneFloodingClientCannotExhaustTheGlobalBudget(): void
    {
        $limiter = $this->limiter();
        $allowed = 0;
        for ($i = 0; $i < 1001; $i++) {
            $allowed += $limiter->allow('192.0.2.66', 20) ? 1 : 0;
        }
        $this->assertSame(20, $allowed, 'the flooding client itself gets exactly its 20');
        $this->assertTrue(
            $limiter->allow('192.0.2.77', 20),
            'a different visitor is dropped because the rejected requests of 192.0.2.66 used up the global budget'
        );
    }

    // ------------------------------------------------------------------------------------------------------------
    // TC2.02 / TC2.08 - host and utm classification (real catalogue)
    // ------------------------------------------------------------------------------------------------------------

    private function classifier(): SourceClassifier
    {
        $config = $this->createStub(Config::class);
        $config->method('customSourceHosts')->willReturn([]);
        $config->method('customUtm')->willReturn([]);
        $rules = $this->createStub(RuleProvider::class);
        $rules->method('sourceRules')->willReturn([]);
        return new SourceClassifier(new SourceCatalogue(), $config, $rules);
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: bool}>
     */
    public static function hosts(): array
    {
        return [
            'exact' => ['chatgpt.com', 'chatgpt', false],
            'subdomain' => ['www.chatgpt.com', 'chatgpt', false],
            'uppercase' => ['CHATGPT.COM', 'chatgpt', false],
            'trailing dot' => ['chatgpt.com.', 'chatgpt', false],
            'prefix look-alike' => ['notchatgpt.com', null, false],
            'hyphen look-alike' => ['evil-chatgpt.com', null, false],
            'suffix look-alike' => ['chatgpt.com.evil.example.invalid', null, false],
            'with port' => ['chatgpt.com:443', null, false],
            'punycode look-alike' => ['xn--chatgt-9za.com', null, false],
            'second openai host' => ['chat.openai.com', 'chatgpt', false],
            'openai root is not chatgpt' => ['openai.com', null, false],
            'gemini' => ['gemini.google.com', 'gemini', false],
            'other google host' => ['mail.google.com', null, false],
            'google chat is not ai' => ['chat.google.com', null, false],
            'facebook ai subdomain' => ['ai.facebook.com', null, false],
            'youtube is not you.com' => ['youtube.com', null, false],
            'you.com' => ['you.com', 'you', false],
            'copilot cloud' => ['copilot.cloud.microsoft', 'copilot', false],
            'claude look-alike candidate' => ['claude.ai.evil.example.invalid', null, true],
            'generic ai tld candidate' => ['notgoogle.ai', null, true],
            'agent candidate' => ['my-agent.example.invalid', null, true],
            'idn ai candidate' => ['xn--bcher-kva.ai', null, true],
            'plain site' => ['example.invalid', null, false],
            'empty' => ['', null, false],
        ];
    }

    /**
     * @dataProvider hosts
     */
    #[DataProvider('hosts')]
    public function testHostClassification(string $host, ?string $code, bool $candidate): void
    {
        $classifier = $this->classifier();
        $this->assertSame($code, $classifier->classifyHost($host)?->code);
        $this->assertSame($candidate, $classifier->isCandidateHost($host));
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: bool}>
     */
    public static function utms(): array
    {
        return [
            'chatgpt' => ['chatgpt', 'chatgpt', false],
            'chatgpt.com (what chatgpt appends)' => ['chatgpt.com', 'chatgpt', false],
            'mixed case' => ['ChatGPT', 'chatgpt', false],
            'claude.ai' => ['claude.ai', 'claude', false],
            'candidate' => ['chat-gpt', null, true],
            'underscore is not a delimiter' => ['my_ai', null, false],
            'newsletter' => ['newsletter', null, false],
            'too long' => [str_repeat('a', 65), null, false],
        ];
    }

    /**
     * @dataProvider utms
     */
    #[DataProvider('utms')]
    public function testUtmClassification(string $utm, ?string $code, bool $candidate): void
    {
        $classifier = $this->classifier();
        $this->assertSame($code, $classifier->classifyUtm($utm)?->code);
        $this->assertSame($candidate, $classifier->isCandidateUtm($utm));
    }

    // ------------------------------------------------------------------------------------------------------------
    // TC2.06 - attribution cookie
    // ------------------------------------------------------------------------------------------------------------

    private function cookie(int $now = self::NOW, bool $consent = true, string $key = "old-test-key\nnew-test-key"): AttributionCookie
    {
        $manager = $this->createStub(CookieManagerInterface::class);
        $manager->method('getCookie')->willReturnCallback(fn () => $this->cookieValue);
        $manager->method('setPublicCookie')->willReturnCallback(
            function (string $name, string $value, PublicCookieMetadata $metadata) {
                $this->written[] = ['name' => $name, 'value' => $value, 'metadata' => $metadata];
                $this->cookieValue = $value;
            }
        );
        $factory = $this->createStub(CookieMetadataFactory::class);
        $factory->method('createPublicCookieMetadata')->willReturnCallback(fn () => new PublicCookieMetadata());
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(fn ($path) => $path === 'crypt/key' ? $key : null);
        $config = $this->createStub(Config::class);
        $config->method('attributionWindowDays')->willReturn(30);
        $consentChecker = $this->createStub(ConsentChecker::class);
        $consentChecker->method('canSetCookie')->willReturn($consent);
        $request = $this->createStub(RequestInterface::class);
        $request->method('isSecure')->willReturn(true);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn($now);
        return new AttributionCookie(
            $manager,
            $factory,
            $deployment,
            $config,
            $consentChecker,
            $request,
            $dateTime,
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testExpiryBoundaryIsExactlyTheWindow(): void
    {
        $lt = self::NOW - 30 * 86400;
        $this->cookieValue = $this->cookie()->sign(['f' => 'grok', 'ft' => $lt, 'l' => 'grok', 'lt' => $lt, 'lp' => '/']);
        $this->assertNotNull($this->cookie()->read(), 'last touch exactly 30 days ago is still inside the window');
        $this->assertNull($this->cookie(self::NOW + 1)->read(), 'one second later it has expired');
    }

    public function testForeignKeyAndRotatedKeyAreRejected(): void
    {
        $value = $this->cookie()->sign(['f' => 'claude', 'ft' => self::NOW, 'l' => 'claude', 'lt' => self::NOW, 'lp' => '/']);
        $this->assertNotNull($this->cookie()->verify($value));
        $this->assertNull($this->cookie(self::NOW, true, 'another-installation')->verify($value));
        // after encryption:key:change the newest key signs and verifies; older cookies stop counting
        $this->assertNull($this->cookie(self::NOW, true, "old-test-key\nnew-test-key\nnewer-test-key")->verify($value));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function brokenValues(): array
    {
        return [
            'base64 with padding' => ['eyJmIjoiY2xhdWRlIn0=.c2ln'],
            'standard alphabet' => ['ab+c/d.ef+g/h'],
            'url-encoded junk' => ['%25%25%25.%23%23%23'],
            'three parts' => ['a.b.c'],
            'no dot' => ['abcdef'],
            'only dot' => ['.'],
            'over 4096' => [str_repeat('a', 4100) . '.b'],
            'nul byte' => ["eyJm\0.abc"],
        ];
    }

    /**
     * @dataProvider brokenValues
     */
    #[DataProvider('brokenValues')]
    public function testBrokenCookieValuesAreIgnoredAndOverwrittenWithAFreshFirstTouch(string $value): void
    {
        $this->cookieValue = $value;
        $cookie = $this->cookie();
        $this->assertNull($cookie->read());
        $cookie->touch(new SourceMatch('perplexity', 'Perplexity'), '/aivtest-d2');
        $payload = $cookie->verify((string)$this->cookieValue);
        $this->assertSame('perplexity', $payload['f'] ?? null);
        $this->assertSame(self::NOW, $payload['ft'] ?? null);
    }

    public function testFirstTouchKeptLastTouchUpdatedAndCookieFlags(): void
    {
        $this->cookieValue = $this->cookie()->sign(
            ['f' => 'claude', 'ft' => self::NOW - 86400, 'l' => 'claude', 'lt' => self::NOW - 86400, 'lp' => '/first']
        );
        $this->cookie()->touch(new SourceMatch('perplexity', 'Perplexity'), '/second?q=1#x');
        $this->assertCount(1, $this->written);
        $payload = $this->cookie()->verify($this->written[0]['value']);
        $this->assertSame(
            ['f' => 'claude', 'ft' => self::NOW - 86400, 'l' => 'perplexity', 'lt' => self::NOW, 'lp' => '/second'],
            $payload
        );
        /** @var PublicCookieMetadata $metadata */
        $metadata = $this->written[0]['metadata'];
        $this->assertSame('panth_aiv', $this->written[0]['name']);
        $this->assertSame(30 * 86400, $metadata->getDuration());
        $this->assertTrue($metadata->getHttpOnly());
        $this->assertTrue($metadata->getSecure());
        $this->assertSame('/', $metadata->getPath());
        $this->assertSame('Lax', $metadata->getSameSite());
    }

    public function testLargestPossibleCookieStaysFarBelow4Kb(): void
    {
        $cookie = $this->cookie();
        $cookie->touch(new SourceMatch(str_repeat('c', 64), 'x'), '/' . str_repeat('%E2', 300));
        $value = (string)$this->cookieValue;
        $this->assertNotSame('', $value);
        $this->assertLessThan(1024, strlen('panth_aiv=' . $value));
    }

    public function testNoConsentMeansNoCookieButNoError(): void
    {
        $this->cookie(self::NOW, false)->touch(new SourceMatch('chatgpt', 'ChatGPT'), '/x');
        $this->assertSame([], $this->written);
    }

    // ------------------------------------------------------------------------------------------------------------
    // TC2.07 - consent with the real Magento cookie helper
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: bool, 1: mixed, 2: bool, 3: bool}>
     */
    public static function consentCases(): array
    {
        return [
            'restriction off' => [false, null, true, true],
            'restriction on, no consent cookie' => [true, null, true, false],
            'restriction on, accepted for website 1' => [true, '{"1":1}', true, true],
            'restriction on, forged 0' => [true, '{"1":0}', true, false],
            'restriction on, other website' => [true, '{"2":1}', true, false],
            'restriction on, broken json' => [true, '{"1":', true, false],
            'restriction on, cookie sent as array' => [true, ['1'], true, false],
            'restriction on, respect consent off' => [true, null, false, true],
        ];
    }

    /**
     * @dataProvider consentCases
     */
    #[DataProvider('consentCases')]
    public function testConsentWithRealCookieHelper(
        bool $restriction,
        mixed $consentCookie,
        bool $respect,
        bool $expected
    ): void {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getCookie')->willReturnCallback(
            fn ($name, $default = null) => $name === 'user_allowed_save_cookie' && $consentCookie !== null
                ? $consentCookie : $default
        );
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn ($path) => $path === 'web/cookie/cookie_restriction' ? ($restriction ? '1' : '0') : null
        );
        $context = $this->createStub(HelperContext::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getScopeConfig')->willReturn($scopeConfig);
        $website = $this->createStub(Website::class);
        $website->method('getId')->willReturn(1);
        $helper = new CookieHelper(
            $context,
            $this->createStub(StoreManagerInterface::class),
            ['current_store' => $this->createStub(Store::class), 'website' => $website]
        );
        $config = $this->createStub(Config::class);
        $config->method('respectCookieConsent')->willReturn($respect);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message) {
            $this->warnings[] = $message;
        });
        $this->assertSame($expected, (new ConsentChecker($config, $helper, $logger))->canSetCookie());
    }

    // ------------------------------------------------------------------------------------------------------------
    // TC2.03 / TC2.04 / TC2.08 - controller edge cases with the real classifier
    // ------------------------------------------------------------------------------------------------------------

    private function controller(string $body, array $o = []): Index
    {
        $o += ['ua' => self::BROWSER, 'origin' => 'https://shop.test', 'referer' => null, 'candidateId' => 7];
        $request = $this->createStub(HttpRequest::class);
        $request->method('getContent')->willReturn($body);
        $request->method('getServer')->willReturnCallback(fn (string $key) => match ($key) {
            'HTTP_USER_AGENT' => $o['ua'],
            'HTTP_ORIGIN' => $o['origin'],
            'HTTP_REFERER' => $o['referer'],
            default => null,
        });
        $result = $this->createStub(Raw::class);
        $result->method('setHttpResponseCode')->willReturnSelf();
        $result->method('setHeader')->willReturnSelf();
        $result->method('setContents')->willReturnSelf();
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($result);
        $stores = [];
        foreach (['shop.test' => 1, 'other-shop.test' => 2] as $host => $id) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn($id);
            $store->method('getBaseUrl')->willReturn('https://' . $host . '/');
            $stores[] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($stores[0]);
        $storeManager->method('getStores')->willReturn($stores);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('trackVisits')->willReturn(true);
        $config->method('discoveryEnabled')->willReturn(true);
        $config->method('landingSampleRate')->willReturn(25);
        $config->method('rateLimitPerMinute')->willReturn(20);
        $config->method('customSourceHosts')->willReturn([]);
        $config->method('customUtm')->willReturn([]);
        $bots = $this->createStub(BotClassifierInterface::class);
        $bots->method('classify')->willReturn(null);
        $bots->method('isDiscoveryCandidate')->willReturn(false);
        $rules = $this->createStub(RuleProvider::class);
        $rules->method('sourceRules')->willReturn([]);
        $limiter = $this->createStub(RateLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $writer = $this->createStub(VisitWriter::class);
        foreach (['insertVisit', 'insertLanding'] as $method) {
            $writer->method($method)->willReturnCallback(function (...$args) use ($method) {
                $this->calls[] = [$method, $args];
            });
        }
        $writer->method('upsertSourceCandidate')->willReturnCallback(function (string $key) use ($o) {
            $this->calls[] = ['upsertSourceCandidate', [$key]];
            return $o['candidateId'];
        });
        $cookie = $this->createStub(AttributionCookieInterface::class);
        $cookie->method('touch')->willReturnCallback(function (SourceMatch $source, string $path) {
            $this->calls[] = ['touch', [$source->code, $path]];
        });
        $remote = $this->createStub(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturn('198.51.100.7');
        return new Index(
            $request,
            $rawFactory,
            $storeManager,
            $config,
            $bots,
            new SourceClassifier(new SourceCatalogue(), $config, $rules),
            $limiter,
            $writer,
            $cookie,
            $remote,
            $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function droppedBodies(): array
    {
        $ok = ['t' => 'ai', 'h' => 'chatgpt.com', 'u' => '', 'p' => '/aivtest-d2'];
        return [
            'utf-8 bom' => ["\u{feff}" . json_encode($ok)],
            'invalid utf-8' => ["{\"t\":\"ai\",\"h\":\"chatgpt.com\",\"u\":\"\",\"p\":\"/a\xff\"}"],
            'huge number utm' => ['{"t":"ai","h":"","u":1e999,"p":"/a"}'],
            'uppercase type' => [(string)json_encode(['t' => 'AI'] + $ok)],
            'tab in path' => [(string)json_encode(['p' => "/a\tb"] + $ok)],
            'nul in path' => [(string)json_encode(['p' => "/a\0b"] + $ok)],
            'del in path' => [(string)json_encode(['p' => "/a\x7fb"] + $ok)],
            'host is another store (internal)' => [(string)json_encode(['h' => 'other-shop.test'] + $ok)],
            'non-ai host claimed as candidate' => [(string)json_encode(['t' => 'cand', 'h' => 'ai.facebook.com'] + $ok)],
            'google chat claimed as candidate' => [(string)json_encode(['t' => 'cand', 'h' => 'chat.google.com'] + $ok)],
            'look-alike claimed as ai' => [(string)json_encode(['h' => 'notchatgpt.com'] + $ok)],
            'landing on home' => ['{"t":"land","p":"/"}'],
            'form encoded' => ['t=ai&h=chatgpt.com&p=/a'],
        ];
    }

    /**
     * @dataProvider droppedBodies
     */
    #[DataProvider('droppedBodies')]
    public function testDroppedBodies(string $body): void
    {
        $this->controller($body)->execute();
        $this->assertSame([], $this->calls);
    }

    public function testEscapedJsonIsDecodedBeforeValidation(): void
    {
        $this->controller('{"t":"land","p":"\/aivtest-d2-a"}')->execute();
        $this->assertSame([['insertLanding', [1, '/aivtest-d2-a']]], $this->calls);
    }

    public function testDuplicateKeysLastOneWins(): void
    {
        $this->controller('{"t":"ai","h":"chatgpt.com","u":"","p":"/a","t":"land"}')->execute();
        $this->assertSame([['insertLanding', [1, '/a']]], $this->calls);
    }

    public function testCandidateVisitDoesNotTouchTheAttributionCookie(): void
    {
        $this->controller((string)json_encode(['t' => 'cand', 'h' => 'www.aivtest-ai.example.invalid', 'u' => '', 'p' => '/c']))
            ->execute();
        $this->assertSame([
            ['upsertSourceCandidate', ['host:aivtest-ai.example.invalid']],
            ['insertVisit', [1, 'cand:7', 'referrer', 'www.aivtest-ai.example.invalid', '/c']],
        ], $this->calls);
    }

    public function testNonAiReferrerWithAiUtmIsAnUtmVisitKeepingTheHost(): void
    {
        $this->controller((string)json_encode(['t' => 'ai', 'h' => 'www.google.com', 'u' => 'chatgpt.com', 'p' => '/u']))
            ->execute();
        $this->assertSame([
            ['insertVisit', [1, 'chatgpt', 'utm', 'www.google.com', '/u']],
            ['touch', ['chatgpt', '/u']],
        ], $this->calls);
    }

    public function testOriginNullFallsBackToTheReferer(): void
    {
        $body = (string)json_encode(['t' => 'ai', 'h' => 'claude.ai', 'u' => '', 'p' => '/n']);
        $this->controller($body, ['origin' => 'null', 'referer' => 'https://evil.example.invalid/'])->execute();
        $this->assertSame([], $this->calls, 'foreign referer behind an opaque origin is dropped');
        $this->controller($body, ['origin' => 'null', 'referer' => 'https://shop.test/p'])->execute();
        $this->assertCount(2, $this->calls);
    }

    // ------------------------------------------------------------------------------------------------------------
    // TC2.10 - snippet
    // ------------------------------------------------------------------------------------------------------------

    public function testSnippetIsSmallAndDependencyFree(): void
    {
        $this->assertLessThan(2048, strlen(Collector::SCRIPT));
        foreach (['require(', 'jQuery', 'define(', 'document.write', 'eval(', 'innerHTML', 'document.cookie'] as $token) {
            $this->assertStringNotContainsString($token, Collector::SCRIPT);
        }
        $this->assertStringContainsString("credentials:'same-origin'", Collector::SCRIPT);
        $this->assertStringContainsString("'Content-Type':'text/plain'", Collector::SCRIPT);
        $this->assertStringContainsString('sessionStorage', Collector::SCRIPT);
    }
}
