<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Health;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Service\Health\TrackingCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrackingCheckTest extends TestCase
{
    /**
     * @param array $enabledByStore id => bool
     * @param bool $crawlers
     * @param bool $visits
     * @return TrackingCheck
     */
    private function check(array $enabledByStore, bool $crawlers, bool $visits): TrackingCheck
    {
        $stores = [];
        foreach (array_keys($enabledByStore) as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getCode')->willReturn('store' . $id);
            $store->method('getIsActive')->willReturn(1);
            $stores[] = $store;
        }
        $inactive = $this->createStub(StoreInterface::class);
        $inactive->method('getId')->willReturn(99);
        $inactive->method('getCode')->willReturn('closed');
        $inactive->method('getIsActive')->willReturn(0);
        $stores[] = $inactive;

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn ($id) => $enabledByStore[$id] ?? false);
        $config->method('trackCrawlers')->willReturn($crawlers);
        $config->method('trackVisits')->willReturn($visits);
        $config->method('discoveryEnabled')->willReturn(true);
        return new TrackingCheck($config, $storeManager);
    }

    public static function switchProvider(): array
    {
        return [
            'all on' => [[1 => true, 2 => true], true, true, HealthResult::STATUS_OK, 'on for every'],
            'all stores off' => [[1 => false, 2 => false], true, true, HealthResult::STATUS_WARN, 'every store view'],
            'one store off' => [[1 => true, 2 => false], true, true, HealthResult::STATUS_WARN, 'store2'],
            'crawlers off' => [[1 => true], false, true, HealthResult::STATUS_WARN, 'Track AI Crawlers'],
            'visits off' => [[1 => true], true, false, HealthResult::STATUS_WARN, 'Track Visits'],
        ];
    }

    /**
     * @dataProvider switchProvider
     */
    #[DataProvider('switchProvider')]
    public function testSwitches(array $enabled, bool $crawlers, bool $visits, string $status, string $needle): void
    {
        $result = $this->check($enabled, $crawlers, $visits)->run();

        $this->assertSame($status, $result->status);
        $this->assertStringContainsString($needle, $result->message);
        $this->assertStringNotContainsString('closed', $result->message);
        if ($status === HealthResult::STATUS_WARN) {
            $this->assertStringContainsString('Fix:', $result->message);
        }
    }

    public function testExceptionIsUnknown(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willThrowException(new \RuntimeException('nope'));
        $check = new TrackingCheck($this->createStub(Config::class), $storeManager);

        $this->assertSame(HealthResult::STATUS_UNKNOWN, $check->run()->status);
        $this->assertSame('tracking', $check->code());
    }
}
