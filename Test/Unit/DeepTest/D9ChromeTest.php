<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Api\RelabelerInterface;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Model\RuleWriter;
use Panth\AiVisibility\Service\BotNames;
use Panth\AiVisibility\Service\Discovery\CandidateClassifier;
use Panth\AiVisibility\Service\Discovery\CandidateRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deep test D9 (found while testing the Discovery page in Chrome).
 *
 * Fixed: on the first build a real browser that fetched /llms.txt became a bot candidate, the Discovery page
 * pre-filled a token that every ordinary browser of that platform sends ("Intel" for macOS, "like" from
 * "KHTML, like Gecko", "CPU" for iOS), and confirming it was accepted, so one click turned ordinary shoppers
 * into an "AI bot". Ordinary browsers are no longer candidates, get no suggested token, and such tokens are refused.
 */
class D9ChromeTest extends TestCase
{
    private const MAC_SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 '
        . '(KHTML, like Gecko) Version/17.0 Safari/605.1.15';
    private const MAC_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
    private const WIN_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
    private const WIN_EDGE = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0';
    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 '
        . '(KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
    private const IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 '
        . '(KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';

    /**
     * @return array
     */
    public static function browserProvider(): array
    {
        return [
            'mac safari' => [self::MAC_SAFARI],
            'mac chrome' => [self::MAC_CHROME],
            'windows chrome' => [self::WIN_CHROME],
            'iphone safari' => [self::IPHONE],
            'android chrome' => [self::ANDROID],
        ];
    }

    /**
     * The suggested token for an ordinary browser must not also match other ordinary browsers.
     *
     * @dataProvider browserProvider
     * @param string $userAgent
     */
    #[DataProvider('browserProvider')]
    public function testSuggestedTokenForBrowserDoesNotMatchOtherBrowsers(string $userAgent): void
    {
        $token = $this->classifier()->suggestToken($userAgent);
        if ($token === '') {
            $this->addToAssertionCount(1);
            return;
        }
        $others = array_filter(
            [self::MAC_SAFARI, self::MAC_CHROME, self::WIN_CHROME, self::WIN_EDGE, self::IPHONE, self::IPAD,
                self::ANDROID],
            static fn (string $ua): bool => $ua !== $userAgent
        );
        $matching = array_values(array_filter($others, static fn (string $ua): bool => stripos($ua, $token) !== false));
        $this->assertSame(
            [],
            $matching,
            sprintf('Suggested token "%s" also matches %d other ordinary browser User-Agent(s)', $token, count($matching))
        );
    }

    /**
     * @return array
     */
    public static function browserTokenProvider(): array
    {
        return [
            'Intel (every macOS browser)' => [self::MAC_SAFARI, 'Intel'],
            'like (every KHTML browser)' => [self::WIN_CHROME, 'like'],
            'CPU (every iOS browser)' => [self::IPHONE, 'CPU'],
        ];
    }

    /**
     * Confirming a browser candidate with a token found in every browser of a platform must be refused.
     *
     * @dataProvider browserTokenProvider
     * @param string $userAgent
     * @param string $token
     */
    #[DataProvider('browserTokenProvider')]
    public function testConfirmRejectsTokenSharedByOrdinaryBrowsers(string $userAgent, string $token): void
    {
        $candidates = $this->createStub(CandidateRepository::class);
        $candidates->method('get')->willReturn([
            'candidate_id' => 901,
            'type' => RuleProvider::TYPE_BOT,
            'key_value' => $userAgent,
            'status' => 'new',
        ]);
        $ruleWriter = $this->createMock(RuleWriter::class);
        $ruleWriter->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->classifier($candidates, $ruleWriter)->classify(
            901,
            'other',
            ['name' => 'Test Agent', 'token' => $token, 'company' => '']
        );
    }

    /**
     * @param CandidateRepository|null $candidates
     * @param RuleWriter|null $ruleWriter
     * @return CandidateClassifier
     */
    private function classifier(?CandidateRepository $candidates = null, ?RuleWriter $ruleWriter = null): CandidateClassifier
    {
        $ruleProvider = $this->createStub(RuleProvider::class);
        $ruleProvider->method('botRules')->willReturn([]);
        $ruleProvider->method('sourceRules')->willReturn([]);
        $botClassifier = $this->createStub(BotClassifierInterface::class);
        $botClassifier->method('classify')->willReturn(null);
        return new CandidateClassifier(
            $candidates ?? $this->createStub(CandidateRepository::class),
            $ruleProvider,
            $ruleWriter ?? $this->createStub(RuleWriter::class),
            $this->createStub(RelabelerInterface::class),
            $botClassifier,
            $this->createStub(SourceClassifierInterface::class),
            $this->createStub(BotNames::class)
        );
    }
}
