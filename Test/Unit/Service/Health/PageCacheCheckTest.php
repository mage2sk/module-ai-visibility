<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Health;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Service\Health\PageCacheCheck;
use PHPUnit\Framework\TestCase;

class PageCacheCheckTest extends TestCase
{
    /**
     * @param mixed $application
     * @param array $stores [id, code, active, source]
     * @param \Throwable|null $storeFailure
     * @return PageCacheCheck
     */
    private function check($application, array $stores, ?\Throwable $storeFailure = null): PageCacheCheck
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($application);

        $storeStubs = [];
        $sources = [];
        foreach ($stores as [$id, $code, $active, $source]) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getCode')->willReturn($code);
            $store->method('getIsActive')->willReturn($active);
            $storeStubs[] = $store;
            $sources[$id] = $source;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($storeFailure !== null) {
            $storeManager->method('getStores')->willThrowException($storeFailure);
        } else {
            $storeManager->method('getStores')->willReturn($storeStubs);
        }
        $config = $this->createStub(Config::class);
        $config->method('crawlerSource')->willReturnCallback(fn (int $id) => $sources[$id] ?? 'magento');

        return new PageCacheCheck($scopeConfig, $storeManager, $config);
    }

    public function testBuiltInCacheIsOk(): void
    {
        $result = $this->check('1', [[1, 'default', 1, 'magento']])->run();
        $this->assertSame(HealthResult::STATUS_OK, $result->status);
        $this->assertStringContainsString('built-in', $result->message);
    }

    public function testVarnishWithoutLogImportWarnsWithFix(): void
    {
        $result = $this->check('2', [
            [1, 'default', 1, 'magento'],
            [2, 'second', 1, 'log'],
            [3, 'inactive', 0, 'magento'],
        ])->run();

        $this->assertSame(HealthResult::STATUS_WARN, $result->status);
        $this->assertStringContainsString('default', $result->message);
        $this->assertStringNotContainsString('inactive', $result->message);
        $this->assertStringContainsString('Fix:', $result->message);
        $this->assertSame(['default'], $result->details['uncovered_stores']);
        $this->assertLessThanOrEqual(512, mb_strlen($result->message));
    }

    public function testVarnishWithFullLogImportCoverageIsOk(): void
    {
        $result = $this->check(2, [[1, 'default', 1, 'log']])->run();
        $this->assertSame(HealthResult::STATUS_OK, $result->status);
    }

    public function testExceptionBecomesUnknown(): void
    {
        $result = $this->check('2', [], new \RuntimeException('store table gone'))->run();
        $this->assertSame(HealthResult::STATUS_UNKNOWN, $result->status);
        $this->assertStringContainsString('store table gone', $result->message);
    }

    public function testCodeAndLabel(): void
    {
        $check = $this->check('1', []);
        $this->assertSame('page_cache', $check->code());
        $this->assertSame('Page cache coverage', $check->label());
    }
}
