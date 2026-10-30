<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use Panth\AiVisibility\Api\AttributionCookieInterface;
use Panth\AiVisibility\Model\Attribution;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Observer\AttributeOrder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AttributeOrderTest extends TestCase
{
    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $queries = [];

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

    /**
     * @var int
     */
    private int $cookieReads = 0;

    /**
     * @var int
     */
    private int $firstAt = 0;

    /**
     * @var int
     */
    private int $lastAt = 0;

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $queryError = null;

    /**
     * Rows already stored, keyed by order id (emulates the primary key + INSERT IGNORE)
     *
     * @var array<int, array>
     */
    private array $stored = [];

    /**
     * @param Attribution|null|\Throwable $cookie
     * @param string|\Throwable $area
     * @param bool $enabled
     * @return AttributeOrder
     */
    private function observer($cookie, $area = 'frontend', bool $enabled = true): AttributeOrder
    {
        $cookieService = $this->createStub(AttributionCookieInterface::class);
        $cookieService->method('read')->willReturnCallback(function () use ($cookie) {
            $this->cookieReads++;
            if ($cookie instanceof \Throwable) {
                throw $cookie;
            }
            return $cookie;
        });

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($name) => '`' . $name . '`');
        $connection->method('query')->willReturnCallback(function ($sql, $bind = []) {
            if ($this->queryError !== null) {
                throw $this->queryError;
            }
            $this->queries[] = [$sql, $bind];
            $orderId = (int)$bind[0];
            if (str_starts_with($sql, 'INSERT IGNORE ') && !isset($this->stored[$orderId])) {
                $this->stored[$orderId] = $bind;
            }
            return $this->createStub(\Zend_Db_Statement_Interface::class);
        });

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name) => 'pfx_' . $name);

        $state = $this->createStub(State::class);
        if ($area instanceof \Throwable) {
            $state->method('getAreaCode')->willThrowException($area);
        } else {
            $state->method('getAreaCode')->willReturn($area);
        }

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('attributionWindowDays')->willReturn(30);

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });

        return new AttributeOrder($cookieService, $resource, $state, $config, $logger);
    }

    private function event(?object $order): Observer
    {
        $event = new Event(['order' => $order]);
        return new Observer(['event' => $event]);
    }

    private function namedEvent(string $name, array $data): Observer
    {
        $event = new Event($data);
        $event->setName($name);
        return new Observer(['event' => $event]);
    }

    private function order(int $id = 1001, int $storeId = 1): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getEntityId')->willReturn($id);
        $order->method('getStoreId')->willReturn($storeId);
        return $order;
    }

    private function attribution(): Attribution
    {
        return new Attribution('chatgpt', $this->firstAt, 'perplexity', $this->lastAt, '/gear/tent.html');
    }

    protected function setUp(): void
    {
        $this->firstAt = time() - 2 * 86400;
        $this->lastAt = time() - 86400;
    }

    public function testNoCookieWritesNothing(): void
    {
        $this->observer(null)->execute($this->event($this->order()));
        $this->assertSame(1, $this->cookieReads);
        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->warnings);
    }

    public function testExpiredAttributionWritesNothing(): void
    {
        // The cookie service already returns null for expired values; the observer double-checks the window.
        $expired = new Attribution('chatgpt', time() - 40 * 86400, 'chatgpt', time() - 31 * 86400, '/a');
        $this->observer($expired)->execute($this->event($this->order()));
        $this->assertSame(1, $this->cookieReads);
        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->warnings);
    }

    public function testAttributionInsideTheWindowIsWritten(): void
    {
        $recent = new Attribution('chatgpt', time() - 40 * 86400, 'gemini', time() - 29 * 86400, '/a');
        $this->observer($recent)->execute($this->event($this->order()));
        $this->assertCount(1, $this->queries);
    }

    public function testValidCookieInsertsReferralRow(): void
    {
        $before = time();
        $this->observer($this->attribution())->execute($this->event($this->order(1001)));
        $after = time();

        $this->assertCount(1, $this->queries);
        [$sql, $bind] = $this->queries[0];
        $this->assertStringStartsWith('INSERT IGNORE INTO `pfx_panth_aiv_order` (', $sql);
        $this->assertSame(
            'INSERT IGNORE INTO `pfx_panth_aiv_order` (`order_id`, `channel`, `first_source`, `first_at`, '
            . '`last_source`, `last_at`, `landing_path`, `created_at`, `is_simulated`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $sql
        );
        $this->assertSame(1001, $bind[0]);
        $this->assertSame('referral', $bind[1]);
        $this->assertSame('chatgpt', $bind[2]);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->firstAt), $bind[3]);
        $this->assertSame('perplexity', $bind[4]);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->lastAt), $bind[5]);
        $this->assertSame('/gear/tent.html', $bind[6]);
        $created = strtotime($bind[7] . ' UTC');
        $this->assertGreaterThanOrEqual($before, $created);
        $this->assertLessThanOrEqual($after, $created);
        $this->assertSame(0, $bind[8]);
        $this->assertSame([], $this->warnings);
    }

    public function testDuplicateOrderKeepsFirstRow(): void
    {
        $observer = $this->observer($this->attribution());
        $observer->execute($this->event($this->order(7)));
        $first = $this->stored[7];
        $observer->execute($this->event($this->order(7)));

        $this->assertCount(2, $this->queries);
        $this->assertStringStartsWith('INSERT IGNORE INTO', $this->queries[1][0]);
        $this->assertCount(1, $this->stored);
        $this->assertSame($first, $this->stored[7]);
        $this->assertSame([], $this->warnings);
    }

    public function testMultishippingOrdersAreAllAttributedWithOneCookieRead(): void
    {
        $observer = $this->observer($this->attribution());
        $observer->execute($this->namedEvent(
            AttributeOrder::EVENT_SUBMIT_ALL_AFTER,
            ['orders' => [$this->order(11), $this->order(12), $this->order(0), new \stdClass()], 'quote' => null]
        ));

        $this->assertSame([11, 12], array_keys($this->stored));
        $this->assertCount(2, $this->queries);
        $this->assertSame(1, $this->cookieReads);
        $this->assertSame('chatgpt', $this->stored[12][2]);
        $this->assertSame([], $this->warnings);
    }

    public function testMultishippingSkipsOrdersOfDisabledStores(): void
    {
        $this->observer($this->attribution(), 'frontend', false)->execute($this->namedEvent(
            AttributeOrder::EVENT_SUBMIT_ALL_AFTER,
            ['orders' => [$this->order(11), $this->order(12)]]
        ));
        $this->assertSame([], $this->queries);
        $this->assertSame(0, $this->cookieReads);
    }

    public function testOnePageCheckoutIsNotWrittenTwice(): void
    {
        $observer = $this->observer($this->attribution());
        $order = $this->order(7);
        $observer->execute($this->namedEvent('sales_model_service_quote_submit_success', ['order' => $order]));
        $observer->execute($this->namedEvent(AttributeOrder::EVENT_SUBMIT_ALL_AFTER, ['order' => $order]));

        $this->assertCount(1, $this->queries);
        $this->assertSame(1, $this->cookieReads);
        $this->assertSame([7], array_keys($this->stored));
    }

    public function testObserverIsRegisteredForOnePageAndMultishippingCheckout(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/events.xml');
        $events = [];
        foreach ($xml->event as $event) {
            foreach ($event->observer as $observer) {
                $events[(string)$event['name']] = (string)$observer['instance'];
            }
        }
        $this->assertSame(AttributeOrder::class, $events['sales_model_service_quote_submit_success'] ?? null);
        $this->assertSame(AttributeOrder::class, $events[AttributeOrder::EVENT_SUBMIT_ALL_AFTER] ?? null);
    }

    public function testAdminAreaIsSkippedWithoutReadingTheCookie(): void
    {
        $this->observer($this->attribution(), 'adminhtml')->execute($this->event($this->order()));
        $this->assertSame(0, $this->cookieReads);
        $this->assertSame([], $this->queries);
    }

    public function testUnknownAreaIsTreatedAsStorefront(): void
    {
        $area = new \Magento\Framework\Exception\LocalizedException(__('Area code is not set'));
        $this->observer($this->attribution(), $area)->execute($this->event($this->order()));
        $this->assertCount(1, $this->queries);
    }

    public function testDisabledStoreIsSkipped(): void
    {
        $this->observer($this->attribution(), 'frontend', false)->execute($this->event($this->order()));
        $this->assertSame(0, $this->cookieReads);
        $this->assertSame([], $this->queries);
    }

    public function testMissingOrUnsavedOrderIsSkipped(): void
    {
        $observer = $this->observer($this->attribution());
        $observer->execute($this->event(null));
        $observer->execute($this->event(new \stdClass()));
        $observer->execute($this->event($this->order(0)));
        $this->assertSame([], $this->queries);
        $this->assertSame(0, $this->cookieReads);
    }

    public function testDatabaseErrorNeverEscapes(): void
    {
        $this->queryError = new \RuntimeException('SQLSTATE[23000] constraint');
        $this->observer($this->attribution())->execute($this->event($this->order()));
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('SQLSTATE[23000]', $this->warnings[0]);
    }

    public function testCookieErrorNeverEscapes(): void
    {
        $this->observer(new \Error('broken'))->execute($this->event($this->order()));
        $this->assertSame([], $this->queries);
        $this->assertCount(1, $this->warnings);
    }

    public function testLongValuesAreCutAndMissingTouchFallsBack(): void
    {
        $attribution = new Attribution('', 0, str_repeat('s', 80), $this->lastAt, '/' . str_repeat('p', 600));
        $this->observer($attribution)->execute($this->event($this->order()));

        $bind = $this->queries[0][1];
        $this->assertSame(str_repeat('s', 64), $bind[2]);
        $this->assertSame(str_repeat('s', 64), $bind[4]);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->lastAt), $bind[3]);
        $this->assertSame(512, strlen($bind[6]));
    }

    public function testEmptyLandingPathIsStoredAsNull(): void
    {
        $this->observer(new Attribution('claude', $this->lastAt, 'claude', $this->lastAt, ''))
            ->execute($this->event($this->order()));
        $this->assertNull($this->queries[0][1][6]);
    }

    public function testAttributionWithoutAnySourceIsIgnored(): void
    {
        $this->observer(new Attribution('', $this->lastAt, ' ', $this->lastAt, '/x'))
            ->execute($this->event($this->order()));
        $this->assertSame([], $this->queries);
    }
}
