<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Email;

use Magento\Backend\Model\UrlFactory as BackendUrlFactory;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Sends the weekly summary email through the Magento mail transport.
 *
 * Schedule (cron job panth_aiv_weekly_email, hourly): the summary goes out in the first run at or after the
 * configured weekday and hour (UTC), at most once per ISO week. When cron did not run at that hour, the next
 * run in the same ISO week sends it (catch-up); a week that has ended is not sent late. The sent week is
 * kept in panth_aiv_counter (WeeklySendMarker). The sender identity is the store email identity from
 * panth_aivisibility/email/sender, resolved for the default store view.
 */
class WeeklySummarySender
{
    private const DASHBOARD_ROUTE = 'panth_aivisibility/dashboard/index';
    private const LOG_PREFIX = '[Panth_AiVisibility] weekly summary: ';

    /**
     * @param Config $config
     * @param WeeklySummaryBuilder $builder
     * @param WeeklySummaryFormatter $formatter
     * @param WeeklySendMarker $marker
     * @param TransportBuilder $transportBuilder
     * @param StoreManagerInterface $storeManager
     * @param Emulation $emulation
     * @param BackendUrlFactory $backendUrlFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly WeeklySummaryBuilder $builder,
        private readonly WeeklySummaryFormatter $formatter,
        private readonly WeeklySendMarker $marker,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation $emulation,
        private readonly BackendUrlFactory $backendUrlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Send the summary when the configured weekday / hour (UTC) is reached and it was not sent this week
     *
     * Never throws.
     *
     * @param int|null $now Unix time (null = current time)
     * @return bool Whether an email was sent
     */
    public function sendIfDue(?int $now = null): bool
    {
        try {
            if (!$this->config->emailEnabled()) {
                return false;
            }
            $now ??= time();
            $today = gmdate('Y-m-d', $now);
            $lastWeek = $this->marker->lastWeek();
            try {
                // Only keeps the marker row inside the counter retention; never a reason not to send.
                $this->marker->touch($today);
            } catch (\Throwable $e) {
                $this->logger->warning(self::LOG_PREFIX . 'could not refresh the sent-week marker: ' . $e->getMessage());
            }
            if (!self::isDue($now, $this->config->emailWeekday(), $this->config->emailHour(), $lastWeek)) {
                return false;
            }
            $recipients = $this->config->emailRecipients();
            if ($recipients === []) {
                $this->logger->warning(self::LOG_PREFIX . 'switched on but no valid recipient is configured.');
                return false;
            }
            $week = self::weekKey($now);
            $previous = $this->marker->claim($week, $today);
            if ($previous === null) {
                return false;
            }
            $error = $this->deliver($recipients, $now);
            if ($error !== null) {
                $this->marker->release($week, $previous);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(self::LOG_PREFIX . 'scheduled send failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Whether the summary is due: the configured weekday / hour of this ISO week is reached and not yet sent
     *
     * @param int $now Unix time
     * @param int $weekday 1 = Monday .. 7 = Sunday
     * @param int $hour 0..23 (UTC)
     * @param int|null $lastWeek ISO week (YYYYWW) of the last send
     * @return bool
     */
    public static function isDue(int $now, int $weekday, int $hour, ?int $lastWeek): bool
    {
        if ($lastWeek !== null && $lastWeek === self::weekKey($now)) {
            return false;
        }
        $weekday = max(1, min(7, $weekday));
        $hour = max(0, min(23, $hour));
        $todayN = (int)gmdate('N', $now);
        $nowHour = (int)gmdate('G', $now);
        return $todayN > $weekday || ($todayN === $weekday && $nowHour >= $hour);
    }

    /**
     * ISO week of a time as YYYYWW (ISO year, so 2026-12-31 can belong to week 202653 or 202701)
     *
     * @param int $now Unix time
     * @return int
     */
    public static function weekKey(int $now): int
    {
        return (int)gmdate('o', $now) * 100 + (int)gmdate('W', $now);
    }

    /**
     * Subject and plain-text summary without sending
     *
     * @param int|null $now
     * @return array{subject: string, text: string, recipients: string[]}
     */
    public function preview(?int $now = null): array
    {
        $now ??= time();
        $summary = $this->builder->build($now);
        $site = $this->siteName();
        return [
            'subject' => $this->formatter->subject($summary, $site),
            'text' => $this->formatter->text($summary, $site),
            'recipients' => $this->config->emailRecipients(),
        ];
    }

    /**
     * Send now (CLI): to $to (a test send, ignores the on/off switch and the schedule) or to the configured
     * recipients (needs the summary switched on; marks the week as sent)
     *
     * @param string|null $to One or more addresses (comma separated)
     * @param int|null $now
     * @return array{sent: bool, recipients: string[], message: string}
     */
    public function sendNow(?string $to = null, ?int $now = null): array
    {
        $now ??= time();
        if ($to !== null) {
            $list = Config::splitEmailList($to);
            if ($list['invalid'] !== []) {
                return $this->result(false, [], (string)__('Invalid address(es): %1', implode(', ', $list['invalid'])));
            }
            if ($list['valid'] === []) {
                return $this->result(false, [], (string)__('Give at least one address with --to.'));
            }
            if (count($list['valid']) > Config::EMAIL_MAX_RECIPIENTS) {
                return $this->result(
                    false,
                    [],
                    (string)__('Give at most %1 addresses.', Config::EMAIL_MAX_RECIPIENTS)
                );
            }
            $recipients = $list['valid'];
        } else {
            if (!$this->config->emailEnabled()) {
                return $this->result(false, [], (string)__(
                    'The weekly summary is switched off. Switch it on in the configuration or test with --to=<address>.'
                ));
            }
            $recipients = $this->config->emailRecipients();
            if ($recipients === []) {
                return $this->result(false, [], (string)__('No valid recipient is configured.'));
            }
        }

        $error = $this->deliver($recipients, $now);
        if ($error !== null) {
            return $this->result(false, $recipients, (string)__('Sending failed: %1', $error));
        }
        if ($to === null) {
            try {
                $this->marker->claim(self::weekKey($now), gmdate('Y-m-d', $now));
            } catch (\Throwable $e) {
                $this->logger->warning(self::LOG_PREFIX . 'could not store the sent week: ' . $e->getMessage());
            }
        }
        return $this->result(true, $recipients, (string)__('Sent.'));
    }

    /**
     * Build, render and send the email
     *
     * @param string[] $recipients Validated addresses
     * @param int $now
     * @return string|null Error message, or null when sent
     */
    private function deliver(array $recipients, int $now): ?string
    {
        try {
            $summary = $this->builder->build($now);
            $site = $this->siteName();
            $vars = $this->formatter->templateVars($summary, $site, $this->dashboardUrl());
            $storeId = $this->storeId();
        } catch (\Throwable $e) {
            $this->logger->warning(self::LOG_PREFIX . 'could not be built: ' . $e->getMessage());
            return $e->getMessage();
        }

        $emulating = false;
        try {
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            $emulating = true;
            $this->transportBuilder
                ->setTemplateIdentifier(Config::EMAIL_TEMPLATE_WEEKLY_SUMMARY)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars($vars)
                ->setFromByScope($this->config->emailSender(), $storeId);
            foreach ($recipients as $recipient) {
                $this->transportBuilder->addTo($recipient);
            }
            $this->transportBuilder->getTransport()->sendMessage();
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning(self::LOG_PREFIX . 'send failed: ' . $e->getMessage());
            return $e->getMessage();
        } finally {
            if ($emulating) {
                $this->emulation->stopEnvironmentEmulation();
            }
        }
    }

    /**
     * Admin dashboard URL (no secret key: the link is opened later, after login), '' when it cannot be built
     *
     * @return string
     */
    private function dashboardUrl(): string
    {
        try {
            $url = $this->backendUrlFactory->create();
            $url->turnOffSecretKey();
            return (string)$url->getUrl(self::DASHBOARD_ROUTE);
        } catch (\Throwable $e) {
            $this->logger->warning(self::LOG_PREFIX . 'admin dashboard link could not be built: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Default store view (sender identity and template scope)
     *
     * @return int
     */
    private function storeId(): int
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
            if ($store !== null) {
                return (int)$store->getId();
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        return Store::DEFAULT_STORE_ID;
    }

    /**
     * Store name shown in the email
     *
     * @return string
     */
    private function siteName(): string
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
            if ($store !== null) {
                $name = method_exists($store, 'getFrontendName') ? (string)$store->getFrontendName() : '';
                return $name !== '' ? $name : (string)$store->getName();
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        return 'Magento';
    }

    /**
     * Result array of sendNow()
     *
     * @param bool $sent
     * @param string[] $recipients
     * @param string $message
     * @return array{sent: bool, recipients: string[], message: string}
     */
    private function result(bool $sent, array $recipients, string $message): array
    {
        return ['sent' => $sent, 'recipients' => $recipients, 'message' => $message];
    }
}
