<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\AiVisibility\Api\HealthRepositoryInterface;
use Panth\AiVisibility\Model\HealthResult;
use Psr\Log\LoggerInterface;

/**
 * Data-health card on the dashboard (block panth_aiv.health_card).
 */
class HealthCard extends Template
{
    /**
     * @var string
     */
    protected $_template = 'Panth_AiVisibility::health/card.phtml';

    /**
     * @var array|null
     */
    private ?array $results = null;

    /**
     * @param Context $context
     * @param HealthRepositoryInterface $repository
     * @param TimezoneInterface $timezone
     * @param LoggerInterface $logger
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly HealthRepositoryInterface $repository,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Latest results: code => ['label', 'status', 'message', 'details', 'checked_at']
     *
     * @return array
     */
    public function getResults(): array
    {
        if ($this->results === null) {
            try {
                $this->results = $this->repository->latest();
            } catch (\Throwable $e) {
                $this->logger->warning('[Panth_AiVisibility] health results could not be read: ' . $e->getMessage());
                $this->results = [];
            }
        }
        return $this->results;
    }

    /**
     * Admin message CSS modifier for a status
     *
     * @param string $status
     * @return string
     */
    public function getMessageClass(string $status): string
    {
        switch ($status) {
            case HealthResult::STATUS_OK:
                return 'message-success success';
            case HealthResult::STATUS_WARN:
                return 'message-warning warning';
            case HealthResult::STATUS_ERROR:
                return 'message-error error';
            default:
                return 'message-notice notice';
        }
    }

    /**
     * Status label
     *
     * @param string $status
     * @return string
     */
    public function getStatusLabel(string $status): string
    {
        switch ($status) {
            case HealthResult::STATUS_OK:
                return (string)__('OK');
            case HealthResult::STATUS_WARN:
                return (string)__('Warning');
            case HealthResult::STATUS_ERROR:
                return (string)__('Error');
            default:
                return (string)__('Unknown');
        }
    }

    /**
     * UTC 'Y-m-d H:i:s' as admin-locale date and time
     *
     * @param string $utc
     * @return string
     */
    public function formatCheckedAt(string $utc): string
    {
        if ($utc === '') {
            return '';
        }
        try {
            return $this->timezone->formatDateTime(
                new \DateTime($utc, new \DateTimeZone('UTC')),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            );
        } catch (\Throwable $e) {
            return $utc . ' UTC';
        }
    }

    /**
     * URL of the "Run checks now" POST action
     *
     * @return string
     */
    public function getRunUrl(): string
    {
        return $this->getUrl('panth_aivisibility/health/run');
    }

    /**
     * Whether the current admin may run the checks
     *
     * @return bool
     */
    public function canRun(): bool
    {
        return $this->_authorization->isAllowed('Panth_AiVisibility::dashboard');
    }
}
