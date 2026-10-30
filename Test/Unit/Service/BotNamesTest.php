<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Service\BotClassifier;
use Panth\AiVisibility\Service\BotNames;
use PHPUnit\Framework\TestCase;

class BotNamesTest extends TestCase
{
    private function classifier(array $rules = [], array $custom = []): BotClassifier
    {
        $provider = $this->createStub(RuleProvider::class);
        $provider->method('botRules')->willReturn($rules);
        $config = $this->createStub(Config::class);
        $config->method('customBotPatterns')->willReturn($custom);
        return new BotClassifier($config, $provider);
    }

    public function testBuiltInAndRuleNames(): void
    {
        $names = new BotNames($this->classifier([
            ['code' => 'disc_sim', 'name' => 'SimAgent', 'company' => 'Example Labs', 'pattern' => 'SimAgent'],
            ['code' => 'disc_same', 'name' => 'Same', 'company' => 'Same', 'pattern' => 'SameBot'],
            ['code' => 'gptbot', 'name' => 'Hijack', 'company' => null, 'pattern' => 'HijackBot'],
        ]));

        $this->assertSame('GPTBot (OpenAI)', $names->nameFor('gptbot'));
        $this->assertSame('SimAgent (Example Labs)', $names->nameFor('disc_sim'));
        $this->assertSame('Same', $names->nameFor('disc_same'));
        $this->assertSame('Unknown bot #12', $names->nameFor('cand:12'));
        $this->assertSame('some_custom', $names->nameFor('some_custom'));
        $this->assertSame('cand:x', $names->nameFor('cand:x'));
        $this->assertTrue($names->isBuiltIn('claudebot'));
        $this->assertFalse($names->isBuiltIn('disc_sim'));
        $all = $names->all();
        $this->assertArrayHasKey('disc_sim', $all);
        $this->assertSame('Amazonbot (Amazon)', reset($all));
    }

    public function testCustomBotsUseTheClassifierCodes(): void
    {
        $classifier = $this->classifier([], [
            ['name' => 'Shop Helper', 'token' => 'ShopHelper', 'category' => 'search', 'company' => 'Helper Inc'],
            ['name' => 'GPTBot', 'token' => 'OtherGpt', 'category' => 'live', 'company' => ''],
        ]);
        $names = new BotNames($classifier);

        $match = $classifier->classify('Mozilla/5.0 (compatible; ShopHelper/2.0)');
        $this->assertNotNull($match);
        $this->assertSame('custom_shop_helper', $match->code);
        $this->assertSame('Shop Helper (Helper Inc)', $names->nameFor($match->code));
        $this->assertSame('GPTBot', $names->nameFor('custom_gptbot'));
        $this->assertSame('GPTBot (OpenAI)', $names->nameFor('gptbot'));
        $this->assertSame('shop_helper', $names->nameFor('shop_helper'));
    }

    public function testEveryBuiltInBotHasAName(): void
    {
        $names = new BotNames($this->classifier());
        foreach (\Panth\AiVisibility\Model\Catalogue\BotCatalogue::BOTS as $code => [$name]) {
            $this->assertStringStartsWith($name, $names->nameFor($code));
        }
    }

    public function testCandidateId(): void
    {
        $names = new BotNames($this->classifier());
        $this->assertSame(7, $names->candidateId('cand:7'));
        $this->assertNull($names->candidateId('cand:0'));
        $this->assertNull($names->candidateId('cand:'));
        $this->assertNull($names->candidateId('gptbot'));
    }
}
