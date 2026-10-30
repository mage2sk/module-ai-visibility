<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Model\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * @var array<int, string>
     */
    private array $storeCodes = [1 => 'default', 2 => 'Luma_EN'];

    /**
     * @var array<int, array>
     */
    private array $flagCalls = [];

    private function config(): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path) => $this->values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            function (string $path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null): bool {
                $this->flagCalls[] = [$path, $scopeType, $scopeCode];
                return (bool)($this->values[$path] ?? false);
            }
        );

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function ($storeId) {
            if (!isset($this->storeCodes[(int)$storeId])) {
                throw new NoSuchEntityException(__('No such store'));
            }
            $store = $this->createStub(StoreInterface::class);
            $store->method('getCode')->willReturn($this->storeCodes[(int)$storeId]);
            return $store;
        });

        return new Config($scopeConfig, $storeManager);
    }

    public function testDefaultsWhenNothingIsConfigured(): void
    {
        $config = $this->config();

        $this->assertFalse($config->isEnabled());
        $this->assertFalse($config->trackCrawlers());
        $this->assertSame(30, $config->attributionWindowDays());
        $this->assertSame('first', $config->attributionModel());
        $this->assertSame(200000, $config->rawDailyCap());
        $this->assertSame(20, $config->rateLimitPerMinute());
        $this->assertSame(25, $config->landingSampleRate());
        $this->assertSame(14, $config->hitRetentionDays());
        $this->assertSame(30, $config->rawVisitRetentionDays());
        $this->assertSame(30, $config->landingRetentionDays());
        $this->assertSame(13, $config->dailyRetentionMonths());
        $this->assertSame(200000, $config->logImportMaxLines());
        $this->assertSame([], $config->customBotPatterns());
        $this->assertSame([], $config->customSourceHosts());
        $this->assertSame([], $config->customUtm());
        $this->assertSame([], $config->logImportFiles());
        $this->assertSame('magento', $config->crawlerSource(1));
    }

    public function testIsEnabledReadsStoreScope(): void
    {
        $this->values[Config::XML_PATH_ENABLED] = '1';
        $this->assertTrue($this->config()->isEnabled(2));
        $this->assertSame([Config::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, 2], $this->flagCalls[0]);
    }

    public function testFlagsReadDefaultScope(): void
    {
        $this->values[Config::XML_PATH_TRACK_CRAWLERS] = '1';
        $this->values[Config::XML_PATH_TRACK_VISITS] = '1';
        $this->values[Config::XML_PATH_DISCOVERY_ENABLED] = '0';
        $this->values[Config::XML_PATH_RESPECT_COOKIE_CONSENT] = '1';
        $this->values[Config::XML_PATH_LOG_IMPORT_ENABLED] = '1';
        $config = $this->config();

        $this->assertTrue($config->trackCrawlers());
        $this->assertTrue($config->trackVisits());
        $this->assertFalse($config->discoveryEnabled());
        $this->assertTrue($config->respectCookieConsent());
        $this->assertTrue($config->logImportEnabled());
        foreach ($this->flagCalls as $call) {
            $this->assertSame(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $call[1]);
            $this->assertNull($call[2]);
        }
    }

    public function testIntegersAreClampedAndInvalidValuesFallBack(): void
    {
        $cases = [
            // path, raw value, expected
            [Config::XML_PATH_ATTRIBUTION_WINDOW_DAYS, '45', 45],
            [Config::XML_PATH_ATTRIBUTION_WINDOW_DAYS, ' 7 ', 7],
            [Config::XML_PATH_ATTRIBUTION_WINDOW_DAYS, '0', 1],
            [Config::XML_PATH_ATTRIBUTION_WINDOW_DAYS, '9999', 365],
            [Config::XML_PATH_ATTRIBUTION_WINDOW_DAYS, 'abc', 30],
            [Config::XML_PATH_ATTRIBUTION_WINDOW_DAYS, '12.5', 30],
            [Config::XML_PATH_ATTRIBUTION_WINDOW_DAYS, '', 30],
            [Config::XML_PATH_RAW_DAILY_CAP, '10', 1000],
            [Config::XML_PATH_RAW_DAILY_CAP, '99999999999', 10000000],
            [Config::XML_PATH_RATE_LIMIT_PER_MINUTE, '-5', 1],
            [Config::XML_PATH_RATE_LIMIT_PER_MINUTE, 50, 50],
            [Config::XML_PATH_LANDING_SAMPLE_RATE, '0', 0],
            [Config::XML_PATH_LANDING_SAMPLE_RATE, '150', 100],
            [Config::XML_PATH_HIT_RETENTION_DAYS, '400', 365],
            [Config::XML_PATH_RAW_VISIT_RETENTION_DAYS, '731', 730],
            [Config::XML_PATH_LANDING_RETENTION_DAYS, '0', 1],
            [Config::XML_PATH_DAILY_RETENTION_MONTHS, '61', 60],
            [Config::XML_PATH_LOG_IMPORT_MAX_LINES, '1', 1000],
        ];
        $getters = [
            Config::XML_PATH_ATTRIBUTION_WINDOW_DAYS => 'attributionWindowDays',
            Config::XML_PATH_RAW_DAILY_CAP => 'rawDailyCap',
            Config::XML_PATH_RATE_LIMIT_PER_MINUTE => 'rateLimitPerMinute',
            Config::XML_PATH_LANDING_SAMPLE_RATE => 'landingSampleRate',
            Config::XML_PATH_HIT_RETENTION_DAYS => 'hitRetentionDays',
            Config::XML_PATH_RAW_VISIT_RETENTION_DAYS => 'rawVisitRetentionDays',
            Config::XML_PATH_LANDING_RETENTION_DAYS => 'landingRetentionDays',
            Config::XML_PATH_DAILY_RETENTION_MONTHS => 'dailyRetentionMonths',
            Config::XML_PATH_LOG_IMPORT_MAX_LINES => 'logImportMaxLines',
        ];
        foreach ($cases as [$path, $raw, $expected]) {
            $this->values = [$path => $raw];
            $getter = $getters[$path];
            $this->assertSame(
                $expected,
                $this->config()->$getter(),
                sprintf('%s with %s', $getter, var_export($raw, true))
            );
        }
    }

    public function testAttributionModel(): void
    {
        $cases = ['last' => 'last', ' LAST ' => 'last', 'first' => 'first', 'linear' => 'first', '' => 'first'];
        foreach ($cases as $raw => $expected) {
            $this->values = [Config::XML_PATH_ATTRIBUTION_MODEL => (string)$raw];
            $this->assertSame($expected, $this->config()->attributionModel());
        }
    }

    public function testCustomBotPatternsAreParsed(): void
    {
        $this->values[Config::XML_PATH_CUSTOM_BOT_PATTERNS] = implode("\r\n", [
            '# comment line',
            'ExampleBot | ExampleBot/ | Search | Example Inc',
            '',
            '  NoCompany|NoCoBot|live  ',
            'BadCategory | BadCatBot | weird',
            'TwoParts | TwoPartsBot',
            'TooShort | ab | training',
            ' | NamelessBot | training',
            'Duplicate | examplebot/ | training',
        ]);

        $this->assertSame(
            [
                ['name' => 'ExampleBot', 'token' => 'ExampleBot/', 'category' => 'search', 'company' => 'Example Inc'],
                ['name' => 'NoCompany', 'token' => 'NoCoBot', 'category' => 'live', 'company' => 'NoCompany'],
                ['name' => 'BadCategory', 'token' => 'BadCatBot', 'category' => 'other', 'company' => 'BadCategory'],
                ['name' => 'TwoParts', 'token' => 'TwoPartsBot', 'category' => 'other', 'company' => 'TwoParts'],
            ],
            $this->config()->customBotPatterns()
        );
    }

    public function testCustomSourceHostsAreNormalised(): void
    {
        $this->values[Config::XML_PATH_CUSTOM_HOSTS] = implode("\n", [
            'assistant.example.invalid | Example Assistant',
            'https://Chat.Shop.Test/some/path?x=1 | Shop Chat',
            'noname.example.invalid',
            'bad host.example | Broken',
            'double..dot.example | Broken',
            'ASSISTANT.example.invalid | Duplicate',
            'port.example.invalid:8443 | Port',
        ]);

        $this->assertSame(
            [
                'assistant.example.invalid' => 'Example Assistant',
                'chat.shop.test' => 'Shop Chat',
                'noname.example.invalid' => 'noname.example.invalid',
                'port.example.invalid' => 'Port',
            ],
            $this->config()->customSourceHosts()
        );
    }

    public function testCustomUtmValuesAreValidated(): void
    {
        $this->values[Config::XML_PATH_CUSTOM_UTM] = implode("\n", [
            'Example-Assistant | Example Assistant',
            'plain_value',
            'has space | Broken',
            str_repeat('a', 65) . ' | Too long',
            'example-assistant | Duplicate',
        ]);

        $this->assertSame(
            ['example-assistant' => 'Example Assistant', 'plain_value' => 'plain_value'],
            $this->config()->customUtm()
        );
    }

    public function testLogImportFilesAreParsed(): void
    {
        $this->values[Config::XML_PATH_LOG_IMPORT_FILES] = implode("\n", [
            '/var/log/nginx/access.log | default',
            '/var/log/nginx/luma.log | Luma_EN',
            '/var/log/nginx/nocode.log',
            'relative/access.log | default',
            '/var/log/bad.log | 9starts_with_digit',
            '/var/log/nginx/access.log | other',
        ]);

        $this->assertSame(
            [
                ['path' => '/var/log/nginx/access.log', 'store_code' => 'default'],
                ['path' => '/var/log/nginx/luma.log', 'store_code' => 'luma_en'],
                ['path' => '/var/log/nginx/nocode.log', 'store_code' => 'default'],
            ],
            $this->config()->logImportFiles()
        );
    }

    public function testCrawlerSourceIsLogOnlyForMappedStoresWhenImportIsEnabled(): void
    {
        $this->values[Config::XML_PATH_LOG_IMPORT_FILES] = '/var/log/nginx/luma.log | luma_en';

        $this->values[Config::XML_PATH_LOG_IMPORT_ENABLED] = '0';
        $this->assertSame('magento', $this->config()->crawlerSource(2), 'Import disabled');

        $this->values[Config::XML_PATH_LOG_IMPORT_ENABLED] = '1';
        $config = $this->config();
        $this->assertSame('log', $config->crawlerSource(2), 'Store code matches case-insensitively');
        $this->assertSame('magento', $config->crawlerSource(1), 'Store not mapped');
        $this->assertSame('magento', $config->crawlerSource(99), 'Unknown store');
    }

    public function testCrawlerSourceWithoutValidFiles(): void
    {
        $this->values[Config::XML_PATH_LOG_IMPORT_ENABLED] = '1';
        $this->values[Config::XML_PATH_LOG_IMPORT_FILES] = 'not/absolute.log | default';
        $this->assertSame('magento', $this->config()->crawlerSource(1));
    }

    public function testUrlStoreCodeFollowsTheStoreCodeInUrlSetting(): void
    {
        $this->assertSame('', $this->config()->urlStoreCode(2));
        $this->values[Config::XML_PATH_STORE_CODE_IN_URL] = '1';
        $this->assertSame('luma_en', $this->config()->urlStoreCode(2));
        $this->assertSame('default', $this->config()->urlStoreCode(1));
        $this->assertSame('', $this->config()->urlStoreCode(99));
    }

    public function testUrlStoreCodeUsesTheStoreModelWhenAvailable(): void
    {
        $this->values[Config::XML_PATH_STORE_CODE_IN_URL] = '1';
        $store = $this->createStub(\Magento\Store\Model\Store::class);
        $store->method('getCode')->willReturn('en');
        $store->method('isUseStoreInUrl')->willReturn(false);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $config = new Config($this->createStub(ScopeConfigInterface::class), $storeManager);
        $this->assertSame('', $config->urlStoreCode(3), 'single-store mode or a disabled code wins');

        $inUrl = $this->createStub(\Magento\Store\Model\Store::class);
        $inUrl->method('getCode')->willReturn('EN');
        $inUrl->method('isUseStoreInUrl')->willReturn(true);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($inUrl);
        $config = new Config($this->createStub(ScopeConfigInterface::class), $storeManager);
        $this->assertSame('en', $config->urlStoreCode(3));
    }
}
