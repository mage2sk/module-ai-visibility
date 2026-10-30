<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AiVisibility\Api\AttributionCookieInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\SourceMatch;
use Panth\AiVisibility\Service\AttributionCookie;
use Panth\AiVisibility\Service\ConsentChecker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AttributionCookieTest extends TestCase
{
    private const NOW = 1790000000;

    /**
     * @var string|null
     */
    private ?string $cookieValue = null;

    /**
     * @var array<int, array{name: string, value: string, metadata: PublicCookieMetadata}>
     */
    private array $written = [];

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

    private function cookie(
        int $now = self::NOW,
        bool $consent = true,
        string $cryptKey = "oldkey0000\nnewkey1111",
        bool $secure = true,
        int $windowDays = 30,
        bool $failWrite = false
    ): AttributionCookie {
        $manager = $this->createStub(CookieManagerInterface::class);
        $manager->method('getCookie')->willReturnCallback(fn () => $this->cookieValue);
        $manager->method('setPublicCookie')->willReturnCallback(
            function (string $name, string $value, PublicCookieMetadata $metadata) use ($failWrite) {
                if ($failWrite) {
                    throw new \RuntimeException('headers already sent');
                }
                $this->written[] = ['name' => $name, 'value' => $value, 'metadata' => $metadata];
                $this->cookieValue = $value;
            }
        );
        $factory = $this->createStub(CookieMetadataFactory::class);
        $factory->method('createPublicCookieMetadata')->willReturnCallback(fn () => new PublicCookieMetadata());
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(fn ($path) => $path === 'crypt/key' ? $cryptKey : null);
        $config = $this->createStub(Config::class);
        $config->method('attributionWindowDays')->willReturn($windowDays);
        $consentChecker = $this->createStub(ConsentChecker::class);
        $consentChecker->method('canSetCookie')->willReturn($consent);
        $request = $this->createStub(RequestInterface::class);
        $request->method('isSecure')->willReturn($secure);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn($now);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message) {
            $this->warnings[] = $message;
        });
        return new AttributionCookie(
            $manager,
            $factory,
            $deployment,
            $config,
            $consentChecker,
            $request,
            $dateTime,
            $logger
        );
    }

    private function payload(array $override = []): array
    {
        return array_merge(
            ['f' => 'chatgpt', 'ft' => self::NOW - 100, 'l' => 'claude', 'lt' => self::NOW - 50, 'lp' => '/a.html'],
            $override
        );
    }

    public function testSignAndVerifyRoundTrip(): void
    {
        $cookie = $this->cookie();
        $value = $cookie->sign($this->payload());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/', $value);
        $this->assertSame($this->payload(), $cookie->verify($value));
        // Pure: same input, same output.
        $this->assertSame($value, $cookie->sign($this->payload()));
    }

    public function testKeyUsesNewestCryptKeyLine(): void
    {
        $value = $this->cookie(self::NOW, true, "oldkey0000\nnewkey1111")->sign($this->payload());
        $this->assertNotNull($this->cookie(self::NOW, true, "newkey1111")->verify($value));
        $this->assertNull($this->cookie(self::NOW, true, "oldkey0000")->verify($value));
    }

    public function testNoCryptKeyDisablesTheCookie(): void
    {
        $cookie = $this->cookie(self::NOW, true, '');
        $this->assertSame('', $cookie->sign($this->payload()));
        $this->assertNull($cookie->verify('abc.def'));
        $cookie->touch(new SourceMatch('chatgpt', 'ChatGPT'), '/p');
        $this->assertSame([], $this->written);
    }

    public static function tamperedValues(): array
    {
        return [
            'empty' => [''],
            'no dot' => ['abcdef'],
            'two dots' => ['a.b.c'],
            'empty signature' => ['abc.'],
            'empty payload' => ['.abc'],
            'bad base64' => ['a*b.' . str_repeat('A', 43)],
            'huge' => [str_repeat('A', 5000) . '.x'],
        ];
    }

    /**
     * @dataProvider tamperedValues
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tamperedValues')]
    public function testMalformedValuesAreRejected(string $value): void
    {
        $this->assertNull($this->cookie()->verify($value));
    }

    public function testTamperedPayloadOrSignatureIsRejected(): void
    {
        $cookie = $this->cookie();
        $value = $cookie->sign($this->payload());
        [$encoded, $signature] = explode('.', $value);
        $forged = rtrim(strtr(base64_encode((string)json_encode($this->payload(['f' => 'grok']))), '+/', '-_'), '=');
        $this->assertNull($cookie->verify($forged . '.' . $signature));
        $flipped = $signature;
        $flipped[5] = $flipped[5] === 'A' ? 'B' : 'A';
        $this->assertNull($cookie->verify($encoded . '.' . $flipped));
        $this->assertNull($cookie->verify($encoded . 'A.' . $signature));
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing f' => [['ft' => 1, 'l' => 'x', 'lt' => 1, 'lp' => '/']],
            'empty f' => [['f' => '', 'ft' => 1, 'l' => 'x', 'lt' => 1, 'lp' => '/']],
            'string time' => [['f' => 'x', 'ft' => '1', 'l' => 'x', 'lt' => 1, 'lp' => '/']],
            'zero time' => [['f' => 'x', 'ft' => 1, 'l' => 'x', 'lt' => 0, 'lp' => '/']],
            'array source' => [['f' => ['x'], 'ft' => 1, 'l' => 'x', 'lt' => 1, 'lp' => '/']],
            'long source' => [['f' => str_repeat('x', 65), 'ft' => 1, 'l' => 'x', 'lt' => 1, 'lp' => '/']],
            'missing lp' => [['f' => 'x', 'ft' => 1, 'l' => 'x', 'lt' => 1]],
            'list' => [['x', 1, 'x', 1, '/']],
        ];
    }

    /**
     * @dataProvider invalidPayloads
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function testSignedButInvalidPayloadIsRejected(array $payload): void
    {
        $cookie = $this->cookie();
        $this->assertNull($cookie->verify($cookie->sign($payload)));
    }

    public function testReadReturnsAttribution(): void
    {
        $cookie = $this->cookie();
        $this->cookieValue = $cookie->sign($this->payload());
        $attribution = $cookie->read();
        $this->assertNotNull($attribution);
        $this->assertSame('chatgpt', $attribution->firstSource);
        $this->assertSame(self::NOW - 100, $attribution->firstAt);
        $this->assertSame('claude', $attribution->lastSource);
        $this->assertSame(self::NOW - 50, $attribution->lastAt);
        $this->assertSame('/a.html', $attribution->landingPath);
    }

    public function testReadWithoutCookieOrTamperedCookieIsNull(): void
    {
        $this->assertNull($this->cookie()->read());
        $this->cookieValue = 'garbage.value';
        $this->assertNull($this->cookie()->read());
    }

    public function testExpiryUsesLastTouchAndWindow(): void
    {
        $signer = $this->cookie();
        $this->cookieValue = $signer->sign($this->payload(['ft' => self::NOW - 90 * 86400, 'lt' => self::NOW]));
        // Old first touch, recent last touch: still valid.
        $this->assertNotNull($this->cookie(self::NOW + 29 * 86400)->read());
        $this->assertNotNull($this->cookie(self::NOW + 30 * 86400)->read());
        $this->assertNull($this->cookie(self::NOW + 30 * 86400 + 1)->read());
        // A shorter window expires it sooner.
        $this->assertNull($this->cookie(self::NOW + 8 * 86400, true, "oldkey0000\nnewkey1111", true, 7)->read());
    }

    public function testFirstTouchCreatesCookieWithFlags(): void
    {
        $this->cookie()->touch(new SourceMatch('perplexity', 'Perplexity'), '/landing.html?utm_source=x#top');
        $this->assertCount(1, $this->written);
        $write = $this->written[0];
        $this->assertSame(AttributionCookieInterface::COOKIE_NAME, $write['name']);
        $payload = $this->cookie()->verify($write['value']);
        $this->assertSame(
            ['f' => 'perplexity', 'ft' => self::NOW, 'l' => 'perplexity', 'lt' => self::NOW, 'lp' => '/landing.html'],
            $payload
        );
        $metadata = $write['metadata'];
        $this->assertTrue($metadata->getHttpOnly());
        $this->assertTrue($metadata->getSecure());
        $this->assertSame('/', $metadata->getPath());
        $this->assertSame(30 * 86400, $metadata->getDuration());
        $this->assertSame('Lax', $metadata->getSameSite());
    }

    public function testNotSecureOnHttp(): void
    {
        $this->cookie(self::NOW, true, "k", false)->touch(new SourceMatch('claude', 'Claude'), '/');
        $this->assertFalse($this->written[0]['metadata']->getSecure());
    }

    public function testLaterTouchKeepsFirstAndUpdatesLast(): void
    {
        $this->cookie()->touch(new SourceMatch('chatgpt', 'ChatGPT'), '/first');
        $this->cookie(self::NOW + 3600)->touch(new SourceMatch('gemini', 'Gemini'), '/second');
        $payload = $this->cookie()->verify((string)$this->cookieValue);
        $this->assertSame('chatgpt', $payload['f']);
        $this->assertSame(self::NOW, $payload['ft']);
        $this->assertSame('gemini', $payload['l']);
        $this->assertSame(self::NOW + 3600, $payload['lt']);
        $this->assertSame('/second', $payload['lp']);
    }

    public function testTouchAfterExpiryStartsAFreshFirstTouch(): void
    {
        $this->cookie()->touch(new SourceMatch('chatgpt', 'ChatGPT'), '/first');
        $later = self::NOW + 31 * 86400;
        $this->cookie($later)->touch(new SourceMatch('claude', 'Claude'), '/again');
        $payload = $this->cookie($later)->verify((string)$this->cookieValue);
        $this->assertSame('claude', $payload['f']);
        $this->assertSame($later, $payload['ft']);
    }

    public function testTouchOverTamperedCookieStartsFresh(): void
    {
        $this->cookieValue = 'tampered.value';
        $this->cookie()->touch(new SourceMatch('grok', 'Grok'), '/x');
        $this->assertSame('grok', $this->cookie()->verify((string)$this->cookieValue)['f']);
    }

    public function testConsentModeSuppressesTheCookie(): void
    {
        $this->cookie(self::NOW, false)->touch(new SourceMatch('chatgpt', 'ChatGPT'), '/p');
        $this->assertSame([], $this->written);
    }

    public function testInvalidLandingPathFallsBackToRoot(): void
    {
        $this->cookie()->touch(new SourceMatch('chatgpt', 'ChatGPT'), 'https://shop.test/p');
        $this->assertSame('/', $this->cookie()->verify((string)$this->cookieValue)['lp']);
        $this->cookie()->touch(new SourceMatch('chatgpt', 'ChatGPT'), '/' . str_repeat('a', 600));
        $this->assertSame(512, strlen($this->cookie()->verify((string)$this->cookieValue)['lp']));
    }

    public function testWriteFailureIsLoggedNotThrown(): void
    {
        $this->cookie(self::NOW, true, 'k', true, 30, true)->touch(new SourceMatch('chatgpt', 'ChatGPT'), '/p');
        $this->assertCount(1, $this->warnings);
    }
}
