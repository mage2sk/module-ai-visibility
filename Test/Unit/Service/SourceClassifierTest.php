<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Panth\AiVisibility\Model\Catalogue\SourceCatalogue;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Service\SourceClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourceClassifierTest extends TestCase
{
    private function classifier(array $hosts = [], array $utm = [], array $rules = []): SourceClassifier
    {
        $config = $this->createStub(Config::class);
        $config->method('customSourceHosts')->willReturn($hosts);
        $config->method('customUtm')->willReturn($utm);
        $ruleProvider = $this->createStub(RuleProvider::class);
        $ruleProvider->method('sourceRules')->willReturn($rules);
        return new SourceClassifier(new SourceCatalogue(), $config, $ruleProvider);
    }

    public static function knownHosts(): array
    {
        return [
            ['chatgpt.com', 'chatgpt'],
            ['www.chatgpt.com', 'chatgpt'],
            ['CHATGPT.COM', 'chatgpt'],
            ['chatgpt.com.', 'chatgpt'],
            ['chat.openai.com', 'chatgpt'],
            ['perplexity.ai', 'perplexity'],
            ['www.perplexity.ai', 'perplexity'],
            ['gemini.google.com', 'gemini'],
            ['bard.google.com', 'gemini'],
            ['copilot.microsoft.com', 'copilot'],
            ['copilot.cloud.microsoft', 'copilot'],
            ['claude.ai', 'claude'],
            ['meta.ai', 'meta_ai'],
            ['chat.mistral.ai', 'mistral'],
            ['chat.deepseek.com', 'deepseek'],
            ['grok.com', 'grok'],
            ['you.com', 'you'],
        ];
    }

    /**
     * @dataProvider knownHosts
     */
    #[DataProvider('knownHosts')]
    public function testKnownHostsAndSubdomainsMatch(string $host, string $code): void
    {
        $match = $this->classifier()->classifyHost($host);
        $this->assertNotNull($match);
        $this->assertSame($code, $match->code);
    }

    public static function lookAlikeHosts(): array
    {
        return [
            ['notchatgpt.com'],
            ['chatgpt.com.evil.example.invalid'],
            ['chatgpt.co'],
            ['chatgpt-com.example.invalid'],
            ['xclaude.ai'],
            ['claude.ai.example.invalid'],
            ['google.com'],
            ['mistral.ai'],
            ['openai.com'],
            ['com'],
            [''],
            ['chat gpt.com'],
            ['chatgpt..com'],
            ['-chatgpt.com'],
            [str_repeat('a', 250) . '.chatgpt.com'],
        ];
    }

    /**
     * @dataProvider lookAlikeHosts
     */
    #[DataProvider('lookAlikeHosts')]
    public function testLookAlikeHostsDoNotMatch(string $host): void
    {
        $this->assertNull($this->classifier()->classifyHost($host));
    }

    public function testUtmMatching(): void
    {
        $classifier = $this->classifier();
        $this->assertSame('chatgpt', $classifier->classifyUtm('chatgpt.com')->code);
        $this->assertSame('chatgpt', $classifier->classifyUtm('ChatGPT')->code);
        $this->assertSame('chatgpt', $classifier->classifyUtm(' openai ')->code);
        $this->assertSame('claude', $classifier->classifyUtm('claude.ai')->code);
        $this->assertSame('meta_ai', $classifier->classifyUtm('metaai')->code);
        $this->assertSame('you', $classifier->classifyUtm('you.com')->code);
        $this->assertNull($classifier->classifyUtm('chatgpt-ads'));
        $this->assertNull($classifier->classifyUtm('newsletter'));
        $this->assertNull($classifier->classifyUtm(''));
        $this->assertNull($classifier->classifyUtm('chat gpt'));
        $this->assertNull($classifier->classifyUtm(str_repeat('a', 65)));
    }

    public static function candidateHosts(): array
    {
        return [
            ['assistant.example.invalid', true],
            ['chat.example.invalid', true],
            ['my-agent.example.invalid', true],
            ['ai.shop.test', true],
            ['shop-gpt.example.invalid', true],
            ['llm.example.invalid', true],
            // Not AI-looking.
            ['shop.test', false],
            ['blog.example.invalid', false],
            ['maine.example.invalid', false],
            ['chatter.example.invalid', false],
            ['agentur.example.invalid', false],
            // Already known.
            ['chatgpt.com', false],
            ['www.perplexity.ai', false],
            // Non-AI list.
            ['www.google.com', false],
            ['ai.google.com', false],
            ['google.co.uk', false],
            ['chat.bing.com', false],
            ['ai.yahoo.co.jp', false],
            ['chat.facebook.com', false],
            ['ai.reddit.com', false],
            ['android-app', false],
            // Invalid.
            ['', false],
            ['chat..example.invalid', false],
        ];
    }

    /**
     * @dataProvider candidateHosts
     */
    #[DataProvider('candidateHosts')]
    public function testCandidateHostHeuristic(string $host, bool $expected): void
    {
        $this->assertSame($expected, $this->classifier()->isCandidateHost($host));
    }

    public function testCandidateUtm(): void
    {
        $classifier = $this->classifier();
        $this->assertTrue($classifier->isCandidateUtm('ai-assistant'));
        $this->assertTrue($classifier->isCandidateUtm('gpt'));
        $this->assertTrue($classifier->isCandidateUtm('shop.agent'));
        $this->assertFalse($classifier->isCandidateUtm('chatgpt'));
        $this->assertFalse($classifier->isCandidateUtm('newsletter'));
        $this->assertFalse($classifier->isCandidateUtm('email'));
        $this->assertFalse($classifier->isCandidateUtm('bad value'));
    }

    public function testCustomConfigHostsAndUtm(): void
    {
        $classifier = $this->classifier(
            ['assistant.shop.test' => 'Shop Assistant', 'chat.example.invalid' => 'ChatGPT', 'chatgpt.com' => 'Other'],
            ['shopbot' => 'Shop Assistant', 'chatgpt' => 'Other']
        );
        $match = $classifier->classifyHost('www.assistant.shop.test');
        $this->assertSame('shop_assistant', $match->code);
        $this->assertSame('Shop Assistant', $match->name);
        $this->assertSame('shop_assistant', $classifier->classifyUtm('shopbot')->code);
        // A custom host named like a built-in source joins that source.
        $this->assertSame('chatgpt', $classifier->classifyHost('chat.example.invalid')->code);
        // Built-in entries keep precedence.
        $this->assertSame('chatgpt', $classifier->classifyHost('chatgpt.com')->code);
        $this->assertSame('chatgpt', $classifier->classifyUtm('chatgpt')->code);
        $this->assertFalse($classifier->isCandidateHost('assistant.shop.test'));
        $this->assertSame('Shop Assistant', $classifier->nameFor('shop_assistant'));
    }

    public function testCustomNameClashingWithBuiltInCodeGetsPrefix(): void
    {
        $classifier = $this->classifier(['a.shop.test' => 'Grok!', 'b.shop.test' => 'Claude', 'c.shop.test' => '***']);
        // "Grok!" slugs to "grok" which is a different (built-in) name.
        $this->assertSame('custom_grok', $classifier->classifyHost('a.shop.test')->code);
        $this->assertSame('claude', $classifier->classifyHost('b.shop.test')->code);
        $this->assertStringStartsWith('custom_', $classifier->classifyHost('c.shop.test')->code);
    }

    public function testConfirmedRules(): void
    {
        $rules = [
            [
                'rule_id' => 1, 'type' => 'source', 'code' => 'disc_shop_chat', 'name' => 'Shop Chat',
                'match_on' => 'host', 'pattern' => 'Chat.Shop.Test', 'category' => null, 'company' => null,
                'candidate_id' => 4, 'created_at' => '2026-01-01 00:00:00',
            ],
            [
                'rule_id' => 2, 'type' => 'source', 'code' => 'disc_shop_chat', 'name' => 'Shop Chat',
                'match_on' => 'utm', 'pattern' => 'shop-chat', 'category' => null, 'company' => null,
                'candidate_id' => 5, 'created_at' => '2026-01-01 00:00:00',
            ],
            [
                'rule_id' => 3, 'type' => 'source', 'code' => 'disc_hijack', 'name' => 'Hijack',
                'match_on' => 'host', 'pattern' => 'chatgpt.com', 'category' => null, 'company' => null,
                'candidate_id' => null, 'created_at' => '2026-01-01 00:00:00',
            ],
        ];
        $classifier = $this->classifier([], [], $rules);
        $this->assertSame('disc_shop_chat', $classifier->classifyHost('chat.shop.test')->code);
        $this->assertSame('Shop Chat', $classifier->classifyHost('eu.chat.shop.test')->name);
        $this->assertSame('disc_shop_chat', $classifier->classifyUtm('shop-chat')->code);
        $this->assertSame('chatgpt', $classifier->classifyHost('chatgpt.com')->code);
        $this->assertFalse($classifier->isCandidateHost('chat.shop.test'));
        $this->assertFalse($classifier->isCandidateUtm('shop-chat'));
        $this->assertArrayHasKey('disc_shop_chat', $classifier->allSources());
    }

    public function testNamesAndAllSources(): void
    {
        $classifier = $this->classifier(['assistant.shop.test' => 'Shop Assistant']);
        $all = $classifier->allSources();
        $this->assertSame('ChatGPT', $all['chatgpt']);
        $this->assertSame('Le Chat (Mistral)', $all['mistral']);
        $this->assertSame('Shop Assistant', $all['shop_assistant']);
        $this->assertCount(11, $all);
        $this->assertSame('Microsoft Copilot', $classifier->nameFor('copilot'));
        $this->assertSame('cand:12', $classifier->nameFor('cand:12'));
        $this->assertSame('unknown_code', $classifier->nameFor('unknown_code'));
    }

    public function testClientConfigShape(): void
    {
        $config = $this->classifier(['assistant.shop.test' => 'Shop Assistant'])->clientConfig();
        $this->assertSame(['hosts', 'utm', 'candidateRegex', 'nonAiHosts'], array_keys($config));
        $this->assertSame('chatgpt', $config['hosts']['chatgpt.com']);
        $this->assertSame('shop_assistant', $config['hosts']['assistant.shop.test']);
        $this->assertSame('perplexity', $config['utm']['perplexity']);
        $this->assertSame(SourceCatalogue::CANDIDATE_PATTERN, $config['candidateRegex']);
        $this->assertContains('google.*', $config['nonAiHosts']);
    }

    public function testIndexIsBuiltOnce(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects($this->once())->method('customSourceHosts')->willReturn([]);
        $config->expects($this->once())->method('customUtm')->willReturn([]);
        $ruleProvider = $this->createMock(RuleProvider::class);
        $ruleProvider->expects($this->once())->method('sourceRules')->willReturn([]);
        $classifier = new SourceClassifier(new SourceCatalogue(), $config, $ruleProvider);
        $classifier->classifyHost('chatgpt.com');
        $classifier->classifyUtm('claude');
        $classifier->allSources();
        $classifier->clientConfig();
    }
}
