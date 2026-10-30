<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Email;

use Magento\Backend\Model\Url as BackendUrl;
use Magento\Backend\Model\UrlFactory as BackendUrlFactory;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Email\WeeklySendMarker;
use Panth\AiVisibility\Service\Email\WeeklySummaryBuilder;
use Panth\AiVisibility\Service\Email\WeeklySummaryFormatter;
use Panth\AiVisibility\Service\Email\WeeklySummarySender;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Shared mocks get expectations only in the tests that need them.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class WeeklySummarySenderTest extends TestCase
{
    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var WeeklySendMarker&MockObject
     */
    private $marker;

    /**
     * @var TransportBuilder&MockObject
     */
    private $transportBuilder;

    /**
     * @var TransportInterface&MockObject
     */
    private $transport;

    /**
     * @var Emulation&MockObject
     */
    private $emulation;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * @var string[]
     */
    private array $addedTo = [];

    protected function setUp(): void
    {
        $this->addedTo = [];
        $this->config = $this->createMock(Config::class);
        $this->config->method('emailWeekday')->willReturn(1);
        $this->config->method('emailHour')->willReturn(7);
        $this->config->method('emailSender')->willReturn('sales');
        $this->marker = $this->createMock(WeeklySendMarker::class);
        $this->transport = $this->createMock(TransportInterface::class);
        $this->transportBuilder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope'] as $method) {
            $this->transportBuilder->method($method)->willReturnSelf();
        }
        $this->transportBuilder->method('addTo')->willReturnCallback(function ($address) {
            $this->addedTo[] = $address;
            return $this->transportBuilder;
        });
        $this->transportBuilder->method('getTransport')->willReturn($this->transport);
        $this->emulation = $this->createMock(Emulation::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @dataProvider dueCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('dueCases')]
    public function testIsDue(string $now, int $weekday, int $hour, ?int $lastWeek, bool $expected): void
    {
        $this->assertSame($expected, WeeklySummarySender::isDue(self::ts($now), $weekday, $hour, $lastWeek));
    }

    /**
     * @return array<string, array{string, int, int, ?int, bool}>
     */
    public static function dueCases(): array
    {
        // 2026-10-26 is a Monday (ISO week 202644).
        return [
            'monday before the hour' => ['2026-10-26 06:59:59', 1, 7, null, false],
            'monday at the hour' => ['2026-10-26 07:00:00', 1, 7, null, true],
            'monday later run' => ['2026-10-26 07:05:00', 1, 7, 202643, true],
            'catch-up next day' => ['2026-10-27 03:05:00', 1, 7, 202643, true],
            'catch-up sunday night' => ['2026-11-01 23:05:00', 1, 7, 202643, true],
            'already sent this week' => ['2026-10-28 09:05:00', 1, 7, 202644, false],
            'already sent, same day' => ['2026-10-26 08:05:00', 1, 7, 202644, false],
            'sunday schedule, saturday' => ['2026-10-31 23:05:00', 7, 7, null, false],
            'sunday schedule, sunday' => ['2026-11-01 07:05:00', 7, 7, null, true],
            'midnight hour' => ['2026-10-28 00:05:00', 3, 0, null, true],
            'wednesday schedule, tuesday' => ['2026-10-27 23:59:00', 3, 0, null, false],
            'iso new year: same week as 31 dec' => ['2027-01-02 10:00:00', 1, 7, 202653, false],
            'iso new year: next monday' => ['2027-01-04 07:00:00', 1, 7, 202653, true],
            'out of range values are clamped' => ['2026-11-01 23:05:00', 9, 30, null, true],
        ];
    }

    /**
     * @dataProvider weekKeys
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('weekKeys')]
    public function testWeekKeyUsesIsoYear(string $now, int $expected): void
    {
        $this->assertSame($expected, WeeklySummarySender::weekKey(self::ts($now)));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function weekKeys(): array
    {
        return [
            'monday of week 44' => ['2026-10-26 00:00:00', 202644],
            'sunday end of week 44' => ['2026-11-01 23:59:59', 202644],
            'next monday' => ['2026-11-02 00:00:00', 202645],
            '31 dec 2026 is week 53' => ['2026-12-31 12:00:00', 202653],
            '3 jan 2027 still week 53 of 2026' => ['2027-01-03 23:59:59', 202653],
            '30 dec 2024 is week 1 of 2025' => ['2024-12-30 08:00:00', 202501],
        ];
    }

    public function testDisabledDoesNothing(): void
    {
        $this->config->method('emailEnabled')->willReturn(false);
        $this->marker->expects($this->never())->method('lastWeek');
        $this->marker->expects($this->never())->method('claim');
        $this->transport->expects($this->never())->method('sendMessage');

        $this->assertFalse($this->sender()->sendIfDue(self::ts('2026-10-26 07:05:00')));
    }

    public function testNotDueOnlyTouchesTheMarker(): void
    {
        $this->config->method('emailEnabled')->willReturn(true);
        $this->marker->method('lastWeek')->willReturn(202643);
        $this->marker->expects($this->once())->method('touch')->with('2026-10-26');
        $this->marker->expects($this->never())->method('claim');
        $this->transport->expects($this->never())->method('sendMessage');

        $this->assertFalse($this->sender()->sendIfDue(self::ts('2026-10-26 06:05:00')));
    }

    public function testNoRecipientsLogsAndDoesNotClaim(): void
    {
        $this->config->method('emailEnabled')->willReturn(true);
        $this->config->method('emailRecipients')->willReturn([]);
        $this->marker->method('lastWeek')->willReturn(null);
        $this->marker->expects($this->never())->method('claim');
        $this->transport->expects($this->never())->method('sendMessage');
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('no valid recipient'));

        $this->assertFalse($this->sender()->sendIfDue(self::ts('2026-10-26 07:05:00')));
    }

    public function testDueSendsOnceWithStoreSenderIdentity(): void
    {
        $this->config->method('emailEnabled')->willReturn(true);
        $this->config->method('emailRecipients')->willReturn(['ops@shop.test', 'owner@shop.test']);
        $this->marker->method('lastWeek')->willReturn(202643);
        $this->marker->expects($this->once())->method('claim')->with(202644, '2026-10-27')->willReturn(202643);
        $this->marker->expects($this->never())->method('release');
        $this->transportBuilder->expects($this->once())->method('setFromByScope')->with('sales', 3)->willReturnSelf();
        $this->transportBuilder->expects($this->once())->method('setTemplateIdentifier')
            ->with(Config::EMAIL_TEMPLATE_WEEKLY_SUMMARY)->willReturnSelf();
        $this->transportBuilder->expects($this->once())->method('setTemplateVars')
            ->with($this->callback(static function (array $vars): bool {
                return isset($vars['subject'], $vars['metrics_html'], $vars['dashboard_link_html'])
                    && str_contains($vars['dashboard_link_html'], 'https://shop.test/admin/panth_aivisibility/');
            }))->willReturnSelf();
        $this->transport->expects($this->once())->method('sendMessage');
        $this->emulation->expects($this->once())->method('startEnvironmentEmulation')->with(3, 'frontend', true);
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');

        $this->assertTrue($this->sender()->sendIfDue(self::ts('2026-10-27 02:05:00')));
        $this->assertSame(['ops@shop.test', 'owner@shop.test'], $this->addedTo);
    }

    public function testConcurrentRunThatLostTheClaimDoesNotSend(): void
    {
        $this->config->method('emailEnabled')->willReturn(true);
        $this->config->method('emailRecipients')->willReturn(['ops@shop.test']);
        $this->marker->method('lastWeek')->willReturn(null);
        $this->marker->method('claim')->willReturn(null);
        $this->transport->expects($this->never())->method('sendMessage');

        $this->assertFalse($this->sender()->sendIfDue(self::ts('2026-10-26 07:05:00')));
    }

    public function testFailedSendReleasesTheWeekAndNeverThrows(): void
    {
        $this->config->method('emailEnabled')->willReturn(true);
        $this->config->method('emailRecipients')->willReturn(['ops@shop.test']);
        $this->marker->method('lastWeek')->willReturn(202643);
        $this->marker->method('claim')->willReturn(202643);
        $this->marker->expects($this->once())->method('release')->with(202644, 202643);
        $this->transport->method('sendMessage')->willThrowException(new \RuntimeException('smtp down'));
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->assertFalse($this->sender()->sendIfDue(self::ts('2026-10-26 07:05:00')));
    }

    public function testEmulationFailureReleasesTheWeek(): void
    {
        $this->config->method('emailEnabled')->willReturn(true);
        $this->config->method('emailRecipients')->willReturn(['ops@shop.test']);
        $this->marker->method('lastWeek')->willReturn(null);
        $this->marker->method('claim')->willReturn(0);
        $this->marker->expects($this->once())->method('release')->with(202644, 0);
        $this->emulation->method('startEnvironmentEmulation')->willThrowException(new \RuntimeException('no theme'));
        $this->emulation->expects($this->never())->method('stopEnvironmentEmulation');
        $this->transport->expects($this->never())->method('sendMessage');

        $this->assertFalse($this->sender()->sendIfDue(self::ts('2026-10-26 07:05:00')));
    }

    public function testDatabaseErrorNeverEscapes(): void
    {
        $this->config->method('emailEnabled')->willReturn(true);
        $this->marker->method('lastWeek')->willThrowException(new \RuntimeException('db gone'));
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('db gone'));

        $this->assertFalse($this->sender()->sendIfDue(self::ts('2026-10-26 07:05:00')));
    }

    /**
     * @dataProvider invalidTo
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTo')]
    public function testSendNowRejectsInvalidRecipients(string $to, string $message): void
    {
        $this->transport->expects($this->never())->method('sendMessage');

        $result = $this->sender()->sendNow($to);

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString($message, $result['message']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidTo(): array
    {
        return [
            'not an address' => ['not-an-address', 'Invalid address(es): not-an-address'],
            'one bad among good' => ['ok@shop.test, bad@', 'Invalid address(es): bad@'],
            'header injection' => ["a@shop.test\r\nBcc: x@shop.test", 'Invalid address(es)'],
            'empty' => [' , ', 'at least one address'],
        ];
    }

    public function testSendNowToTestAddressWorksWhileDisabledAndDoesNotMarkTheWeek(): void
    {
        $this->config->method('emailEnabled')->willReturn(false);
        $this->marker->expects($this->never())->method('claim');
        $this->transport->expects($this->once())->method('sendMessage');

        $result = $this->sender()->sendNow('aivtest@example.invalid', self::ts('2026-10-26 07:05:00'));

        $this->assertTrue($result['sent']);
        $this->assertSame(['aivtest@example.invalid'], $this->addedTo);
    }

    public function testSendNowWithoutToNeedsTheSwitch(): void
    {
        $this->config->method('emailEnabled')->willReturn(false);
        $this->transport->expects($this->never())->method('sendMessage');

        $result = $this->sender()->sendNow();

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('switched off', $result['message']);
    }

    public function testSendNowToConfiguredRecipientsMarksTheWeek(): void
    {
        $this->config->method('emailEnabled')->willReturn(true);
        $this->config->method('emailRecipients')->willReturn(['ops@shop.test']);
        $this->marker->expects($this->once())->method('claim')->with(202644, '2026-10-28');

        $result = $this->sender()->sendNow(null, self::ts('2026-10-28 10:00:00'));

        $this->assertTrue($result['sent']);
    }

    public function testSendNowReportsTransportError(): void
    {
        $this->transport->method('sendMessage')->willThrowException(new \RuntimeException('smtp refused'));

        $result = $this->sender()->sendNow('ops@shop.test');

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('smtp refused', $result['message']);
    }

    public function testPreviewReturnsSubjectAndText(): void
    {
        $this->config->method('emailRecipients')->willReturn(['ops@shop.test']);
        $this->transport->expects($this->never())->method('sendMessage');

        $preview = $this->sender()->preview(self::ts('2026-10-26 07:05:00'));

        $this->assertSame('AI Visibility weekly summary for Demo Shop: 2026-10-19 to 2026-10-25', $preview['subject']);
        $this->assertStringContainsString('AI crawler hits:', $preview['text']);
        $this->assertSame(['ops@shop.test'], $preview['recipients']);
    }

    /**
     * Sender with a stub builder (empty week) and a default store view with ID 3
     *
     * @return WeeklySummarySender
     */
    private function sender(): WeeklySummarySender
    {
        $builder = $this->createStub(WeeklySummaryBuilder::class);
        $builder->method('build')->willReturnCallback(static function (int $now): array {
            return WeeklySummaryBuilder::periods($now) + [
                'metrics' => [],
                'money' => ['current' => '0.00', 'previous' => '0.00'],
                'top_pages' => [],
                'top_sources' => [],
                'recommendations' => [],
                'verdicts' => [],
                'candidates' => ['count' => 0, 'items' => []],
            ];
        });
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $store->method('getName')->willReturn('Demo Shop');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);
        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturn('https://shop.test/admin/panth_aivisibility/dashboard/index/');
        $urlFactory = $this->createStub(BackendUrlFactory::class);
        $urlFactory->method('create')->willReturn($url);

        return new WeeklySummarySender(
            $this->config,
            $builder,
            new WeeklySummaryFormatter(new Escaper()),
            $this->marker,
            $this->transportBuilder,
            $storeManager,
            $this->emulation,
            $urlFactory,
            $this->logger
        );
    }

    /**
     * Unix time of a UTC date-time
     *
     * @param string $dateTime
     * @return int
     */
    private static function ts(string $dateTime): int
    {
        return (new \DateTimeImmutable($dateTime, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
