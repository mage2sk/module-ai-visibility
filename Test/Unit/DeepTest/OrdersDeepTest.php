<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Panth\AiVisibility\Api\AttributionCookieInterface;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Model\Attribution;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Observer\AttributeOrder;
use Panth\AiVisibility\Plugin\OrderGridCollectionPlugin;
use Panth\AiVisibility\Service\AttributionCookie;
use Panth\AiVisibility\Service\ConsentChecker;
use Panth\AiVisibility\Ui\Component\Listing\Column\AiSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep test D3 (orders and attribution).
 *
 * Runs the order observer together with the REAL signed-cookie service (only the cookie jar,
 * the deployment key and the database adapter are faked), with the exact event payloads that
 * Magento 2.4.7-p3 and 2.4.9 dispatch (QuoteManagement::submitQuote and
 * Multishipping::createOrders - identical in both versions), plus the order grid plugin next to
 * the mage-pos grid joins and the AI Source grid column.
 */
class OrdersDeepTest extends TestCase
{
    private const EVENT_SUCCESS = 'sales_model_service_quote_submit_success';
    private const EVENT_ALL_AFTER = 'checkout_submit_all_after';

    /**
     * @var string|null value of the panth_aiv cookie in the fake cookie jar
     */
    private ?string $cookieValue = null;

    /**
     * @var string
     */
    private string $cryptKey = '';

    /**
     * Rows written, keyed by order id (emulates the primary key + INSERT IGNORE)
     *
     * @var array<int, array<string, mixed>>
     */
    private array $stored = [];

    /**
     * @var int
     */
    private int $insertCalls = 0;

    /**
     * @var array<int, \Throwable> order id => error thrown by the adapter for that order
     */
    private array $queryErrors = [];

    /**
     * @var \Throwable|null error thrown by ResourceConnection::getConnection()
     */
    private ?\Throwable $connectionError = null;

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

    /**
     * @var int
     */
    private int $cookieReads = 0;

    protected function setUp(): void
    {
        // built at runtime, not a real key
        $this->cryptKey = 'aivtest' . str_repeat('d3', 13);
    }

    // ------------------------------------------------------------------ helpers

