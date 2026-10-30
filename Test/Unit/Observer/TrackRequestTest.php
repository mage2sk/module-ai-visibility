<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Observer;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\HitWriterInterface;
use Panth\AiVisibility\Model\BotMatch;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Observer\TrackRequest;
use Panth\AiVisibility\Service\BotClassifier;
use Panth\AiVisibility\Service\PageTypeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TrackRequestTest extends TestCase
{
    private const GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; '
        . '+https://openai.com/gptbot)';
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/130.0.0.0 Safari/537.36';
    private const CANDIDATE = 'SimAgent/1.0 (+https://example.invalid/bot)';

    /**
     * @var array<string, mixed>
     */
    private array $settings = [];

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
     * @var int
     */
    private int $crawlerSourceCalls = 0;

    /**
     * @var string
     */
    private string $area = 'frontend';

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $writerError = null;

    protected function setUp(): void
    {
        $this->settings = [
            'enabled' => true,
            'track_crawlers' => true,
            'discovery' => true,
            'source' => Config::CRAWLER_SOURCE_MAGENTO,
        ];
    }

    private function observer(): TrackRequest
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn ($storeId) => $this->settings['enabled'] && $storeId === 3);
        $config->method('trackCrawlers')->willReturnCallback(fn () => $this->settings['track_crawlers']);
        $config->method('discoveryEnabled')->willReturnCallback(fn () => $this->settings['discovery']);
        $config->method('customBotPatterns')->willReturn([]);
        $config->method('crawlerSource')->willReturnCallback(function () {
            $this->crawlerSourceCalls++;
            return $this->settings['source'];
        });

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('3');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturnCallback(function () {
            if ($this->area === '') {
                throw new \RuntimeException('Area code is not set');
            }
            return $this->area;
        });

        $ruleProvider = $this->createStub(RuleProvider::class);
        $ruleProvider->method('botRules')->willReturn([]);

        $writer = $this->createStub(HitWriterInterface::class);
        $writer->method('recordHit')->willReturnCallback(function (...$args) {
            if ($this->writerError) {
                throw $this->writerError;
            }
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
            $config,
            $storeManager,
            $state,
            new BotClassifier($config, $ruleProvider),
            new PageTypeResolver(),
            $writer,
            $logger
        );
    }

    private function event(
        string $userAgent,
        string $uri = '/joust-duffle-bag.html',
        int $status = 200,
        $requestTime = null
    ): Observer {
        $server = ['REQUEST_URI' => $uri, 'REQUEST_TIME_FLOAT' => $requestTime ?? microtime(true)];
        $request = $this->createStub(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(
            fn ($name) => $name === 'User-Agent' ? ($userAgent === '' ? false : $userAgent) : false
        );
        $request->method('getServerValue')->willReturnCallback(fn ($name) => $server[$name] ?? null);
        $request->method('getRequestUri')->willReturn('/fallback.html');
        $response = $this->createStub(HttpResponse::class);
        $response->method('getHttpResponseCode')->willReturn($status);
        return new Observer(['event' => new Event(['request' => $request, 'response' => $response])]);
    }

    public function testKnownBotHitIsRecorded(): void
    {
        $this->observer()->execute($this->event(self::GPTBOT, '/gear/bags.html?p=2', 200, microtime(true) - 0.25));

        $this->assertCount(1, $this->hits);
        [$storeId, $bot, $path, $hasQuery, $status, $duration] = $this->hits[0];
        $this->assertSame(3, $storeId);
        $this->assertInstanceOf(BotMatch::class, $bot);
        $this->assertSame('gptbot', $bot->code);
        $this->assertSame('/gear/bags.html', $path);
        $this->assertTrue($hasQuery);
        $this->assertSame(200, $status);
        $this->assertGreaterThanOrEqual(250, $duration);
        $this->assertLessThan(5000, $duration);
        $this->assertSame([], $this->candidateHits);
    }

    public function testHumanRequestDoesNoWorkBeyondStringChecks(): void
    {
        $this->observer()->execute($this->event(self::CHROME));
        $this->observer()->execute($this->event(self::CHROME, '/llms-full.txt.bak'));
        $this->assertSame([], $this->hits);
        $this->assertSame([], $this->candidateHits);
        $this->assertSame(0, $this->crawlerSourceCalls, 'Store lookups for log import only run for bots');
        $this->assertSame([], $this->warnings);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function statusProvider(): array
    {
        return [
            'ok' => [200],
            'redirect' => [301],
            'not found' => [404],
            'error' => [503],
        ];
    }

    /**
     * @dataProvider statusProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('statusProvider')]
    public function testStatusComesFromTheResponse(int $status): void
    {
        $this->observer()->execute($this->event(self::GPTBOT, '/x.html', $status));
        $this->assertSame($status, $this->hits[0][4]);
    }

    public function testImplausibleStatusAndMissingResponseGiveZero(): void
    {
        $this->observer()->execute($this->event(self::GPTBOT, '/x.html', 0));
        $this->assertSame(0, $this->hits[0][4]);

        $request = $this->createStub(HttpRequest::class);
        $request->method('getHeader')->willReturn(self::GPTBOT);
        $request->method('getServerValue')->willReturnCallback(fn ($name) => $name === 'REQUEST_URI' ? '/y' : null);
        $this->observer()->execute(new Observer(['event' => new Event(['request' => $request])]));
        $this->assertSame(0, $this->hits[1][4]);
        $this->assertSame(0, $this->hits[1][5], 'No REQUEST_TIME_FLOAT gives duration 0');
    }

    public function testDurationIsNeverNegative(): void
    {
        $this->observer()->execute($this->event(self::GPTBOT, '/x.html', 200, microtime(true) + 100));
        $this->assertSame(0, $this->hits[0][5]);
        $this->observer()->execute($this->event(self::GPTBOT, '/x.html', 200, 'garbage'));
        $this->assertSame(0, $this->hits[1][5]);
    }

    public function testRequestUriFallback(): void
    {
        $this->observer()->execute($this->event(self::GPTBOT, ''));
        $this->assertSame('/fallback.html', $this->hits[0][2]);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function skipProvider(): array
    {
        return [
            'static' => ['/static/version123/frontend/x/y/en_US/css/styles.css', true],
            'media image' => ['/media/catalog/product/a/b/ab.jpg', true],
            'pub static' => ['/pub/static/frontend/a.js', true],
            'collector' => ['/panth_aiv/collect/index/', true],
            'collector root' => ['/panth_aiv', true],
            'collector upper' => ['/PANTH_AIV/collect', true],
            'media sitemap' => ['/media/sitemap.xml', false],
            'media nested sitemap' => ['/media/sitemap/sitemap-1-1.xml', false],
            'pub media sitemap gz' => ['/pub/media/sitemap_products.xml.gz', false],
            'media other xml' => ['/media/feed.xml', true],
            'page' => ['/gear.html', false],
            'static-like page' => ['/statics.html', false],
            'llms' => ['/llms.txt', false],
        ];
    }

    /**
     * @dataProvider skipProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('skipProvider')]
    public function testSkippedPaths(string $path, bool $skipped): void
    {
        $this->assertSame($skipped, TrackRequest::isSkippedPath($path));
        $this->observer()->execute($this->event(self::GPTBOT, $path));
        $this->assertCount($skipped ? 0 : 1, $this->hits);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function uriProvider(): array
    {
        return [
            'root' => ['/', '/', false],
            'empty' => ['', '/', false],
            'query' => ['/a.html?utm_source=x', '/a.html', true],
            'empty query' => ['/a.html?', '/a.html', false],
            'fragment' => ['/a.html#top', '/a.html', false],
            'absolute' => ['https://shop.test/b.html?x=1', '/b.html', true],
            'absolute no path' => ['https://shop.test', '/', false],
            'double slashes' => ['//a///b.html', '/a/b.html', false],
            'encoded space' => ['/my%20page.html', '/my page.html', false],
            'encoded utf8' => ['/caf%C3%A9.html', '/café.html', false],
            'encoded slash' => ['/a%2Fb', '/a/b', false],
            'encoded invalid utf8 kept' => ['/bad%FF.html', '/bad%FF.html', false],
            'encoded control kept' => ['/x%00y', '/x%00y', false],
            'raw control removed' => ["/x\ty", '/xy', false],
            'no leading slash' => ['foo.html', '/foo.html', false],
        ];
    }

    /**
     * @dataProvider uriProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('uriProvider')]
    public function testSplitUri(string $uri, string $path, bool $hasQuery): void
    {
        $this->assertSame([$path, $hasQuery], TrackRequest::splitUri($uri));
    }

    public function testLongPathIsCut(): void
    {
        [$path] = TrackRequest::splitUri('/' . str_repeat('ü', 700) . '?q=1');
        $this->assertSame(512, mb_strlen($path, 'UTF-8'));
        [$path] = TrackRequest::splitUri('/' . str_repeat('%C3%BC', 700));
        $this->assertSame(512, mb_strlen($path, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($path, 'UTF-8'));
    }

    public function testCandidateHitIsRecorded(): void
    {
        $this->observer()->execute($this->event(self::CANDIDATE, '/about-us?ref=1', 404));
        $this->assertSame([], $this->hits);
        $this->assertCount(1, $this->candidateHits);
        [$storeId, $ua, $path, $hasQuery, $status] = $this->candidateHits[0];
        $this->assertSame(3, $storeId);
        $this->assertSame(self::CANDIDATE, $ua);
        $this->assertSame('/about-us', $path);
        $this->assertTrue($hasQuery);
        $this->assertSame(404, $status);
    }

    public function testLlmsFetchByAnyClientIsACandidate(): void
    {
        $this->observer()->execute($this->event('curl/8.4.0', '/llms.txt'));
        $this->assertCount(1, $this->candidateHits);
        $this->observer()->execute($this->event('curl/8.4.0', '/about-us'));
        $this->assertCount(1, $this->candidateHits, 'Not bot-like and not llms.txt');
    }

    public function testExcludedBotFetchingLlmsIsNotACandidate(): void
    {
        $googlebot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $this->observer()->execute($this->event($googlebot, '/llms.txt'));
        $this->observer()->execute($this->event($googlebot, '/about-us'));
        $this->assertSame([], $this->candidateHits);
        $this->assertSame([], $this->hits);
    }

    public function testDiscoveryDisabledSkipsCandidatesButNotKnownBots(): void
    {
        $this->settings['discovery'] = false;
        $this->observer()->execute($this->event(self::CANDIDATE, '/llms.txt'));
        $this->observer()->execute($this->event(self::GPTBOT, '/llms.txt'));
        $this->assertSame([], $this->candidateHits);
        $this->assertCount(1, $this->hits);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function disabledProvider(): array
    {
        return [
            'module disabled' => ['enabled', false],
            'crawler tracking off' => ['track_crawlers', false],
            'log import source' => ['source', Config::CRAWLER_SOURCE_LOG],
        ];
    }

    /**
     * @dataProvider disabledProvider
     * @param string $key
     * @param mixed $value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('disabledProvider')]
    public function testDisabledSettingsSkipEverything(string $key, $value): void
    {
        $this->settings[$key] = $value;
        $this->observer()->execute($this->event(self::GPTBOT));
        $this->observer()->execute($this->event(self::CANDIDATE));
        $this->assertSame([], $this->hits);
        $this->assertSame([], $this->candidateHits);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function areaProvider(): array
    {
        return [
            'admin' => ['adminhtml'],
            'rest' => ['webapi_rest'],
            'graphql' => ['graphql'],
            'cron' => ['crontab'],
            'unset' => [''],
        ];
    }

    /**
     * @dataProvider areaProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('areaProvider')]
    public function testOnlyTheStorefrontIsTracked(string $area): void
    {
        $this->area = $area;
        $this->observer()->execute($this->event(self::GPTBOT));
        $this->assertSame([], $this->hits);
        $this->assertSame([], $this->warnings);
    }

    public function testMissingUserAgentIsSkipped(): void
    {
        $this->observer()->execute($this->event(''));
        $this->observer()->execute($this->event('   '));
        $this->assertSame([], $this->hits);
        $this->assertSame([], $this->candidateHits);
    }

    public function testNonHttpRequestIsSkipped(): void
    {
        $this->observer()->execute(new Observer(['event' => new Event(['request' => new \stdClass()])]));
        $this->observer()->execute(new Observer(['event' => new Event([])]));
        $this->assertSame([], $this->hits);
        $this->assertSame([], $this->warnings);
    }

    public function testExceptionsAreSwallowedAndLogged(): void
    {
        $this->writerError = new \RuntimeException('database is gone');
        $this->observer()->execute($this->event(self::GPTBOT));
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('database is gone', $this->warnings[0]);

        $this->writerError = new \TypeError('type problem');
        $this->observer()->execute($this->event(self::GPTBOT));
        $this->assertCount(2, $this->warnings);
    }

    public function testLoggerFailureIsSwallowed(): void
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getHeader')->willThrowException(new \RuntimeException('broken request'));
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willThrowException(new \RuntimeException('broken logger'));
        $config = $this->createStub(Config::class);
        $observer = new TrackRequest(
            $config,
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(State::class),
            new BotClassifier($config, $this->createStub(RuleProvider::class)),
            new PageTypeResolver(),
            $this->createStub(HitWriterInterface::class),
            $logger
        );
        $observer->execute(new Observer(['event' => new Event(['request' => $request])]));
        $this->addToAssertionCount(1);
    }
}
