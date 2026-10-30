<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Collect;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\AttributionCookieInterface;
use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Model\BotMatch;
use Panth\AiVisibility\Model\Catalogue\SourceCatalogue;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Controller\Collect\Index;
use Panth\AiVisibility\Service\RateLimiter;
use Panth\AiVisibility\Service\SourceClassifier;
use Panth\AiVisibility\Service\VisitWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0';

    /**
     * Writer / cookie calls: [method, args]
     *
     * @var array<int, array>
     */
    private array $calls = [];

    /**
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * @var int|null
     */
    private ?int $status = null;

    /**
     * @var string|null
     */
    private ?string $contents = null;

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

    /**
     * @var array<int, array{0: string, 1: int}>
     */
    private array $rateCalls = [];

    private function controller(string $body, array $options = []): Index
    {
        $o = array_merge([
            'enabled' => true,
            'trackVisits' => true,
            'discovery' => true,
            'sampleRate' => 25,
            'ua' => self::BROWSER,
            'origin' => 'https://shop.test',
            'referer' => null,
            'allow' => true,
            'knownBot' => false,
            'candidateBot' => false,
            'candidateId' => 9,
            'writerThrows' => false,
        ], $options);

        $request = $this->createStub(HttpRequest::class);
        $request->method('getContent')->willReturn($body);
        $request->method('getServer')->willReturnCallback(fn (string $key) => match ($key) {
            'HTTP_USER_AGENT' => $o['ua'],
            'HTTP_ORIGIN' => $o['origin'],
            'HTTP_REFERER' => $o['referer'],
            default => null,
        });

        $result = $this->createStub(Raw::class);
        $result->method('setHttpResponseCode')->willReturnCallback(function (int $code) use (&$result) {
            $this->status = $code;
            return $result;
        });
        $result->method('setHeader')->willReturnCallback(function (string $name, $value) use (&$result) {
            $this->headers[$name] = (string)$value;
            return $result;
        });
        $result->method('setContents')->willReturnCallback(function (string $value) use (&$result) {
            $this->contents = $value;
            return $result;
        });
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($result);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $store->method('getBaseUrl')->willReturnCallback(
            fn ($type = null, $secure = null) => $secure ? 'https://shop.test/' : 'http://shop.test/'
        );
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getStores')->willReturn([$store]);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn ($storeId = null) => $storeId === 3 && $o['enabled']);
        $config->method('trackVisits')->willReturn($o['trackVisits']);
        $config->method('discoveryEnabled')->willReturn($o['discovery']);
        $config->method('landingSampleRate')->willReturn($o['sampleRate']);
        $config->method('rateLimitPerMinute')->willReturn(20);
        $config->method('customSourceHosts')->willReturn([]);
        $config->method('customUtm')->willReturn([]);

        $bots = $this->createStub(BotClassifierInterface::class);
        $bots->method('classify')->willReturn(
            $o['knownBot'] ? new BotMatch('gptbot', 'GPTBot', 'OpenAI', 'training') : null
        );
        $bots->method('isDiscoveryCandidate')->willReturn($o['candidateBot']);

        $ruleProvider = $this->createStub(RuleProvider::class);
        $ruleProvider->method('sourceRules')->willReturn([]);
        $sources = new SourceClassifier(new SourceCatalogue(), $config, $ruleProvider);

        $limiter = $this->createStub(RateLimiter::class);
        $limiter->method('allow')->willReturnCallback(function (string $ip, int $limit) use ($o) {
            $this->rateCalls[] = [$ip, $limit];
            return $o['allow'];
        });

        $writer = $this->createStub(VisitWriter::class);
        $writer->method('insertVisit')->willReturnCallback(function (...$args) use ($o) {
            if ($o['writerThrows']) {
                throw new \RuntimeException('db down');
            }
            $this->calls[] = ['insertVisit', $args];
        });
        $writer->method('insertLanding')->willReturnCallback(function (...$args) {
            $this->calls[] = ['insertLanding', $args];
        });
        $writer->method('upsertSourceCandidate')->willReturnCallback(function (string $key) use ($o) {
            $this->calls[] = ['upsertSourceCandidate', [$key]];
            return $o['candidateId'];
        });

        $cookie = $this->createStub(AttributionCookieInterface::class);
        $cookie->method('touch')->willReturnCallback(function ($source, string $path) {
            $this->calls[] = ['touch', [$source->code, $path]];
        });

        $remote = $this->createStub(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturn('198.51.100.7');

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message) {
            $this->warnings[] = $message;
        });

        return new Index(
            $request,
            $rawFactory,
            $storeManager,
            $config,
            $bots,
            $sources,
            $limiter,
            $writer,
            $cookie,
            $remote,
            $logger
        );
    }

    private function send(array $payload, array $options = []): void
    {
        $this->runRaw((string)json_encode($payload), $options);
    }

    private function runRaw(string $body, array $options = []): void
    {
        $this->controller($body, $options)->execute();
        $this->assertSame(204, $this->status, 'always 204');
        $this->assertSame('', $this->contents, 'no body');
        $this->assertStringContainsString('no-store', $this->headers['Cache-Control'] ?? '');
    }

    public function testActionIsPostOnlyAndCsrfExempt(): void
    {
        $controller = $this->controller('');
        $this->assertInstanceOf(HttpPostActionInterface::class, $controller);
        $this->assertNotInstanceOf(HttpGetActionInterface::class, $controller);
        $this->assertInstanceOf(CsrfAwareActionInterface::class, $controller);
        $request = $this->createStub(HttpRequest::class);
        $this->assertTrue($controller->validateForCsrf($request));
        $this->assertNull($controller->createCsrfValidationException($request));
    }

    public function testKnownHostRecordsVisitAndTouchesCookie(): void
    {
        $this->send(['t' => 'ai', 'h' => 'chatgpt.com', 'u' => '', 'p' => '/p.html']);
        $this->assertSame([
            ['insertVisit', [3, 'chatgpt', 'referrer', 'chatgpt.com', '/p.html']],
            ['touch', ['chatgpt', '/p.html']],
        ], $this->calls);
        $this->assertSame([['198.51.100.7', 20]], $this->rateCalls);
    }

    public function testSubdomainHostIsReclassifiedServerSide(): void
    {
        $this->send(['t' => 'ai', 'h' => 'www.perplexity.ai', 'u' => 'chatgpt', 'p' => '/']);
        $this->assertSame(['insertVisit', [3, 'perplexity', 'referrer', 'www.perplexity.ai', '/']], $this->calls[0]);
    }

    public function testUtmMatchWithoutReferrer(): void
    {
        $this->send(['t' => 'ai', 'h' => '', 'u' => 'claude', 'p' => '/c.html']);
        $this->assertSame([
            ['insertVisit', [3, 'claude', 'utm', null, '/c.html']],
            ['touch', ['claude', '/c.html']],
        ], $this->calls);
    }

    public function testUtmMatchWithUnknownReferrerKeepsHost(): void
    {
        $this->send(['t' => 'ai', 'h' => 'news.example.invalid', 'u' => 'openai', 'p' => '/']);
        $this->assertSame(['insertVisit', [3, 'chatgpt', 'utm', 'news.example.invalid', '/']], $this->calls[0]);
    }

    public function testCandidateHostKeyDropsLeadingWww(): void
    {
        $this->send(['t' => 'cand', 'h' => 'www.assistant.example.invalid', 'u' => '', 'p' => '/x']);
        $this->assertSame(['upsertSourceCandidate', ['host:assistant.example.invalid']], $this->calls[0]);
    }

    public function testClientAiClaimForUnknownHostBecomesCandidate(): void
    {
        $this->send(['t' => 'ai', 'h' => 'assistant.example.invalid', 'u' => '', 'p' => '/x']);
        $this->assertSame([
            ['upsertSourceCandidate', ['host:assistant.example.invalid']],
            ['insertVisit', [3, 'cand:9', 'referrer', 'assistant.example.invalid', '/x']],
        ], $this->calls);
    }

    public function testClientAiClaimForNonCandidateIsDropped(): void
    {
        $this->send(['t' => 'ai', 'h' => 'notchatgpt.com', 'u' => '', 'p' => '/x']);
        $this->send(['t' => 'ai', 'h' => 'chatgpt.com.evil.example.invalid', 'u' => 'newsletter', 'p' => '/x']);
        $this->assertSame([], $this->calls);
    }

    public function testCandidateHostStripsWww(): void
    {
        $this->send(['t' => 'cand', 'h' => 'www.chat.example.invalid', 'u' => '', 'p' => '/x']);
        $this->assertSame(['upsertSourceCandidate', ['host:chat.example.invalid']], $this->calls[0]);
        $this->assertSame(['insertVisit', [3, 'cand:9', 'referrer', 'www.chat.example.invalid', '/x']], $this->calls[1]);
    }

    public function testCandidateUtm(): void
    {
        $this->send(['t' => 'cand', 'h' => '', 'u' => 'shop-agent', 'p' => '/x']);
        $this->assertSame([
            ['upsertSourceCandidate', ['utm:shop-agent']],
            ['insertVisit', [3, 'cand:9', 'utm', null, '/x']],
        ], $this->calls);
    }

    public function testCandidateClaimForKnownHostIsUpgradedToAi(): void
    {
        $this->send(['t' => 'cand', 'h' => 'claude.ai', 'u' => '', 'p' => '/x']);
        $this->assertSame('claude', $this->calls[0][1][1]);
        $this->assertSame('touch', $this->calls[1][0]);
    }

    public function testCandidateMarkedNotAiIsNotRecorded(): void
    {
        $this->send(['t' => 'cand', 'h' => 'assistant.example.invalid', 'u' => '', 'p' => '/'], ['candidateId' => null]);
        $this->assertSame([['upsertSourceCandidate', ['host:assistant.example.invalid']]], $this->calls);
    }

    public function testCandidateDroppedWhenDiscoveryDisabled(): void
    {
        $this->send(['t' => 'cand', 'h' => 'assistant.example.invalid', 'u' => '', 'p' => '/'], ['discovery' => false]);
        $this->assertSame([], $this->calls);
    }

    public function testNonAiCandidateIsDropped(): void
    {
        $this->send(['t' => 'cand', 'h' => 'ai.google.com', 'u' => '', 'p' => '/']);
        $this->send(['t' => 'cand', 'h' => 'shop.example.invalid', 'u' => 'newsletter', 'p' => '/']);
        $this->assertSame([], $this->calls);
    }

    public function testInternalReferrerIsDropped(): void
    {
        $this->send(['t' => 'cand', 'h' => 'shop.test', 'u' => 'gpt', 'p' => '/']);
        $this->assertSame([], $this->calls);
    }

    public function testLanding(): void
    {
        $this->send(['t' => 'land', 'p' => '/deep/page.html']);
        $this->assertSame([['insertLanding', [3, '/deep/page.html']]], $this->calls);
        $this->assertSame([], array_filter($this->calls, fn ($c) => $c[0] === 'touch'));
    }

    public function testLandingIgnoresHostAndUtmFields(): void
    {
        $this->send(['t' => 'land', 'h' => 'chatgpt.com', 'u' => 'chatgpt', 'p' => '/deep']);
        $this->assertSame([['insertLanding', [3, '/deep']]], $this->calls);
    }

    public function testLandingOnHomeOrSamplingOffIsDropped(): void
    {
        $this->send(['t' => 'land', 'p' => '/']);
        $this->send(['t' => 'land', 'p' => '/deep'], ['sampleRate' => 0]);
        $this->assertSame([], $this->calls);
    }

    public static function invalidBodies(): array
    {
        $ok = ['t' => 'ai', 'h' => 'chatgpt.com', 'u' => '', 'p' => '/p'];
        return [
            'empty' => [''],
            'bad json' => ['{"t":"ai",'],
            'json string' => ['"ai"'],
            'json number' => ['42'],
            'too deep' => [(string)json_encode(['t' => 'ai', 'p' => '/', 'x' => [[[[1]]]]])],
            'oversize' => [(string)json_encode($ok + ['pad' => str_repeat('a', 2100)])],
            'unknown type' => [(string)json_encode(['t' => 'bot'] + $ok)],
            'type not string' => [(string)json_encode(['t' => 1] + $ok)],
            'missing type' => [(string)json_encode(['h' => 'chatgpt.com', 'p' => '/'])],
            'missing path' => [(string)json_encode(['t' => 'ai', 'h' => 'chatgpt.com'])],
            'path not string' => [(string)json_encode(['p' => ['/']] + $ok)],
            'relative path' => [(string)json_encode(['p' => 'p.html'] + $ok)],
            'absolute url path' => [(string)json_encode(['p' => 'https://shop.test/p'] + $ok)],
            'query in path' => [(string)json_encode(['p' => '/p?email=x'] + $ok)],
            'fragment in path' => [(string)json_encode(['p' => '/p#x'] + $ok)],
            'space in path' => [(string)json_encode(['p' => '/a b'] + $ok)],
            'control char in path' => [(string)json_encode(['p' => "/a\nb"] + $ok)],
            'non ascii path' => [(string)json_encode(['p' => '/caf' . "\u{e9}"] + $ok)],
            'long path' => [(string)json_encode(['p' => '/' . str_repeat('a', 512)] + $ok)],
            'uppercase host' => [(string)json_encode(['h' => 'ChatGPT.com'] + $ok)],
            'host with path' => [(string)json_encode(['h' => 'chatgpt.com/c/1'] + $ok)],
            'host with port' => [(string)json_encode(['h' => 'chatgpt.com:443'] + $ok)],
            'host url' => [(string)json_encode(['h' => 'https://chatgpt.com'] + $ok)],
            'host double dot' => [(string)json_encode(['h' => 'chatgpt..com'] + $ok)],
            'long host' => [(string)json_encode(['h' => str_repeat('a', 252) . '.com'] + $ok)],
            'host not string' => [(string)json_encode(['h' => ['chatgpt.com']] + $ok)],
            'utm uppercase' => [(string)json_encode(['h' => '', 'u' => 'ChatGPT'] + $ok)],
            'utm spaces' => [(string)json_encode(['h' => '', 'u' => 'chat gpt'] + $ok)],
            'utm long' => [(string)json_encode(['h' => '', 'u' => str_repeat('a', 65)] + $ok)],
            'utm not string' => [(string)json_encode(['h' => '', 'u' => 5] + $ok)],
            'nothing to classify' => [(string)json_encode(['h' => '', 'u' => ''] + $ok)],
        ];
    }

    /**
     * @dataProvider invalidBodies
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodiesAreDropped(string $body): void
    {
        $this->runRaw($body);
        $this->assertSame([], $this->calls);
    }

    public function testBodyLimitIsExactly2048Bytes(): void
    {
        $base = (string)json_encode(['t' => 'ai', 'h' => 'chatgpt.com', 'u' => '', 'p' => '/p', 'x' => '']);
        $body = substr($base, 0, -2) . str_repeat('a', 2048 - strlen($base)) . '"}';
        $this->assertSame(2048, strlen($body));
        $this->runRaw($body);
        $this->assertCount(2, $this->calls);
        $this->calls = [];
        $this->runRaw(substr($body, 0, -2) . 'a"}');
        $this->assertSame([], $this->calls);
    }

    public function testDisabledModuleDropsEverything(): void
    {
        $this->send(['t' => 'ai', 'h' => 'chatgpt.com', 'u' => '', 'p' => '/'], ['enabled' => false]);
        $this->send(['t' => 'ai', 'h' => 'chatgpt.com', 'u' => '', 'p' => '/'], ['trackVisits' => false]);
        $this->assertSame([], $this->calls);
        $this->assertSame([], $this->rateCalls);
    }

    public static function botAgents(): array
    {
        return [
            'known ai bot' => [['knownBot' => true]],
            'discovery candidate' => [['candidateBot' => true]],
            'empty ua' => [['ua' => '']],
            'googlebot' => [['ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']],
            'headless' => [['ua' => 'Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/130.0 Safari/537.36']],
            'lighthouse' => [['ua' => 'Mozilla/5.0 Chrome/130.0 Safari/537.36 Chrome-Lighthouse']],
        ];
    }

    /**
     * @dataProvider botAgents
     */
    #[DataProvider('botAgents')]
    public function testBotsAreDropped(array $options): void
    {
        $this->send(['t' => 'ai', 'h' => 'chatgpt.com', 'u' => '', 'p' => '/'], $options);
        $this->assertSame([], $this->calls);
        $this->assertSame([], $this->rateCalls);
    }

    public function testPhoneModelNamedLikeBotIsNotDropped(): void
    {
        $this->send(
            ['t' => 'ai', 'h' => 'chatgpt.com', 'u' => '', 'p' => '/'],
            ['ua' => 'Mozilla/5.0 (Linux; Android 10; CUBOT X30) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36']
        );
        $this->assertCount(2, $this->calls);
    }

    public function testRateLimitedIsDropped(): void
    {
        $this->send(['t' => 'ai', 'h' => 'chatgpt.com', 'u' => '', 'p' => '/'], ['allow' => false]);
        $this->assertSame([], $this->calls);
        $this->assertCount(1, $this->rateCalls);
    }

    public function testCrossOriginIsDropped(): void
    {
        $payload = ['t' => 'ai', 'h' => 'chatgpt.com', 'u' => '', 'p' => '/'];
        $this->send($payload, ['origin' => 'https://evil.example.invalid']);
        $this->send($payload, ['origin' => null, 'referer' => null]);
        $this->send($payload, ['origin' => 'https://shop.test.example.invalid']);
        $this->assertSame([], $this->calls);
        $this->send($payload, ['origin' => null, 'referer' => 'https://shop.test/some/page']);
        $this->assertCount(2, $this->calls);
    }

    public function testWriterFailureStill204AndLogs(): void
    {
        $this->send(['t' => 'ai', 'h' => 'chatgpt.com', 'u' => '', 'p' => '/'], ['writerThrows' => true]);
        $this->assertCount(1, $this->warnings);
    }
}
