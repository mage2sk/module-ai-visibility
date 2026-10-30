<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Source\AttributionModel;

/**
 * "AI Attribution" section on the admin order view page.
 *
 * Reads the panth_aiv_order row of the order in the request (order_id); renders nothing
 * when the order has no AI attribution.
 */
class Attribution extends Template
{
    private const TABLE = 'panth_aiv_order';

    /**
     * @var array|null|false false = not loaded yet
     */
    private $row = false;

    /**
     * @param Context $context
     * @param ResourceConnection $resource
     * @param SourceClassifierInterface $sourceClassifier
     * @param Config $config
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly SourceClassifierInterface $sourceClassifier,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Attribution row of the current order, or null
     *
     * @return array|null
     */
    public function getAttribution(): ?array
    {
        if ($this->row !== false) {
            return $this->row;
        }
        $this->row = null;
        $orderId = (int)$this->getRequest()->getParam('order_id');
        if ($orderId <= 0) {
            return null;
        }
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('order_id = ?', $orderId)
                ->limit(1);
            $row = $connection->fetchRow($select);
            $this->row = is_array($row) && $row !== [] ? $row : null;
        } catch (\Throwable $e) {
            $this->_logger->warning('[Panth_AiVisibility] Order attribution not loaded: ' . $e->getMessage());
        }
        return $this->row;
    }

    /**
     * Display name of a source code
     *
     * @param string|null $code
     * @return string
     */
    public function getSourceName(?string $code): string
    {
        $code = trim((string)$code);
        if ($code === '') {
            return '';
        }
        try {
            $name = trim($this->sourceClassifier->nameFor($code));
        } catch (\Throwable $e) {
            return $code;
        }
        return $name !== '' ? $name : $code;
    }

    /**
     * Whether the last touch gets the credit (general/attribution_model)
     *
     * @return bool
     */
    public function isLastTouchModel(): bool
    {
        return $this->config->attributionModel() === AttributionModel::LAST;
    }

    /**
     * Source code credited by the configured attribution model
     *
     * @return string
     */
    public function getCreditedSource(): string
    {
        $row = $this->getAttribution();
        if ($row === null) {
            return '';
        }
        return (string)($this->isLastTouchModel() ? $row['last_source'] ?? '' : $row['first_source'] ?? '');
    }

    /**
     * Human label of the attribution channel
     *
     * @param string|null $channel
     * @return string
     */
    public function getChannelLabel(?string $channel): string
    {
        $labels = [
            'referral' => __('AI assistant referral'),
            'probable' => __('Probable (inferred)'),
            'webmcp' => __('WebMCP'),
            'agent_checkout' => __('Agent checkout'),
        ];
        $channel = (string)$channel;
        return isset($labels[$channel]) ? (string)$labels[$channel] : $channel;
    }

    /**
     * Format a stored UTC datetime in the admin locale and the configured timezone
     *
     * @param string|null $utc
     * @return string
     */
    public function formatUtc(?string $utc): string
    {
        $utc = trim((string)$utc);
        if ($utc === '' || str_starts_with($utc, '0000-00-00')) {
            return '';
        }
        try {
            $date = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            return (string)$this->_localeDate->formatDateTime(
                $date,
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            );
        } catch (\Throwable $e) {
            return $utc;
        }
    }

    /**
     * Render nothing when the order has no AI attribution
     *
     * @return string
     */
    protected function _toHtml()
    {
        if ($this->getAttribution() === null) {
            return '';
        }
        return parent::_toHtml();
    }
}
