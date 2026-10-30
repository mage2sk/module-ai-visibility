<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Health;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Service\AttributionCookie;
use Panth\AiVisibility\Service\Health\ConsentCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConsentCheckTest extends TestCase
{
    public static function settingsProvider(): array
    {
        return [
            'restriction off' => [false, true, HealthResult::STATUS_OK, 'off'],
            'restriction on, respected' => [true, true, HealthResult::STATUS_OK, 'respected'],
            'restriction on, ignored' => [true, false, HealthResult::STATUS_WARN, 'Fix:'],
        ];
    }

    /**
     * @dataProvider settingsProvider
     */
    #[DataProvider('settingsProvider')]
    public function testStatus(bool $restriction, bool $respect, string $status, string $needle): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn ($path) => $path === 'web/cookie/cookie_restriction' ? $restriction : false
        );
        $config = $this->createStub(Config::class);
        $config->method('respectCookieConsent')->willReturn($respect);

        $result = (new ConsentCheck($scopeConfig, $config, $this->deployment('base' . 'key1')))->run();

        $this->assertSame($status, $result->status);
        $this->assertStringContainsString($needle, $result->message);
        $this->assertSame($restriction, $result->details['cookie_restriction']);
        $this->assertTrue($result->details['crypt_key']);
    }

    public function testExceptionIsUnknown(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willThrowException(new \RuntimeException('config broken'));

        $check = new ConsentCheck($scopeConfig, $this->createStub(Config::class), $this->deployment('k' . '1'));

        $this->assertSame(HealthResult::STATUS_UNKNOWN, $check->run()->status);
        $this->assertSame('consent', $check->code());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function missingKeyProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'only line breaks' => ["\n \r\n"],
        ];
    }

    /**
     * @dataProvider missingKeyProvider
     * @param mixed $raw
     */
    #[DataProvider('missingKeyProvider')]
    public function testMissingCryptKeyIsAnError($raw): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        $config = $this->createStub(Config::class);
        $config->method('respectCookieConsent')->willReturn(true);

        $result = (new ConsentCheck($scopeConfig, $config, $this->deployment($raw)))->run();

        $this->assertSame(HealthResult::STATUS_ERROR, $result->status);
        $this->assertStringContainsString('crypt/key', $result->message);
        $this->assertFalse($result->details['crypt_key']);
    }

    public function testRotatedKeysUseTheNewestLine(): void
    {
        $this->assertSame('new' . 'er', AttributionCookie::newestCryptKey("old" . "er\n  new" . "er  \n\n"));
        $this->assertSame('new' . 'er', AttributionCookie::newestCryptKey("old" . "er\r\nnew" . "er"));
        $this->assertSame('', AttributionCookie::newestCryptKey(" \n"));
    }

    /**
     * Deployment config returning a crypt key value
     *
     * @param mixed $cryptKey
     * @return DeploymentConfig
     */
    private function deployment($cryptKey): DeploymentConfig
    {
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(
            static fn (string $path) => $path === 'crypt/key' ? $cryptKey : null
        );
        return $deployment;
    }
}
