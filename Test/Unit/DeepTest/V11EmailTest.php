<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Backend\Model\Url as BackendUrl;
use Magento\Backend\Model\UrlFactory as BackendUrlFactory;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Email\WeeklySendMarker;
use Panth\AiVisibility\Service\Email\WeeklySummaryBuilder;
use Panth\AiVisibility\Service\Email\WeeklySummaryFormatter;
use Panth\AiVisibility\Service\Email\WeeklySummarySender;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep test E4 (1.1 weekly email): schedule, ISO week / year boundary, claim + release, recipients, escaping.
 *
 * Live findings this file mirrors: 8 parallel claims -> exactly 1 winner; 6 parallel cron runs -> exactly 1 email;
 * a transport failure releases the claim and the next run sends (catch-up). testTouchFailureDoesNotBlockTheSend
 * fails on purpose: a failing touch() (seen live as a duplicate-key error when two marker rows exist) aborts every
 * scheduled send.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class V11EmailTest extends TestCase
{
    private const TO = 'aivtest+e4@example.invalid';

    /** @var string[] */
    private array $calls = [];

    /** @var int */
    private int $sent = 0;

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
        // 2026-10-30 is a Friday (ISO 202644); 2020 and 2026 have 53 ISO weeks.
        return [
            'friday 11h config, friday 11:00' => ['2026-10-30 11:00:00', 5, 11, null, true],
            'friday 11h config, friday 10:59:59' => ['2026-10-30 10:59:59', 5, 11, null, false],
            'saturday config on friday' => ['2026-10-30 23:59:59', 6, 0, null, false],
            'catch-up monday 07h missed until friday' => ['2026-10-30 11:05:00', 1, 7, null, true],
            'catch-up with last send in week before' => ['2026-10-30 11:05:00', 1, 7, 202643, true],
            'sent this week -> not again on sunday 23:59:59' => ['2026-11-01 23:59:59', 1, 7, 202644, false],
            'sunday 23h missed -> not sent late on monday' => ['2026-11-02 06:00:00', 7, 23, 202643, false],
            'sunday 23:00 exactly' => ['2026-11-01 23:00:00', 7, 23, 202643, true],
            '2020-12-31 is 202053 (sent monday 2020-12-28)' => ['2020-12-31 08:00:00', 1, 7, 202053, false],
            '2021-01-03 still 202053' => ['2021-01-03 23:59:59', 1, 7, 202053, false],
            '2021-01-04 is 202101' => ['2021-01-04 07:00:00', 1, 7, 202053, true],
            '2024-12-30 is 202501 (last send 202452)' => ['2024-12-30 07:00:00', 1, 7, 202452, true],
            '2025-01-01 same ISO week as 2024-12-30' => ['2025-01-01 07:00:00', 1, 7, 202501, false],
            'hour 0 on configured day at 00:00:00' => ['2026-10-28 00:00:00', 3, 0, null, true],
        ];
    }

    /**
     * @dataProvider weekKeys
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('weekKeys')]
    public function testWeekKeyIsIsoYearAndWeek(string $now, int $expected): void
    {
        $this->assertSame($expected, WeeklySummarySender::weekKey(self::ts($now)));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function weekKeys(): array
    {
        return [
            '2020-12-31' => ['2020-12-31 12:00:00', 202053],
            '2021-01-03 23:59:59' => ['2021-01-03 23:59:59', 202053],
            '2021-01-04' => ['2021-01-04 00:00:00', 202101],
            '2024-12-30' => ['2024-12-30 00:00:00', 202501],
            '2025-12-29' => ['2025-12-29 08:00:00', 202601],
            '2027-01-03 23:59:59' => ['2027-01-03 23:59:59', 202653],
            '2027-01-04' => ['2027-01-04 00:00:00', 202701],
        ];
    }

    public function testFailedDeliveryReleasesTheClaimWithThePreviousWeek(): void
    {
        $marker = $this->marker(202643, 202643);
        $marker->expects($this->once())->method('release')->with(202644, 202643);
        $sender = $this->sender($marker, true);
        $this->assertFalse($sender->sendIfDue(self::ts('2026-10-30 11:05:00')));
        $this->assertSame(['lastWeek', 'touch', 'claim:202644', 'release'], $this->calls);
    }

    public function testSuccessfulDeliveryKeepsTheClaim(): void
    {
        $marker = $this->marker(202643, 202643);
        $marker->expects($this->never())->method('release');
        $sender = $this->sender($marker);
        $this->assertTrue($sender->sendIfDue(self::ts('2026-10-30 11:05:00')));
        $this->assertSame(1, $this->sent);
    }

    public function testLostClaimSendsNothing(): void
    {
        $marker = $this->marker(202643, null);
        $sender = $this->sender($marker);
        $this->assertFalse($sender->sendIfDue(self::ts('2026-10-30 11:05:00')));
        $this->assertSame(0, $this->sent);
    }

    public function testNoRecipientsDoesNotClaim(): void
    {
        $marker = $this->marker(null, 0);
        $marker->expects($this->never())->method('claim');
        $sender = $this->sender($marker, false, []);
        $this->assertFalse($sender->sendIfDue(self::ts('2026-10-30 11:05:00')));
    }

    public function testDisabledDoesNotTouchTheMarker(): void
    {
        $marker = $this->createMock(WeeklySendMarker::class);
        $marker->expects($this->never())->method('lastWeek');
        $marker->expects($this->never())->method('touch');
        $marker->expects($this->never())->method('claim');
        $sender = $this->sender($marker, false, [self::TO], false);
        $this->assertFalse($sender->sendIfDue(self::ts('2026-10-30 11:05:00')));
        $this->assertSame(0, $this->sent);
    }

    /**
     * Proves a robustness defect: touch() only keeps the marker row alive for the retention, yet a failure there
     * (live: DuplicateException when two email_weekly_sent rows exist) aborts the whole scheduled send, every hour.
     */
    public function testTouchFailureDoesNotBlockTheSend(): void
    {
        $marker = $this->marker(null, 0);
        $marker->method('touch')->willThrowException(
            new DuplicateException(__('Duplicate entry for key PRIMARY'))
        );
        $sender = $this->sender($marker);
        $this->assertTrue(
            $sender->sendIfDue(self::ts('2026-10-30 11:05:00')),
            'A failing marker touch() must not stop the weekly summary from being sent.'
        );
    }

    public function testTestSendToDoesNotClaimTheWeekAndIgnoresTheSwitch(): void
    {
        $marker = $this->createMock(WeeklySendMarker::class);
        $marker->expects($this->never())->method('claim');
        $sender = $this->sender($marker, false, [], false);
        $result = $sender->sendNow(self::TO, self::ts('2026-10-30 11:05:00'));
        $this->assertTrue($result['sent']);
        $this->assertSame([self::TO], $result['recipients']);
    }

    /**
     * @dataProvider badToLists
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badToLists')]
    public function testInvalidTestRecipientsAreRefusedBeforeSending(string $to): void
    {
        $sender = $this->sender($this->createMock(WeeklySendMarker::class));
        $result = $sender->sendNow($to, self::ts('2026-10-30 11:05:00'));
        $this->assertFalse($result['sent']);
        $this->assertSame(0, $this->sent);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badToLists(): array
    {
        $many = implode(',', array_map(static fn (int $i): string => 'u' . $i . '@example.invalid', range(1, 21)));
        return [
            'header injection' => [self::TO . "\r\nBcc: x@example.invalid"],
            'one invalid of two' => [self::TO . ', not-mail'],
            'separators only' => [' , ;'],
            'more than 20' => [$many],
        ];
    }

    public function testSplitEmailListNormalisesAndRejects(): void
    {
        $list = Config::splitEmailList("A@example.invalid; a@EXAMPLE.invalid,\n b@example.invalid\tc@example.invalid");
        $this->assertSame(['A@example.invalid', 'b@example.invalid', 'c@example.invalid'], $list['valid']);
        $this->assertSame([], $list['invalid']);
        $long = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 63) . '.invalid';
        $this->assertSame([$long], Config::splitEmailList($long)['invalid']);
        $injected = Config::splitEmailList("x@example.invalid\r\nBcc: y@example.invalid");
        $this->assertSame(['x@example.invalid', 'y@example.invalid'], $injected['valid']);
        $this->assertSame(['Bcc:'], $injected['invalid'], 'a header line can never survive as one address');
    }

    public function testHostileValuesAreEscapedAndNoExternalResources(): void
    {
        $formatter = new WeeklySummaryFormatter(new Escaper());
        $summary = self::summary();
        $vars = $formatter->templateVars($summary, "Shop <b>&</b>\r\nBcc: x@example.invalid", 'javascript:alert(1)');
        $html = implode('', $vars);
        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringNotContainsStringIgnoringCase('<img', $html);
        $this->assertStringNotContainsStringIgnoringCase('<a ', $vars['pages_html'] . $vars['recommendations_html']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $vars['pages_html']);
        $this->assertStringContainsString('aivteste4&lt;img src=x onerror=alert(3)&gt;', $vars['sources_html']);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp; &quot;quote&quot;', $vars['recommendations_html']);
        $this->assertStringContainsString('&lt;script&gt;alert(4)&lt;/script&gt;', $vars['candidates_html']);
        $this->assertSame('', $vars['dashboard_link_html'], 'javascript: link must be dropped');
        $this->assertStringNotContainsString("\n", $vars['subject']);
        $this->assertStringNotContainsString("\r", $vars['subject']);
        $this->assertStringContainsString('likely helped: +23% (range +10% to +40%)', $vars['verdicts_html']);
        $this->assertDoesNotMatchRegularExpression('/\s(src|background|on[a-z]+)\s*=/i', self::tagsOnly($html));
        $this->assertDoesNotMatchRegularExpression('/url\(/i', $html);
        $link = $formatter->templateVars($summary, 'Shop', 'https://hyva.test/admin/x/?a="b"')['dashboard_link_html'];
        $this->assertStringContainsString('href="https://hyva.test/admin/x/?a=&quot;b&quot;"', $link);
    }

    public function testTemplateHasNoImagesOrExternalResources(): void
    {
        $file = dirname(__DIR__, 3) . '/view/frontend/email/weekly_summary.html';
        $this->assertFileExists($file);
        $tpl = (string)file_get_contents($file);
        $this->assertStringNotContainsStringIgnoringCase('<img', $tpl);
        $this->assertDoesNotMatchRegularExpression('#(src|href)\s*=\s*["\']?https?://#i', $tpl);
        $this->assertDoesNotMatchRegularExpression('/url\(/i', $tpl);
        $this->assertStringContainsString('{{var site_name}}', $tpl, 'site name must be escaped by {{var}}');
        $this->assertStringNotContainsString('{{var site_name|raw}}', $tpl);
    }

    /**
     * @dataProvider changes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('changes')]
    public function testChangeLabel($current, $previous, string $expected): void
    {
        $this->assertSame($expected, WeeklySummaryFormatter::change($current, $previous));
    }

    /**
     * @return array<string, array{int|float, int|float, string}>
     */
    public static function changes(): array
    {
        // live values seen in mailpit: 195/10, 40/0, 7/3, 0/0
        return [
            'hits 195 vs 10' => [195, 10, '+1850%'],
            'live 40 vs 0' => [40, 0, 'new'],
            'visits 7 vs 3' => [7, 3, '+133%'],
            'zero vs zero' => [0, 0, 'no change'],
            'drop to zero' => [0, 5, '-100%'],
            'rounding half' => [1005, 1000, '+1%'],
        ];
    }

    public function testPeriodsAreTheLastSevenCompleteUtcDays(): void
    {
        $this->assertSame(
            ['from' => '2026-10-23', 'to' => '2026-10-29', 'prev_from' => '2026-10-16', 'prev_to' => '2026-10-22'],
            WeeklySummaryBuilder::periods(self::ts('2026-10-30 00:00:00'))
        );
        $this->assertSame(
            ['from' => '2026-12-25', 'to' => '2026-12-31', 'prev_from' => '2026-12-18', 'prev_to' => '2026-12-24'],
            WeeklySummaryBuilder::periods(self::ts('2027-01-01 23:59:59'))
        );
    }

    /**
     * Marker mock recording the call order
     *
     * @param int|null $lastWeek
     * @param int|null $claim
     * @return WeeklySendMarker&\PHPUnit\Framework\MockObject\MockObject
     */
    private function marker(?int $lastWeek, ?int $claim)
    {
        $marker = $this->createMock(WeeklySendMarker::class);
        $marker->method('lastWeek')->willReturnCallback(function () use ($lastWeek) {
            $this->calls[] = 'lastWeek';
            return $lastWeek;
        });
        $marker->method('touch')->willReturnCallback(function (): void {
            $this->calls[] = 'touch';
        });
        $marker->method('claim')->willReturnCallback(function (int $week) use ($claim) {
            $this->calls[] = 'claim:' . $week;
            return $claim;
        });
        $marker->method('release')->willReturnCallback(function (): void {
            $this->calls[] = 'release';
        });
        return $marker;
    }

    /**
     * Sender with a scripted transport
     *
     * @param WeeklySendMarker $marker
     * @param bool $failing
     * @param string[] $recipients
     * @param bool $enabled
     * @return WeeklySummarySender
     */
    private function sender(WeeklySendMarker $marker, bool $failing = false, array $recipients = [self::TO], bool $enabled = true)
    {
        $this->sent = 0;
        $config = $this->createMock(Config::class);
        $config->method('emailEnabled')->willReturn($enabled);
        $config->method('emailRecipients')->willReturn($recipients);
        $config->method('emailWeekday')->willReturn(1);
        $config->method('emailHour')->willReturn(7);
        $config->method('emailSender')->willReturn('general');
        $builder = $this->createMock(WeeklySummaryBuilder::class);
        $builder->method('build')->willReturn(self::summary());
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('sendMessage')->willReturnCallback(function () use ($failing): void {
            if ($failing) {
                throw new MailException(__('E4 simulated SMTP failure'));
            }
            $this->sent++;
        });
        $tb = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $m) {
            $tb->method($m)->willReturnSelf();
        }
        $tb->method('getTransport')->willReturn($transport);
        $url = $this->createMock(BackendUrl::class);
        $url->method('getUrl')->willReturn('https://hyva.test/admin/panth_aivisibility/dashboard/index/');
        $urlFactory = $this->createMock(BackendUrlFactory::class);
        $urlFactory->method('create')->willReturn($url);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn(null);
        return new WeeklySummarySender(
            $config,
            $builder,
            new WeeklySummaryFormatter(new Escaper()),
            $marker,
            $tb,
            $storeManager,
            $this->createMock(Emulation::class),
            $urlFactory,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * Summary with the hostile strings used live
     *
     * @return array
     */
    private static function summary(): array
    {
        return [
            'from' => '2026-10-23', 'to' => '2026-10-29', 'prev_from' => '2026-10-16', 'prev_to' => '2026-10-22',
            'metrics' => [
                'crawler_hits' => ['current' => 195, 'previous' => 10],
                'live_fetches' => ['current' => 40, 'previous' => 0],
                'ai_visits' => ['current' => 7, 'previous' => 3],
                'ai_orders' => ['current' => 0, 'previous' => 0],
                'ai_revenue' => ['current' => 0.0, 'previous' => 0.0],
            ],
            'money' => ['current' => '$0.00', 'previous' => '$0.00'],
            'top_pages' => [[
                'label' => '/aivtest-e4-<script>alert(1)</script>?q="x"&y=1',
                'path' => '/aivtest-e4-<script>alert(1)</script>?q="x"&y=1', 'type' => 'Page', 'hits' => 40,
            ], [
                'label' => "/aivtest-e4-{{var subject}}-'onmouseover=alert(2) <img src=http://evil.example.invalid/t.png>",
                'path' => "/aivtest-e4-{{var subject}}-'onmouseover=alert(2)", 'type' => 'Page', 'hits' => 25,
            ]],
            'top_sources' => [['name' => 'aivteste4<img src=x onerror=alert(3)>', 'visits' => 7]],
            'recommendations' => [[
                'title' => 'AivTest-E4 <b>bold</b> & "quote" <a href="http://evil.example.invalid/x">x</a>',
                'rule_code' => 'aivtest_e4', 'impact_score' => 100, 'affected_count' => 50,
            ]],
            'verdicts' => [[
                'title' => 'AivTest-E4 <b>bold</b>', 'verdict' => 'helped', 'effect' => 1.2345, 'ci_low' => 1.1,
                'ci_high' => 1.4, 'metric' => 'bot_hits',
            ]],
            'candidates' => ['count' => 1, 'items' => [[
                'type' => 'bot', 'key' => 'AivTest-E4Agent/1.0 <script>alert(4)</script> {{trans "x"}}', 'hits' => 9,
            ]]],
        ];
    }

    /**
     * Only the markup of the tags (attributes), text content removed
     *
     * @param string $html
     * @return string
     */
    private static function tagsOnly(string $html): string
    {
        preg_match_all('/<[^>]+>/', $html, $m);
        return implode('', $m[0]);
    }

    /**
     * Unix time of a UTC 'Y-m-d H:i:s'
     *
     * @param string $value
     * @return int
     */
    private static function ts(string $value): int
    {
        return (int)(new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('U');
    }
}
