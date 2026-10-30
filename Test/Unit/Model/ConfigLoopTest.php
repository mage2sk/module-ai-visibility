<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Model\Config;
use PHPUnit\Framework\TestCase;

/**
 * Recommend / verify / weekly email settings (1.1)
 */
class ConfigLoopTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    private function config(): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path) => $this->values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn (string $path): bool => (bool)($this->values[$path] ?? false)
        );
        return new Config($scopeConfig, $this->createStub(StoreManagerInterface::class));
    }

    public function testDefaultsWhenNothingIsConfigured(): void
    {
        $config = $this->config();

        $this->assertFalse($config->recommendationsEnabled());
        $this->assertSame(20, $config->recommendationsMinVolume());
        $this->assertSame(50, $config->recommendationsMaxOpen());
        $this->assertSame(14, $config->verifyWindowDays());
        $this->assertSame(30, $config->verifyMinEvents());
        $this->assertSame(50, $config->verifyMaxControls());
        $this->assertFalse($config->emailEnabled());
        $this->assertSame([], $config->emailRecipients());
        $this->assertSame('general', $config->emailSender());
        $this->assertSame(1, $config->emailWeekday());
        $this->assertSame(7, $config->emailHour());
    }

    public function testFlags(): void
    {
        $this->values = [
            Config::XML_PATH_RECOMMENDATIONS_ENABLED => '1',
            Config::XML_PATH_EMAIL_ENABLED => '1',
        ];
        $config = $this->config();

        $this->assertTrue($config->recommendationsEnabled());
        $this->assertTrue($config->emailEnabled());
    }

    /**
     * @return array
     */
    public static function intProvider(): array
    {
        return [
            'min volume in range' => [Config::XML_PATH_RECOMMENDATIONS_MIN_VOLUME, '5', 'recommendationsMinVolume', 5],
            'min volume below' => [Config::XML_PATH_RECOMMENDATIONS_MIN_VOLUME, '0', 'recommendationsMinVolume', 1],
            'min volume above' => [Config::XML_PATH_RECOMMENDATIONS_MIN_VOLUME, '9999999', 'recommendationsMinVolume', 1000000],
            'min volume junk' => [Config::XML_PATH_RECOMMENDATIONS_MIN_VOLUME, 'many', 'recommendationsMinVolume', 20],
            'max open above' => [Config::XML_PATH_RECOMMENDATIONS_MAX_OPEN, '501', 'recommendationsMaxOpen', 500],
            'max open below' => [Config::XML_PATH_RECOMMENDATIONS_MAX_OPEN, '-3', 'recommendationsMaxOpen', 1],
            'window in range' => [Config::XML_PATH_VERIFY_WINDOW_DAYS, ' 28 ', 'verifyWindowDays', 28],
            'window below' => [Config::XML_PATH_VERIFY_WINDOW_DAYS, '3', 'verifyWindowDays', 7],
            'window above' => [Config::XML_PATH_VERIFY_WINDOW_DAYS, '90', 'verifyWindowDays', 56],
            'window decimal' => [Config::XML_PATH_VERIFY_WINDOW_DAYS, '14.5', 'verifyWindowDays', 14],
            'window int' => [Config::XML_PATH_VERIFY_WINDOW_DAYS, 21, 'verifyWindowDays', 21],
            'min events below' => [Config::XML_PATH_VERIFY_MIN_EVENTS, '0', 'verifyMinEvents', 1],
            'min events empty' => [Config::XML_PATH_VERIFY_MIN_EVENTS, '', 'verifyMinEvents', 30],
            'controls below' => [Config::XML_PATH_VERIFY_MAX_CONTROLS, '2', 'verifyMaxControls', 3],
            'controls above' => [Config::XML_PATH_VERIFY_MAX_CONTROLS, '1000', 'verifyMaxControls', 500],
            'weekday sunday' => [Config::XML_PATH_EMAIL_WEEKDAY, '7', 'emailWeekday', 7],
            'weekday zero' => [Config::XML_PATH_EMAIL_WEEKDAY, '0', 'emailWeekday', 1],
            'weekday above' => [Config::XML_PATH_EMAIL_WEEKDAY, '8', 'emailWeekday', 7],
            'hour midnight' => [Config::XML_PATH_EMAIL_HOUR, '0', 'emailHour', 0],
            'hour above' => [Config::XML_PATH_EMAIL_HOUR, '24', 'emailHour', 23],
            'hour junk' => [Config::XML_PATH_EMAIL_HOUR, '7am', 'emailHour', 7],
        ];
    }

    /**
     * @dataProvider intProvider
     * @param string $path
     * @param mixed $raw
     * @param string $getter
     * @param int $expected
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('intProvider')]
    public function testIntegersAreClampedAndInvalidValuesFallBack(
        string $path,
        $raw,
        string $getter,
        int $expected
    ): void {
        $this->values = [$path => $raw];

        $this->assertSame($expected, $this->config()->$getter());
    }

    /**
     * @return array
     */
    public static function senderProvider(): array
    {
        return [
            'general' => ['general', 'general'],
            'sales' => ['sales', 'sales'],
            'support upper case' => [' Support ', 'support'],
            'custom2' => ['custom2', 'custom2'],
            'unknown identity' => ['marketing', 'general'],
            'empty' => ['', 'general'],
        ];
    }

    /**
     * @dataProvider senderProvider
     * @param string $raw
     * @param string $expected
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('senderProvider')]
    public function testEmailSenderFallsBackToGeneral(string $raw, string $expected): void
    {
        $this->values = [Config::XML_PATH_EMAIL_SENDER => $raw];

        $this->assertSame($expected, $this->config()->emailSender());
    }

    public function testEmailRecipientsSkipInvalidAndDuplicates(): void
    {
        $this->values = [
            Config::XML_PATH_EMAIL_RECIPIENTS => "owner@shop.test, not-an-address\nOwner@Shop.test;ops@example.invalid\r\n\n",
        ];

        $this->assertSame(['owner@shop.test', 'ops@example.invalid'], $this->config()->emailRecipients());
    }

    public function testEmailRecipientsAreCapped(): void
    {
        $list = [];
        for ($i = 1; $i <= 25; $i++) {
            $list[] = 'user' . $i . '@shop.test';
        }
        $this->values = [Config::XML_PATH_EMAIL_RECIPIENTS => implode(',', $list)];

        $recipients = $this->config()->emailRecipients();

        $this->assertCount(Config::EMAIL_MAX_RECIPIENTS, $recipients);
        $this->assertSame('user1@shop.test', $recipients[0]);
        $this->assertSame('user20@shop.test', $recipients[19]);
    }

    public function testEmailRecipientsIgnoreNonStringValues(): void
    {
        $this->values = [Config::XML_PATH_EMAIL_RECIPIENTS => ['owner@shop.test']];

        $this->assertSame([], $this->config()->emailRecipients());
    }

    public function testSplitEmailList(): void
    {
        $tooLong = str_repeat('a', 250) . '@shop.test';

        $this->assertSame(
            ['valid' => ['a@shop.test', 'b@shop.test'], 'invalid' => ['@shop.test', 'c@', $tooLong]],
            Config::splitEmailList(" a@shop.test ; @shop.test, c@ b@shop.test\tA@SHOP.TEST,$tooLong")
        );
        $this->assertSame(['valid' => [], 'invalid' => []], Config::splitEmailList(" \n , ; "));
    }

    public function testEmailTemplateIdMatchesEmailTemplatesXml(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/email_templates.xml');
        $ids = array_map('strval', $xml->xpath('//template/@id') ?: []);

        $this->assertSame([Config::EMAIL_TEMPLATE_WEEKLY_SUMMARY], $ids);
        $file = (string)($xml->xpath('//template/@file')[0] ?? '');
        $area = (string)($xml->xpath('//template/@area')[0] ?? '');
        $this->assertFileExists(dirname(__DIR__, 3) . '/view/' . $area . '/email/' . $file);
    }
}
