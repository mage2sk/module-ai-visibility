<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Model\Catalogue;

use Panth\AiVisibility\Model\Catalogue\BotCatalogue;
use Panth\AiVisibility\Model\Source\Category;
use PHPUnit\Framework\TestCase;

class BotCatalogueTest extends TestCase
{
    public function testCatalogueHasTheSpecifiedBots(): void
    {
        $this->assertCount(22, BotCatalogue::BOTS);
        $this->assertSame(['GPTBot', 'OpenAI', 'training', 'GPTBot'], BotCatalogue::BOTS['gptbot']);
        $this->assertSame(
            ['Cohere Training Crawler', 'Cohere', 'training', 'cohere-training-data-crawler'],
            BotCatalogue::BOTS['cohere_crawler']
        );
        $this->assertSame('other', BotCatalogue::BOTS['diffbot'][2]);
    }

    public function testEveryEntryIsWellFormed(): void
    {
        $tokens = [];
        foreach (BotCatalogue::BOTS as $code => $entry) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,64}$/', $code);
            $this->assertCount(4, $entry);
            [$name, $company, $category, $token] = $entry;
            $this->assertNotSame('', $name);
            $this->assertNotSame('', $company);
            $this->assertTrue(Category::isValid($category), $code);
            $this->assertGreaterThanOrEqual(3, strlen($token));
            $tokens[] = strtolower($token);
        }
        $this->assertSame($tokens, array_unique($tokens), 'Tokens are unique');
    }

    public function testNoAiTokenIsOnTheExclusionList(): void
    {
        foreach (BotCatalogue::BOTS as $code => $entry) {
            $this->assertFalse(BotCatalogue::isNonAi($entry[3]), $code);
        }
        foreach (BotCatalogue::NON_AI_TOKENS as $token) {
            $this->assertSame(strtolower($token), $token, 'Exclusion tokens are lower case');
        }
    }

    public function testIsNonAiIsCaseInsensitive(): void
    {
        $this->assertTrue(BotCatalogue::isNonAi('Mozilla/5.0 (compatible; GOOGLEBOT/2.1)'));
        $this->assertTrue(BotCatalogue::isNonAi('Panth-Cache-Warmer/1.0'));
        $this->assertTrue(BotCatalogue::isNonAi('some cache-warmer'));
        $this->assertFalse(BotCatalogue::isNonAi('UnknownBot/1.0'));
    }

    public function testLooksLikeBot(): void
    {
        $this->assertTrue(BotCatalogue::looksLikeBot('NewCrawler/1.0'));
        $this->assertTrue(BotCatalogue::looksLikeBot('Example-User/1.0'));
        $this->assertTrue(BotCatalogue::looksLikeBot('LLM-Reader'));
        $this->assertFalse(BotCatalogue::looksLikeBot('Example-Username/1.0'));
        $this->assertFalse(BotCatalogue::looksLikeBot('curl/8.4.0'));
    }
}
