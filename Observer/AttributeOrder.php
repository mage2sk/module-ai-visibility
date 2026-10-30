<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Observer;

use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Panth\AiVisibility\Api\AttributionCookieInterface;
use Panth\AiVisibility\Model\Attribution;
use Panth\AiVisibility\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Copies the signed AI attribution cookie onto newly placed orders.
 *
 * Listens to sales_model_service_quote_submit_success (one-page checkout; dispatched inside the
 * try block of QuoteManagement::submitQuote(), so an exception escaping from here would undo the
 * checkout) and to checkout_submit_all_after, which multishipping checkout dispatches with all
 * its orders under "orders". The one-page checkout also dispatches checkout_submit_all_after
 * (with a single "order"); that call is skipped because the order was handled already, and the
 * INSERT IGNORE keeps a second row out in any case. Every failure is caught and logged at warning level.
 */
class AttributeOrder implements ObserverInterface
{
    public const TABLE = 'panth_aiv_order';
    public const CHANNEL_REFERRAL = 'referral';
    public const EVENT_SUBMIT_ALL_AFTER = 'checkout_submit_all_after';

    private const SOURCE_MAX = 64;
    private const PATH_MAX = 512;

    /**
     * @param AttributionCookieInterface $attributionCookie
     * @param ResourceConnection $resource
     * @param State $appState
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AttributionCookieInterface $attributionCookie,
        private readonly ResourceConnection $resource,
        private readonly State $appState,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Record the AI attribution of the order placed by the current visitor
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            if ($this->isAdminArea()) {
                return;
            }
            $orders = $this->orders($observer);
            if ($orders === []) {
                return;
            }
            $attribution = null;
            foreach ($orders as $order) {
                $orderId = (int)$order->getEntityId();
                if ($orderId <= 0 || !$this->config->isEnabled((int)$order->getStoreId())) {
                    continue;
                }
                if ($attribution === null) {
                    $attribution = $this->attributionCookie->read();
                    if ($attribution === null || $this->isExpired($attribution)) {
                        return;
                    }
                }
                try {
                    $this->insert($orderId, $attribution);
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        '[Panth_AiVisibility] Order attribution skipped for order ' . $orderId . ': ' . $e->getMessage()
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] Order attribution skipped: ' . $e->getMessage());
        }
    }

    /**
     * Orders carried by the event: "orders" (multishipping) or "order" (one-page checkout)
     *
     * @param Observer $observer
     * @return OrderInterface[]
     */
    private function orders(Observer $observer): array
    {
        $event = $observer->getEvent();
        $orders = $event->getData('orders');
        if (is_array($orders)) {
            return array_values(array_filter($orders, static fn ($order): bool => $order instanceof OrderInterface));
        }
        if ($event->getName() === self::EVENT_SUBMIT_ALL_AFTER) {
            // One-page checkout: already attributed on sales_model_service_quote_submit_success.
            return [];
        }
        $order = $event->getData('order');
        return $order instanceof OrderInterface ? [$order] : [];
    }

    /**
     * Whether the order is being placed from the admin panel (no shopper cookie there)
     *
     * @return bool
     */
    private function isAdminArea(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Defensive window check on top of the cookie service: the last touch must lie within the window
     *
     * @param Attribution $attribution
     * @return bool
     */
    private function isExpired(Attribution $attribution): bool
    {
        $lastAt = max($attribution->lastAt, $attribution->firstAt);
        if ($lastAt <= 0) {
            return false;
        }
        return $lastAt < time() - $this->config->attributionWindowDays() * 86400;
    }

    /**
     * INSERT IGNORE one attribution row; an existing row for the order is kept
     *
     * @param int $orderId
     * @param Attribution $attribution
     * @return void
     */
    private function insert(int $orderId, Attribution $attribution): void
    {
        $first = $this->cut($attribution->firstSource, self::SOURCE_MAX);
        $last = $this->cut($attribution->lastSource, self::SOURCE_MAX);
        if ($first === '' && $last === '') {
            return;
        }
        if ($first === '') {
            $first = $last;
        } elseif ($last === '') {
            $last = $first;
        }
        $firstAt = $attribution->firstAt > 0 ? $attribution->firstAt : $attribution->lastAt;
        $lastAt = $attribution->lastAt > 0 ? $attribution->lastAt : $firstAt;
        $now = time();
        if ($firstAt > 0 && $lastAt > 0 && $firstAt < $now - $this->config->attributionWindowDays() * 86400) {
            // First touch older than the attribution window: credit the last touch only.
            $first = $last;
            $firstAt = $lastAt;
        }
        $landing = $this->cut($attribution->landingPath, self::PATH_MAX);

        $row = [
            'order_id' => $orderId,
            'channel' => self::CHANNEL_REFERRAL,
            'first_source' => $first,
            'first_at' => gmdate('Y-m-d H:i:s', $firstAt > 0 ? $firstAt : $now),
            'last_source' => $last,
            'last_at' => gmdate('Y-m-d H:i:s', $lastAt > 0 ? $lastAt : $now),
            'landing_path' => $landing !== '' ? $landing : null,
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'is_simulated' => 0,
        ];

        $connection = $this->resource->getConnection();
        $columns = array_map([$connection, 'quoteIdentifier'], array_keys($row));
        $sql = sprintf(
            'INSERT IGNORE INTO %s (%s) VALUES (%s)',
            $connection->quoteIdentifier($this->resource->getTableName(self::TABLE)),
            implode(', ', $columns),
            implode(', ', array_fill(0, count($row), '?'))
        );
        $connection->query($sql, array_values($row));
    }

    /**
     * Trim and cut a value to a column length
     *
     * @param string $value
     * @param int $length
     * @return string
     */
    private function cut(string $value, int $length): string
    {
        return trim(mb_substr(trim($value), 0, $length));
    }
}
