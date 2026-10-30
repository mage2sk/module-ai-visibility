<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Discovery;

use Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Discovery\ClassifierFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CandidateClassifierTest extends TestCase
{
    use ClassifierFixtureTrait;

    public static function userAgentProvider(): array
    {
        return [
            'own product token' => ['SimAgent/1.0 (+https://example.invalid/bot)', 'SimAgent'],
            'compatible style' => ['Mozilla/5.0 (compatible; FooCrawler/2.1; +http://example.invalid)', 'FooCrawler'],
            'bot-like wins over first token' => ['Acme/3 ExampleFetcher/1.0', 'ExampleFetcher'],
            'fallback to first non-generic token' => ['Mozilla/5.0 Acmeapp/3.0', 'Acmeapp'],
            'only browser tokens' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/1', ''],
            'empty' => ['', ''],
        ];
    }

    /**
     * @dataProvider userAgentProvider
     */
    #[DataProvider('userAgentProvider')]
    public function testSuggestToken(string $userAgent, string $expected): void
    {
        $this->assertSame($expected, $this->classifier()->suggestToken($userAgent));
    }

    public function testDisplayValue(): void
    {
        $classifier = $this->classifier();
        $this->assertSame('chat.example.invalid', $classifier->displayValue(
            ['type' => 'source', 'key_value' => 'host:chat.example.invalid']
        ));
        $this->assertSame('askbot', $classifier->displayValue(['type' => 'source', 'key_value' => 'utm:askbot']));
        $long = $classifier->displayValue(['type' => 'bot', 'key_value' => str_repeat('a', 100)]);
        $this->assertSame(80, mb_strlen($long));
        $this->assertStringEndsWith('...', $long);
    }

    public function testLongNamesProduceCodesWithinSixtyFourCharacters(): void
    {
        $this->botCandidate(5, 'SimAgent/1.0');
        $name = str_repeat('Very Long Name ', 8);
        $this->botRules = [$this->rule(1, 'bot', substr('disc_' . trim(str_repeat('very_long_name_', 8), '_'), 0, 64), 'X1', 9)];

        $this->classifier()->classify(5, 'other', ['name' => trim($name)]);

        $code = $this->savedRules[0]['code'];
        $this->assertLessThanOrEqual(64, strlen($code));
        $this->assertMatchesRegularExpression('/^disc_[a-z0-9_]+_2$/', $code);
    }
}
