<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Panth\AiVisibility\Model\Catalogue\BotCatalogue;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Service\BotClassifier;
use PHPUnit\Framework\TestCase;

class BotClassifierTest extends TestCase
{
    /**
     * @var int
     */
    private int $ruleCalls = 0;

    private function classifier(array $custom = [], array $rules = []): BotClassifier
    {
        $config = $this->createStub(Config::class);
        $config->method('customBotPatterns')->willReturn($custom);
        $provider = $this->createStub(RuleProvider::class);
        $provider->method('botRules')->willReturnCallback(function () use ($rules): array {
            $this->ruleCalls++;
            return $rules;
        });
        return new BotClassifier($config, $provider);
    }

    /**
     * Real-world User-Agent strings for every catalogue bot
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function botProvider(): array
    {
        $wk = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ';
        return [
            'gptbot' => [$wk . 'GPTBot/1.2; +https://openai.com/gptbot)', 'gptbot'],
            'oai_searchbot' => [
                'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; '
                . '+https://openai.com/searchbot',
                'oai_searchbot',
            ],
            'chatgpt_user' => [
                'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; '
                . '+https://openai.com/bot',
                'chatgpt_user',
            ],
            'claudebot' => [$wk . 'ClaudeBot/1.0; +claudebot@anthropic.com)', 'claudebot'],
            'claude_searchbot' => [$wk . 'Claude-SearchBot/1.0; +https://www.anthropic.com)', 'claude_searchbot'],
            'claude_user' => [$wk . 'Claude-User/1.0; +Claude-User@anthropic.com)', 'claude_user'],
            'anthropic_ai' => ['anthropic-ai', 'anthropic_ai'],
            'claude_web' => ['Claude-Web/1.0 (web crawler; +https://www.anthropic.com/)', 'claude_web'],
            'perplexitybot' => [$wk . 'PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 'perplexitybot'],
            'perplexity_user' => [
                $wk . 'Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)',
                'perplexity_user',
            ],
            'meta_externalagent' => [
                'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
                'meta_externalagent',
            ],
            'meta_externalfetcher' => [
                'meta-externalfetcher/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
                'meta_externalfetcher',
            ],
            'amazonbot' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_1) AppleWebKit/600.2.5 (KHTML, like Gecko) '
                . 'Version/8.0.2 Safari/600.2.5 (Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot)',
                'amazonbot',
            ],
            'applebot' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)',
                'applebot',
            ],
            'duckassistbot' => ['DuckAssistBot/1.2; (+http://duckduckgo.com/duckassistbot.html)', 'duckassistbot'],
            'mistralai_user' => [$wk . 'MistralAI-User/1.0; +https://docs.mistral.ai/robots)', 'mistralai_user'],
            'youbot' => ['Mozilla/5.0 (compatible; YouBot/1.0; +https://about.you.com/youbot/)', 'youbot'],
            'bytespider' => [
                'Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 '
                . '(compatible; Bytespider; spider-feedback@bytedance.com)',
                'bytespider',
            ],
            'ccbot' => ['CCBot/2.0 (https://commoncrawl.org/faq/)', 'ccbot'],
            'cohere_ai' => ['cohere-ai', 'cohere_ai'],
            'cohere_crawler' => ['cohere-training-data-crawler/1.0 (+https://cohere.com)', 'cohere_crawler'],
            'diffbot' => [
                'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US; rv:1.9.1.2) Gecko/20090729 Firefox/3.5.2 '
                . '(.NET CLR 3.5.30729; Diffbot/0.1; +http://www.diffbot.com)',
                'diffbot',
            ],
            'lower case' => ['gptbot/1.0', 'gptbot'],
            'upper case' => ['MOZILLA/5.0 (COMPATIBLE; CLAUDEBOT/1.0)', 'claudebot'],
        ];
    }

    /**
     * @dataProvider botProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('botProvider')]
    public function testEveryCatalogueBotIsClassified(string $userAgent, string $code): void
    {
        $classifier = $this->classifier();
        $match = $classifier->classify($userAgent);
        $this->assertNotNull($match, $userAgent);
        $this->assertSame($code, $match->code);
        [$name, $company, $category] = BotCatalogue::BOTS[$code];
        $this->assertSame($name, $match->name);
        $this->assertSame($company, $match->company);
        $this->assertSame($category, $match->category);
        $this->assertFalse($classifier->isDiscoveryCandidate($userAgent), 'Known bots are not candidates');
    }

    public function testEveryCatalogueCodeIsCoveredByTheProvider(): void
    {
        $covered = array_unique(array_column(self::botProvider(), 1));
        sort($covered);
        $codes = array_keys(BotCatalogue::BOTS);
        sort($codes);
        $this->assertSame($codes, $covered);
    }

    /**
     * Browser User-Agents that must neither match nor become candidates
     *
     * @return array<string, array{0: string}>
     */
    public static function browserProvider(): array
    {
        return [
            'chrome windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/130.0.0.0 Safari/537.36',
            ],
            'edge' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/130.0.0.0 Safari/537.36 Edg/130.0.2849.68',
            ],
            'firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0'],
            'safari mac' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/18.0 Safari/605.1.15',
            ],
            'safari iphone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/18.0 Mobile/15E148 Safari/604.1',
            ],
            'samsung' => [
                'Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
            ],
            'opera' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/129.0.0.0 Safari/537.36 OPR/114.0.0.0',
            ],
            'facebook in-app' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Mobile/15E148 [FBAN/FBIOS;FBAV/470.0.0.40.97;FBBV/618515616;FBDV/iPhone15,2]',
            ],
            'cubot phone' => [
                'Mozilla/5.0 (Linux; Android 10; CUBOT X30) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/120.0.0.0 Mobile Safari/537.36',
            ],
            'ipad' => [
                'Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'CriOS/129.0.6668.69 Mobile/15E148 Safari/604.1',
            ],
        ];
    }

    /**
     * @dataProvider browserProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('browserProvider')]
    public function testBrowsersDoNotMatch(string $userAgent): void
    {
        $classifier = $this->classifier();
        $this->assertNull($classifier->classify($userAgent));
        $this->assertFalse($classifier->isDiscoveryCandidate($userAgent));
    }

    /**
     * Search engines, social previews, SEO tools, monitors and cache warmers
     *
     * @return array<string, array{0: string}>
     */
    public static function excludedProvider(): array
    {
        return [
            'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'googlebot smartphone' => [
                'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/130.0.6723.69 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            ],
            'google inspection' => [
                'Mozilla/5.0 (compatible; Google-InspectionTool/1.0;)',
            ],
            'googleother' => ['GoogleOther'],
            'storebot' => [
                'Mozilla/5.0 (X11; Linux x86_64; Storebot-Google/1.0) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/79.0.3945.88 Safari/537.36',
            ],
            'adsbot' => ['AdsBot-Google (+http://www.google.com/adsbot.html)'],
            'feedfetcher' => ['FeedFetcher-Google; (+http://www.google.com/feedfetcher.html)'],
            'bingbot' => [
                'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; '
                . '+http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36',
            ],
            'yandex' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'],
            'baidu' => ['Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)'],
            'duckduckbot' => ['DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)'],
            'slurp' => ['Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)'],
            'petalbot' => [
                'Mozilla/5.0 (compatible;PetalBot;+https://webmaster.petalsearch.com/site/petalbot)',
            ],
            'facebook preview' => [
                'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
            ],
            'twitterbot' => ['Twitterbot/1.0'],
            'linkedin' => ['LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)'],
            'slackbot' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)'],
            'discordbot' => ['Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)'],
            'telegram' => ['TelegramBot (like TwitterBot)'],
            'whatsapp' => ['WhatsApp/2.23.20.0'],
            'ahrefs' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
            'semrush' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)'],
            'mj12' => ['Mozilla/5.0 (compatible; MJ12bot/v1.4.8; http://mj12bot.com/)'],
            'dotbot' => ['Mozilla/5.0 (compatible; DotBot/1.2; +https://opensiteexplorer.org/dotbot)'],
            'dataforseo' => ['Mozilla/5.0 (compatible; DataForSeoBot/1.0; +https://dataforseo.com/dataforseo-bot)'],
            'uptimerobot' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'],
            'pingdom' => ['Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)'],
            'lighthouse' => [
                'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/119.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse',
            ],
            'headless' => [
                'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 '
                . 'Safari/537.36 crawler',
            ],
            'magento warmer' => ['Magento Cache Warmer Bot'],
            'own warmer' => ['Panth-Warmer-Bot/1.0'],
            'generic warmer' => ['cache-warmup-agent/2.0'],
        ];
    }

    /**
     * @dataProvider excludedProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('excludedProvider')]
    public function testExcludedBotsAreNeitherMatchedNorCandidates(string $userAgent): void
    {
        $classifier = $this->classifier();
        $this->assertNull($classifier->classify($userAgent));
        $this->assertFalse($classifier->isDiscoveryCandidate($userAgent));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function candidateProvider(): array
    {
        return [
            'planted sim agent' => ['SimAgent/1.0 (+https://example.invalid/bot)', true],
            'llm crawler' => ['Mozilla/5.0 (compatible; NewLLMCrawler/0.3; +https://example.invalid)', true],
            'fetcher' => ['ExampleFetcher/2.1', true],
            'scraper' => ['shop-scraper/1.0', true],
            'gpt-ish' => ['MyGPTReader/1.0', true],
            'dash user' => ['Example-User/1.0', true],
            'spider' => ['TinySpider/0.1', true],
            'empty' => ['', false],
            'blank' => ['   ', false],
            'curl' => ['curl/8.4.0', false],
            'python' => ['python-requests/2.32.3', false],
            'username' => ['Example-Username/1.0', false],
        ];
    }

    /**
     * @dataProvider candidateProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('candidateProvider')]
    public function testDiscoveryCandidates(string $userAgent, bool $expected): void
    {
        $classifier = $this->classifier();
        $this->assertNull($classifier->classify($userAgent));
        $this->assertSame($expected, $classifier->isDiscoveryCandidate($userAgent));
    }

    public function testEmptyUserAgentIsNotClassified(): void
    {
        $classifier = $this->classifier();
        $this->assertNull($classifier->classify(''));
        $this->assertNull($classifier->classify("  \t"));
        $this->assertSame(0, $this->ruleCalls, 'Empty User-Agents never load the rules');
    }

    public function testCustomPatternsAreMatched(): void
    {
        $classifier = $this->classifier([
            ['name' => 'Shop Assistant', 'token' => 'ShopAssist', 'category' => 'live', 'company' => 'Shop Inc'],
            ['name' => 'Second', 'token' => 'SecondBot', 'category' => 'search', 'company' => ''],
        ]);
        $match = $classifier->classify('Mozilla/5.0 (compatible; shopassist/1.0)');
        $this->assertNotNull($match);
        $this->assertSame('custom_shop_assistant', $match->code);
        $this->assertSame('Shop Assistant', $match->name);
        $this->assertSame('Shop Inc', $match->company);
        $this->assertSame('live', $match->category);

        $second = $classifier->classify('SecondBot/2');
        $this->assertNotNull($second);
        $this->assertSame('Second', $second->company, 'Company defaults to the name');
        $this->assertFalse($classifier->isDiscoveryCandidate('SecondBot/2'));
    }

    public function testCustomPatternEdgeCases(): void
    {
        $classifier = $this->classifier([
            ['name' => 'Too Short', 'token' => 'ab', 'category' => 'live', 'company' => ''],
            ['name' => '', 'token' => 'NoNameBot', 'category' => 'live', 'company' => ''],
            ['name' => 'Dup', 'token' => 'DupOneBot', 'category' => 'nonsense', 'company' => ''],
            ['name' => 'Dup', 'token' => 'DupTwoBot', 'category' => 'training', 'company' => ''],
            ['name' => '***', 'token' => 'SymbolBot', 'category' => 'search', 'company' => ''],
        ]);
        $this->assertNull($classifier->classify('ab ab ab'));
        $this->assertNull($classifier->classify('NoNameBot/1'));
        $one = $classifier->classify('DupOneBot');
        $two = $classifier->classify('DupTwoBot');
        $this->assertSame('custom_dup', $one->code);
        $this->assertSame('other', $one->category, 'Unknown categories become other');
        $this->assertSame('custom_dup_2', $two->code, 'Clashing codes get a suffix');
        $symbol = $classifier->classify('SymbolBot');
        $this->assertMatchesRegularExpression('/^custom_[0-9a-f]{8}$/', $symbol->code);
    }

    public function testConfirmedRulesAreMatched(): void
    {
        $classifier = $this->classifier([], [
            [
                'rule_id' => 1, 'type' => 'bot', 'code' => 'disc_sim_agent', 'name' => 'Sim Agent',
                'match_on' => 'ua', 'pattern' => 'SimAgent/', 'category' => 'search', 'company' => null,
                'candidate_id' => 3, 'created_at' => '2026-01-01 00:00:00',
            ],
            [
                'rule_id' => 2, 'type' => 'bot', 'code' => 'disc_bad', 'name' => '', 'match_on' => 'ua',
                'pattern' => 'BadCatBot', 'category' => 'weird', 'company' => '',
            ],
            ['rule_id' => 3, 'type' => 'bot', 'code' => '', 'pattern' => 'NoCodeBot'],
            ['rule_id' => 4, 'type' => 'bot', 'code' => 'disc_short', 'pattern' => 'x'],
        ]);
        $ua = 'SimAgent/1.0 (+https://example.invalid/bot)';
        $match = $classifier->classify($ua);
        $this->assertNotNull($match);
        $this->assertSame('disc_sim_agent', $match->code);
        $this->assertSame('Sim Agent', $match->company, 'Company defaults to the name');
        $this->assertSame('search', $match->category);
        $this->assertFalse($classifier->isDiscoveryCandidate($ua), 'Confirmed bots are no longer candidates');

        $bad = $classifier->classify('BadCatBot');
        $this->assertSame('disc_bad', $bad->name, 'Name defaults to the code');
        $this->assertSame('other', $bad->category);
        $this->assertNull($classifier->classify('NoCodeBot'));
    }

    public function testLongestTokenWinsRegardlessOfOrder(): void
    {
        // "GPTBot" (6) is contained in the custom token "GPTBot-Mini" (11): the longer one wins.
        $classifier = $this->classifier(
            [['name' => 'Mini', 'token' => 'GPTBot-Mini', 'category' => 'live', 'company' => 'Mini Co']]
        );
        $this->assertSame('custom_mini', $classifier->classify('GPTBot-Mini/1.0')->code);
        $this->assertSame('gptbot', $classifier->classify('GPTBot/1.2')->code);

        // A rule token shorter than a built-in token loses when both are present.
        $classifier = $this->classifier([], [
            ['rule_id' => 1, 'type' => 'bot', 'code' => 'disc_claude', 'name' => 'C', 'pattern' => 'claude'],
        ]);
        $this->assertSame('claude_searchbot', $classifier->classify('Claude-SearchBot/1.0')->code);
        $this->assertSame('disc_claude', $classifier->classify('claude-something')->code);
    }

    public function testEqualLengthTiesPreferBuiltInThenCustomThenRules(): void
    {
        $classifier = $this->classifier(
            [['name' => 'Custom', 'token' => 'GPTBot', 'category' => 'live', 'company' => '']],
            [['rule_id' => 1, 'type' => 'bot', 'code' => 'disc_x', 'name' => 'X', 'pattern' => 'XYZBot']]
        );
        $this->assertSame('gptbot', $classifier->classify('GPTBot/1')->code);

        $classifier = $this->classifier(
            [['name' => 'Custom', 'token' => 'XYZBot', 'category' => 'live', 'company' => '']],
            [['rule_id' => 1, 'type' => 'bot', 'code' => 'disc_x', 'name' => 'X', 'pattern' => 'xyzbot']]
        );
        $this->assertSame('custom_custom', $classifier->classify('XYZBot/1')->code);
    }

    public function testTokenTableAndLastResultAreCached(): void
    {
        $classifier = $this->classifier();
        $first = $classifier->classify('GPTBot/1.0');
        $this->assertSame($first, $classifier->classify('GPTBot/1.0'));
        $classifier->classify('Mozilla/5.0 Firefox/131.0');
        $classifier->isDiscoveryCandidate('Mozilla/5.0 Firefox/131.0');
        $this->assertSame(1, $this->ruleCalls, 'Rules are read once per request');
        $classifier->reset();
        $classifier->classify('CCBot/2.0');
        $this->assertSame(2, $this->ruleCalls);
    }

    public function testRuleOrConfigErrorsFallBackToTheCatalogue(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('customBotPatterns')->willThrowException(new \RuntimeException('broken'));
        $provider = $this->createStub(RuleProvider::class);
        $provider->method('botRules')->willThrowException(new \RuntimeException('broken'));
        $classifier = new BotClassifier($config, $provider);
        $this->assertSame('gptbot', $classifier->classify('GPTBot/1.0')->code);
    }

    public function testVeryLongUserAgentIsHandled(): void
    {
        $classifier = $this->classifier();
        $this->assertSame('gptbot', $classifier->classify('GPTBot/1.0 ' . str_repeat('x', 10000))->code);
        $this->assertNull($classifier->classify(str_repeat('a', 5000) . 'GPTBot'), 'Token beyond the cut');
    }
}
