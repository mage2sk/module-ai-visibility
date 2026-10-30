<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Block\Adminhtml\Order\View\Attribution;
use Panth\AiVisibility\Model\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AttributionTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../../../view/adminhtml/templates/order/view/attribution.phtml';

    /**
     * @var array<string, mixed>
     */
    private array $params = ['order_id' => '42'];

    /**
     * @var array|false
     */
    private $row = false;

    /**
     * @var int
     */
    private int $fetches = 0;

    /**
     * @var array<int, array>
     */
    private array $wheres = [];

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $dbError = null;

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn (string $class) => $this->createStub($class));
        ObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ObjectManager::class, '_instance'))->setValue(null, null);
    }

    private function escaper(): Escaper
    {
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn ($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
        return $escaper;
    }

    private function block(string $model = 'first'): Attribution
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            fn ($key, $default = null) => $this->params[$key] ?? $default
        );

        $localeDate = $this->createStub(TimezoneInterface::class);
        $localeDate->method('formatDateTime')->willReturnCallback(
            static fn ($date) => 'L:' . $date->format('Y-m-d H:i') . ' ' . $date->getTimezone()->getName()
        );

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEscaper')->willReturn($this->escaper());
        $context->method('getLocaleDate')->willReturn($localeDate);
        $context->method('getLogger')->willReturn($logger);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->wheres[] = [$cond, $value];
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturnCallback(function () {
            $this->fetches++;
            if ($this->dbError !== null) {
                throw $this->dbError;
            }
            return $this->row;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name) => $name);

        $classifier = $this->createStub(SourceClassifierInterface::class);
        $classifier->method('nameFor')->willReturnCallback(static fn (string $code): string => [
            'chatgpt' => 'ChatGPT',
            'perplexity' => 'Perplexity',
            'evil' => '<script>alert(1)</script>',
        ][$code] ?? $code);

        $config = $this->createStub(Config::class);
        $config->method('attributionModel')->willReturn($model);

        return new Attribution($context, $resource, $classifier, $config);
    }

    private function sampleRow(array $overrides = []): array
    {
        return array_merge([
            'order_id' => '42',
            'channel' => 'referral',
            'first_source' => 'chatgpt',
            'first_at' => '2026-01-01 10:00:00',
            'last_source' => 'perplexity',
            'last_at' => '2026-01-03 12:30:00',
            'landing_path' => '/gear/tent.html',
            'probability' => null,
            'created_at' => '2026-01-03 13:00:00',
            'is_simulated' => '0',
        ], $overrides);
    }

    private function render(Attribution $block): string
    {
        $escaper = $this->escaper();
        ob_start();
        try {
            (static function (Attribution $block, Escaper $escaper, string $file): void {
                include $file;
            })($block, $escaper, self::TEMPLATE);
        } finally {
            $html = (string)ob_get_clean();
        }
        return $html;
    }

    public function testRowIsLoadedOnceForTheRequestedOrder(): void
    {
        $this->row = $this->sampleRow();
        $block = $this->block();
        $this->assertSame('chatgpt', $block->getAttribution()['first_source']);
        $block->getAttribution();
        $this->assertSame(1, $this->fetches);
        $this->assertSame([['order_id = ?', 42]], $this->wheres);
    }

    public function testMissingOrderIdOrRowGivesNull(): void
    {
        $this->params = [];
        $this->assertNull($this->block()->getAttribution());
        $this->assertSame(0, $this->fetches);

        $this->params = ['order_id' => '42'];
        $this->row = false;
        $this->assertNull($this->block()->getAttribution());
    }

    public function testDatabaseErrorIsLoggedNotThrown(): void
    {
        $this->dbError = new \RuntimeException('table missing');
        $this->assertNull($this->block()->getAttribution());
        $this->assertCount(1, $this->warnings);
    }

    public function testNoAttributionRendersNothing(): void
    {
        $block = $this->block();
        $this->assertSame('', trim($this->render($block)));
        $method = new \ReflectionMethod(Attribution::class, '_toHtml');
        $method->setAccessible(true);
        $this->assertSame('', $method->invoke($block));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function modelProvider(): array
    {
        return [
            'first touch' => ['first', 'ChatGPT', 'first touch'],
            'last touch' => ['last', 'Perplexity', 'last touch'],
        ];
    }

    /**
     * @dataProvider modelProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelProvider')]
    public function testCreditedSourceFollowsTheModel(string $model, string $name, string $label): void
    {
        $this->row = $this->sampleRow();
        $block = $this->block($model);
        $html = $this->render($block);
        $this->assertSame($model === 'last', $block->isLastTouchModel());
        $this->assertStringContainsString('<strong>' . $name . '</strong>', $html);
        $this->assertStringContainsString('(' . $label . ')', $html);
        $this->assertStringContainsString('AI Attribution', $html);
        $this->assertStringContainsString('L:2026-01-01 10:00 UTC', $html);
        $this->assertStringContainsString('<code>/gear/tent.html</code>', $html);
        $this->assertStringContainsString('AI assistant referral', $html);
        $this->assertStringNotContainsString('traffic simulator', $html);
    }

    public function testEverythingIsEscaped(): void
    {
        $this->row = $this->sampleRow([
            'first_source' => 'evil',
            'last_source' => '"><img src=x onerror=alert(2)>',
            'landing_path' => '/<b>x</b>?a="1"',
            'channel' => '<i>odd</i>',
        ]);
        $html = $this->render($this->block('first'));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringNotContainsString('<i>odd</i>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(2)&gt;', $html);
        $this->assertStringContainsString('/&lt;b&gt;x&lt;/b&gt;?a=&quot;1&quot;', $html);
    }

    public function testSimulatedRowShowsANotice(): void
    {
        $this->row = $this->sampleRow(['is_simulated' => '1', 'landing_path' => null]);
        $html = $this->render($this->block());
        $this->assertStringContainsString('traffic simulator', $html);
        $this->assertStringNotContainsString('Landing Page', $html);
    }

    public function testHelpers(): void
    {
        $block = $this->block();
        $this->assertSame('', $block->getSourceName(null));
        $this->assertSame('unknown_code', $block->getSourceName('unknown_code'));
        $this->assertSame('', $block->formatUtc(null));
        $this->assertSame('', $block->formatUtc('0000-00-00 00:00:00'));
        $this->assertSame('not a date', $block->formatUtc('not a date'));
        $this->assertSame('Agent checkout', $block->getChannelLabel('agent_checkout'));
        $this->assertSame('custom', $block->getChannelLabel('custom'));
        $this->assertSame('', $block->getCreditedSource());
    }
}
