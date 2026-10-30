<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Report;

use Panth\AiVisibility\Service\BotClassifier;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Service\Report\BotDirectory;
use PHPUnit\Framework\TestCase;

class BotDirectoryTest extends TestCase
{
    public function testBuiltInCustomAndConfirmedBots(): void
    {
        $rules = $this->createStub(RuleProvider::class);
        $rules->method('botRules')->willReturn([
            ['code' => 'disc_simagent', 'name' => 'SimAgent', 'company' => null, 'category' => 'live',
                'pattern' => 'SimAgent'],
            ['code' => 'disc_odd', 'name' => 'Odd', 'company' => 'Odd Co', 'category' => 'weird', 'pattern' => 'OddBot'],
        ]);
        $config = $this->createStub(Config::class);
        $config->method('customBotPatterns')->willReturn([
            ['name' => 'Shop Helper', 'token' => 'ShopHelper', 'category' => 'search', 'company' => 'Helper Inc'],
        ]);
        $directory = new BotDirectory(new BotClassifier($config, $rules));

        $this->assertSame(
            ['name' => 'GPTBot', 'company' => 'OpenAI', 'category' => 'training'],
            $directory->describe('gptbot')
        );
        $this->assertSame('SimAgent', $directory->describe('disc_simagent')['company']);
        $this->assertSame('live', $directory->describe('disc_simagent')['category']);
        $this->assertSame('other', $directory->describe('disc_odd')['category']);
        $this->assertSame('shop_helper', $directory->describe('shop_helper')['company']);
        $this->assertSame('Helper Inc', $directory->describe('custom_shop_helper')['company']);
        $this->assertSame('search', $directory->describe('custom_shop_helper')['category']);
        $this->assertSame(
            ['name' => 'mystery', 'company' => 'mystery', 'category' => 'search'],
            $directory->describe('mystery', 'search')
        );
    }

    public function testFailingRuleLookupKeepsTheBuiltInNames(): void
    {
        $rules = $this->createStub(RuleProvider::class);
        $rules->method('botRules')->willThrowException(new \RuntimeException('db down'));
        $config = $this->createStub(Config::class);
        $config->method('customBotPatterns')->willReturn([]);
        $directory = new BotDirectory(new BotClassifier($config, $rules));
        $this->assertSame('Anthropic', $directory->describe('claudebot')['company']);
    }

    public function testDescribesExactlyTheCodeTheClassifierStores(): void
    {
        $rules = $this->createStub(RuleProvider::class);
        $rules->method('botRules')->willReturn([]);
        $config = $this->createStub(Config::class);
        $config->method('customBotPatterns')->willReturn([
            ['name' => 'Twin', 'token' => 'TwinOne', 'category' => 'live', 'company' => 'A'],
            ['name' => 'Twin', 'token' => 'TwinTwo', 'category' => 'training', 'company' => 'B'],
        ]);
        $classifier = new BotClassifier($config, $rules);
        $directory = new BotDirectory($classifier);

        $second = $classifier->classify('TwinTwo/1.0');
        $this->assertNotNull($second);
        $this->assertSame('custom_twin_2', $second->code);
        $this->assertSame(
            ['name' => 'Twin', 'company' => 'B', 'category' => 'training'],
            $directory->describe($second->code)
        );
        $this->assertSame('A', $directory->describe('custom_twin')['company']);
    }
}