    private function realCookie(int $windowDays, ?string $cryptKey = null): AttributionCookie
    {
        $key = $cryptKey ?? $this->cryptKey;
        $cookieManager = $this->createStub(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnCallback(
            function ($name, $default = null) {
                $this->cookieReads++;
                return $name === AttributionCookieInterface::COOKIE_NAME && $this->cookieValue !== null
                    ? $this->cookieValue
                    : $default;
            }
        );
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(
            static fn ($path, $default = null) => $path === 'crypt/key' ? $key : $default
        );
        $config = $this->createStub(Config::class);
        $config->method('attributionWindowDays')->willReturn($windowDays);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(static fn () => time());

        return new AttributionCookie(
            $cookieManager,
            $this->createStub(CookieMetadataFactory::class),
            $deployment,
            $config,
            $this->createStub(ConsentChecker::class),
            $this->createStub(RequestInterface::class),
            $dateTime,
            $this->createStub(LoggerInterface::class)
        );
    }

    private function observer(AttributionCookieInterface $cookie, int $windowDays = 30, string $area = 'frontend'): AttributeOrder
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($name) => '`' . $name . '`');
        $connection->method('query')->willReturnCallback(function ($sql, $bind = []) {
            $this->insertCalls++;
            $orderId = (int)$bind[0];
            if (isset($this->queryErrors[$orderId])) {
                throw $this->queryErrors[$orderId];
            }
            $this->assertStringStartsWith('INSERT IGNORE INTO `pfx_panth_aiv_order`', $sql);
            $columns = [
                'order_id', 'channel', 'first_source', 'first_at', 'last_source', 'last_at',
                'landing_path', 'created_at', 'is_simulated',
            ];
            if (!isset($this->stored[$orderId])) {
                $this->stored[$orderId] = array_combine($columns, $bind);
            }
            return $this->createStub(\Zend_Db_Statement_Interface::class);
        });

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturnCallback(function () use ($connection) {
            if ($this->connectionError !== null) {
                throw $this->connectionError;
            }
            return $connection;
        });
        $resource->method('getTableName')->willReturnCallback(static fn (string $name) => 'pfx_' . $name);

        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn($area);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('attributionWindowDays')->willReturn($windowDays);

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });

        return new AttributeOrder($cookie, $resource, $state, $config, $logger);
    }

    /**
     * Fire the observer the way Magento\Framework\Event\Manager::dispatch() does
     */
    private function fire(AttributeOrder $observer, string $eventName, array $data): void
    {
        $event = new Event($data);
        $event->setName($eventName);
        $observer->execute(new Observer(array_merge(['event' => $event], $data)));
    }

    private function order(?int $id, int $storeId = 1): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getEntityId')->willReturn($id);
        $order->method('getStoreId')->willReturn($storeId);
        return $order;
    }

    private function cookieFor(string $kind, AttributionCookie $signer): ?string
    {
        $now = time();
        $payload = ['f' => 'chatgpt', 'ft' => $now - 7200, 'l' => 'perplexity', 'lt' => $now - 600, 'lp' => '/aivtest-d3-lp'];
        $valid = $signer->sign($payload);
        [$encoded, $signature] = explode('.', $valid);
        switch ($kind) {
            case 'none':
                return null;
            case 'valid':
                return $valid;
            case 'tampered_sig':
                return $encoded . '.' . strrev($signature);
            case 'tampered_payload':
                $forged = rtrim(strtr(base64_encode((string)json_encode(['l' => 'claude'] + $payload)), '+/', '-_'), '=');
                return $forged . '.' . $signature;
            case 'foreign_key':
                return $this->realCookie(30, 'aivtest-other-deployment-key')->sign($payload);
            case 'malformed':
                return '%%%.@@@';
            case 'old2d':
                return $signer->sign(['ft' => $now - 3 * 86400, 'lt' => $now - 2 * 86400] + $payload);
            case 'old31d':
                return $signer->sign(['ft' => $now - 40 * 86400, 'lt' => $now - 31 * 86400] + $payload);
        }
        $this->fail('unknown cookie kind ' . $kind);
    }

    private function attributionStub(?Attribution $attribution): AttributionCookieInterface
    {
        $cookie = $this->createStub(AttributionCookieInterface::class);
        $cookie->method('read')->willReturnCallback(function () use ($attribution) {
            $this->cookieReads++;
            return $attribution;
        });
        return $cookie;
    }

    // ------------------------------------------------------------------ TC3.01-3.03 (real cookie service)

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function cookieCases(): array
    {
        return [
            'valid signed cookie' => ['valid', 30, 1],
            'no cookie' => ['none', 30, 0],
            'tampered signature' => ['tampered_sig', 30, 0],
            'tampered payload, original signature' => ['tampered_payload', 30, 0],
            'signed with another deployment key' => ['foreign_key', 30, 0],
            'malformed value' => ['malformed', 30, 0],
            'last touch 2 days old, window 1 day' => ['old2d', 1, 0],
            'last touch 2 days old, window 30 days' => ['old2d', 30, 1],
            'last touch 31 days old, default window' => ['old31d', 30, 0],
        ];
    }

    /**
     * @dataProvider cookieCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cookieCases')]
    public function testOnlyAValidSignedCookieInsideTheWindowAttributesTheOrder(string $kind, int $window, int $rows): void
    {
        $cookie = $this->realCookie($window);
        $this->cookieValue = $this->cookieFor($kind, $cookie);

        $observer = $this->observer($cookie, $window);
        $order = $this->order(304);
        $this->fire($observer, self::EVENT_SUCCESS, ['order' => $order, 'quote' => new \stdClass()]);
        $this->fire($observer, self::EVENT_ALL_AFTER, ['order' => $order, 'quote' => new \stdClass()]);

        $this->assertCount($rows, $this->stored);
        $this->assertSame([], $this->warnings);
        if ($rows === 1) {
            $row = $this->stored[304];
            $this->assertSame('referral', $row['channel']);
            $this->assertSame('chatgpt', $row['first_source']);
            $this->assertSame('perplexity', $row['last_source']);
            $this->assertSame('/aivtest-d3-lp', $row['landing_path']);
            $this->assertSame(0, $row['is_simulated']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['first_at']);
            $this->assertLessThanOrEqual($row['last_at'], $row['first_at']);
            $this->assertSame(1, $this->insertCalls, 'the one-page checkout must be written once');
        }
    }

    public function testAdminAreaNeverReadsTheShopperCookieEvenWhenItIsValid(): void
    {
        $cookie = $this->realCookie(30);
        $this->cookieValue = $this->cookieFor('valid', $cookie);
        $observer = $this->observer($cookie, 30, 'adminhtml');
        $this->fire($observer, self::EVENT_SUCCESS, ['order' => $this->order(42), 'quote' => new \stdClass()]);
        $this->assertSame([], $this->stored);
        $this->assertSame(0, $this->cookieReads);
    }

    /**
     * Fixed D3-1: a first touch older than the window no longer keeps the credit when later touches keep
     * the cookie alive; the last touch is credited as first touch too.
     */
    public function testFirstTouchOlderThanTheWindowFallsBackToTheLastTouch(): void
    {
        $cookie = $this->realCookie(30);
        $now = time();
        $this->cookieValue = $cookie->sign(
            ['f' => 'chatgpt', 'ft' => $now - 40 * 86400, 'l' => 'perplexity', 'lt' => $now - 86400, 'lp' => '/x']
        );
        $this->fire($this->observer($cookie, 30), self::EVENT_SUCCESS, ['order' => $this->order(9)]);
        $this->assertSame('perplexity', $this->stored[9]['first_source']);
        $this->assertSame(gmdate('Y-m-d', $now - 86400), substr((string)$this->stored[9]['first_at'], 0, 10));
    }

    public function testTwoQuickOrdersWithTheSameCookieAreBothAttributed(): void
    {
        $cookie = $this->realCookie(30);
        $this->cookieValue = $this->cookieFor('valid', $cookie);
        $observer = $this->observer($cookie);
        $this->fire($observer, self::EVENT_SUCCESS, ['order' => $this->order(701)]);
        $this->fire($observer, self::EVENT_SUCCESS, ['order' => $this->order(702, 2)]);
        $this->assertSame([701, 702], array_keys($this->stored));
    }

    // ------------------------------------------------------------------ TC3.06 failures never escape

    /**
     * @return array<string, array{0: class-string<\Throwable>, 1: string}>
     */
    public static function writerFailures(): array
    {
        return [
            'server gone away' => [\PDOException::class, 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
            'missing table' => [\PDOException::class, "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'x.panth_aiv_order'"],
            'lock wait timeout' => [\RuntimeException::class, 'SQLSTATE[HY000]: 1205 Lock wait timeout exceeded'],
            'type error' => [\TypeError::class, 'Argument #1 must be of type string'],
            'engine error' => [\Error::class, 'Call to a member function query() on null'],
        ];
    }

    /**
     * @dataProvider writerFailures
     * @param class-string<\Throwable> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('writerFailures')]
    public function testAThrowingWriterNeverBreaksOrderPlacement(string $class, string $message): void
    {
        $this->queryErrors[304] = new $class($message);
        $observer = $this->observer($this->attributionStub(new Attribution('chatgpt', time() - 60, 'chatgpt', time() - 60, '/p')));
        $this->fire($observer, self::EVENT_SUCCESS, ['order' => $this->order(304), 'quote' => new \stdClass()]);
        $this->assertSame([], $this->stored);
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('304', $this->warnings[0]);
    }

    public function testConnectionAndOrderAccessorFailuresNeverEscape(): void
    {
        $this->connectionError = new \DomainException('connection refused');
        $observer = $this->observer($this->attributionStub(new Attribution('chatgpt', time() - 60, 'chatgpt', time() - 60, '/p')));
        $this->fire($observer, self::EVENT_SUCCESS, ['order' => $this->order(1)]);

        $broken = $this->createStub(OrderInterface::class);
        $broken->method('getEntityId')->willThrowException(new \LogicException('order not loaded'));
        $this->fire($observer, self::EVENT_SUCCESS, ['order' => $broken]);

        $cookie = $this->createStub(AttributionCookieInterface::class);
        $cookie->method('read')->willThrowException(new \RuntimeException('proxy could not be created'));
        $this->fire($this->observer($cookie), self::EVENT_SUCCESS, ['order' => $this->order(2)]);

        $this->assertSame([], $this->stored);
        $this->assertCount(3, $this->warnings);
    }

    // ------------------------------------------------------------------ TC3.07 multishipping

    public function testMultishippingPayloadAttributesEveryPlacedOrderOnceWithOneCookieRead(): void
    {
        $observer = $this->observer($this->attributionStub(
            new Attribution('chatgpt', time() - 3600, 'claude', time() - 60, '/aivtest-d3-ms')
        ));
        // Multishipping::createOrders() (2.4.7-p3 and 2.4.9): ['orders' => $orders, 'quote' => $quote];
        // $orders also holds orders whose placement failed (never saved -> no entity id)
        $payload = [
            'orders' => [$this->order(501), $this->order(null), $this->order(502), new \stdClass()],
            'quote' => new \stdClass(),
        ];
        $this->fire($observer, self::EVENT_ALL_AFTER, $payload);
        // a repeated dispatch (e.g. retried request) must not add or change rows
        $this->fire($observer, self::EVENT_ALL_AFTER, $payload);

        $this->assertSame([501, 502], array_keys($this->stored));
        $this->assertSame('chatgpt', $this->stored[501]['first_source']);
        $this->assertSame('claude', $this->stored[502]['last_source']);
        $this->assertSame(2, $this->cookieReads, 'one cookie read per dispatch, not per order');
        $this->assertSame([], $this->warnings);
    }

    public function testMultishippingFailedOrderWithRolledBackIdDoesNotStopTheOthers(): void
    {
        // a failed order can keep the id of its rolled-back INSERT; a strict-mode FK error must
        // only skip that order
        $this->queryErrors[601] = new \PDOException(
            'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row'
        );
        $observer = $this->observer($this->attributionStub(
            new Attribution('gemini', time() - 60, 'gemini', time() - 60, '/p')
        ));
        $this->fire($observer, self::EVENT_ALL_AFTER, ['orders' => [$this->order(601), $this->order(602)]]);
        $this->assertSame([602], array_keys($this->stored));
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('601', $this->warnings[0]);
    }

    public function testOnePageAllAfterAloneNeverWritesAndPaypalStylePayloadIsIgnored(): void
    {
        $observer = $this->observer($this->attributionStub(
            new Attribution('chatgpt', time() - 60, 'chatgpt', time() - 60, '/p')
        ));
        // QuoteManagement::submit / PayPal Express PlaceOrder: checkout_submit_all_after with a single "order"
        $this->fire($observer, self::EVENT_ALL_AFTER, ['order' => $this->order(77), 'quote' => new \stdClass()]);
        $this->assertSame([], $this->stored);
        $this->assertSame(0, $this->cookieReads);
    }

    // ------------------------------------------------------------------ TC3.04 grid plugin

    /**
     * @param array<string, array> $from
     * @param array<int, array> $joins
     * @param array<string, string> $map
     */
    private function gridCollection(array &$from, array &$joins, array &$map): AbstractDb
    {
        $select = $this->createStub(Select::class);
        $select->method('getPart')->willReturnCallback(static function ($part) use (&$from) {
            return $part === Select::FROM ? $from : [];
        });
        $select->method('joinLeft')->willReturnCallback(
            static function ($name, $cond, $cols = '*') use ($select, &$from, &$joins) {
                $alias = (string)array_key_first((array)$name);
                $from[$alias] = ['joinType' => 'left join', 'joinCondition' => $cond];
                $joins[] = [$name, $cond, $cols];
                return $select;
            }
        );
        $collection = $this->createStub(AbstractDb::class);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('addFilterToMap')->willReturnCallback(
            static function ($filter, $alias) use ($collection, &$map) {
                $map[$filter] = $alias;
                return $collection;
            }
        );
        return $collection;
    }

    private function gridPlugin(string $model): OrderGridCollectionPlugin
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name) => $name);
        $connection = $this->createStub(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $resource->method('getConnection')->willReturn($connection);
        $config = $this->createStub(Config::class);
        $config->method('attributionModel')->willReturn($model);
        return new OrderGridCollectionPlugin($resource, $config, $this->createStub(LoggerInterface::class));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function models(): array
    {
        return ['first touch' => ['first', 'first_source'], 'last touch' => ['last', 'last_source']];
    }

    /**
     * @dataProvider models
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function testGridJoinCoexistsWithMagePosJoinsAndIsAddedOnlyOnce(string $model, string $column): void
    {
        // what Panth_MagePos' SalesOrderGridCollectionPlugin leaves on the select before us
        $from = [
            'main_table' => ['tableName' => 'sales_order_grid'],
            'ppo' => ['tableName' => 'panth_pos_order'],
            'ppr' => ['tableName' => 'panth_pos_register'],
            'ppu' => ['tableName' => 'panth_pos_user'],
        ];
        $joins = [];
        $map = ['pos_register' => 'ppr.name', 'pos_cashier' => 'ppu.name'];
        $collection = $this->gridCollection($from, $joins, $map);
        $plugin = $this->gridPlugin($model);
        $factory = $this->createStub(CollectionFactory::class);

        $this->assertSame($collection, $plugin->afterGetReport($factory, $collection, 'sales_order_grid_data_source'));
        $plugin->afterGetReport($factory, $collection, 'sales_order_grid_data_source');

        $this->assertCount(1, $joins);
        [$name, $cond, $cols] = $joins[0];
        $this->assertSame(['panth_aiv_ord' => 'panth_aiv_order'], $name);
        $this->assertStringContainsString('panth_aiv_ord.order_id = main_table.entity_id', $cond);
        $this->assertStringContainsString('panth_aiv_ord.is_simulated = 0', $cond);
        $this->assertSame(['ai_source' => 'panth_aiv_ord.' . $column], $cols);
        $this->assertSame('panth_aiv_ord.' . $column, $map['ai_source']);
        $this->assertSame('ppr.name', $map['pos_register'], 'other modules\' filter maps stay intact');
        $this->assertSame(['main_table', 'ppo', 'ppr', 'ppu', 'panth_aiv_ord'], array_keys($from));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function otherDataSources(): array
    {
        return [
            'invoice grid' => ['sales_order_invoice_grid_data_source'],
            'customer grid' => ['customer_listing_data_source'],
            'mage-pos register grid' => ['panth_pos_register_listing_data_source'],
            'empty' => [''],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider otherDataSources
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('otherDataSources')]
    public function testOtherGridDataSourcesAreLeftUntouched($requestName): void
    {
        $from = ['main_table' => ['tableName' => 't']];
        $joins = [];
        $map = [];
        $collection = $this->gridCollection($from, $joins, $map);
        $result = $this->gridPlugin('first')->afterGetReport(
            $this->createStub(CollectionFactory::class),
            $collection,
            $requestName
        );
        $this->assertSame($collection, $result);
        $this->assertSame([], $joins);
        $this->assertSame([], $map);
    }

    // ------------------------------------------------------------------ AI Source grid column

    public function testGridCellsShowDisplayNamesResolvedOncePerCode(): void
    {
        $calls = [];
        $classifier = $this->createStub(SourceClassifierInterface::class);
        $classifier->method('nameFor')->willReturnCallback(static function (string $code) use (&$calls) {
            $calls[] = $code;
            if ($code === 'disc_removed') {
                throw new \RuntimeException('rule gone');
            }
            return ['chatgpt' => 'ChatGPT', 'claude' => 'Claude', 'blank' => '  '][$code] ?? $code;
        });
        $column = new AiSource(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $classifier,
            [],
            ['name' => 'ai_source']
        );
        $data = ['data' => ['items' => [
            ['entity_id' => 1, 'ai_source' => 'chatgpt'],
            ['entity_id' => 2, 'ai_source' => 'chatgpt'],
            ['entity_id' => 3, 'ai_source' => null],
            ['entity_id' => 4],
            ['entity_id' => 5, 'ai_source' => ['x']],
            ['entity_id' => 6, 'ai_source' => ' claude '],
            ['entity_id' => 7, 'ai_source' => 'disc_removed'],
            ['entity_id' => 8, 'ai_source' => 'blank'],
            ['entity_id' => 9, 'ai_source' => '<b>x</b>'],
        ]]];
        $items = $column->prepareDataSource($data)['data']['items'];
        $this->assertSame(
            ['ChatGPT', 'ChatGPT', '', '', '', 'Claude', 'disc_removed', 'blank', '<b>x</b>'],
            array_column($items, 'ai_source')
        );
        $this->assertSame(['chatgpt', 'claude', 'disc_removed', 'blank', '<b>x</b>'], $calls);
    }
}
