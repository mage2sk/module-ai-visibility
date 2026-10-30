<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\ViewModel\Recommendation\BeforeAfterBars;
use Panth\AiVisibility\ViewModel\Recommendation\DataSource;
use Panth\AiVisibility\ViewModel\Recommendation\FixLinkResolver;
use Panth\AiVisibility\ViewModel\Recommendation\Presenter;
use Psr\Log\LoggerInterface;

/**
 * Recommendations page: ranked open recommendations with their forms, and the applied / verified actions.
 */
class Recommendations extends Template
{
    public const ACTION_LIMIT = 50;

    /**
     * @var string
     */
    protected $_template = 'Panth_AiVisibility::recommendation/index.phtml';

    /**
     * @var array[]|null
     */
    private ?array $actions = null;

    /**
     * @param Context $context
     * @param DataSource $dataSource
     * @param Presenter $presenter
     * @param FixLinkResolver $fixLinks
     * @param BeforeAfterBars $bars
     * @param Config $config
     * @param TimezoneInterface $timezone
     * @param LoggerInterface $logger
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly DataSource $dataSource,
        private readonly Presenter $presenter,
        private readonly FixLinkResolver $fixLinks,
        private readonly BeforeAfterBars $bars,
        private readonly Config $config,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Wording helper for the template
     *
     * @return Presenter
     */
    public function getPresenter(): Presenter
    {
        return $this->presenter;
    }

    /**
     * Store view filter: a store view ID, or null for all store views
     *
     * @return int|null
     */
    public function getStoreId(): ?int
    {
        $value = $this->getRequest()->getParam('store');
        if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value <= 0) {
            return null;
        }
        return isset($this->getStoreOptions()[(int)$value]) ? (int)$value : null;
    }

    /**
     * Store views: id => "Website / Store view"
     *
     * @return array<int, string>
     */
    public function getStoreOptions(): array
    {
        $options = [];
        try {
            foreach ($this->_storeManager->getStores() as $store) {
                $name = (string)$store->getName();
                try {
                    $name = (string)$store->getWebsite()->getName() . ' / ' . $name;
                } catch (\Throwable $e) {
                    // keep the store view name alone
                    $name = (string)$store->getName();
                }
                $options[(int)$store->getId()] = $name;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] store views could not be read: ' . $e->getMessage());
        }
        ksort($options);
        return $options;
    }

    /**
     * Name of a store view ("All store views" for 0 / unknown)
     *
     * @param int $storeId
     * @return string
     */
    public function getStoreLabel(int $storeId): string
    {
        return $this->getStoreOptions()[$storeId] ?? (string)__('All store views');
    }

    /**
     * Whether recommendations are switched on in the configuration
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        try {
            return $this->config->recommendationsEnabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Days of data compared before and after a fix
     *
     * @return int
     */
    public function getVerifyWindowDays(): int
    {
        try {
            return $this->config->verifyWindowDays();
        } catch (\Throwable $e) {
            return 14;
        }
    }

    /**
     * Open recommendations of the selected store view, ranked best first
     *
     * @return array[]
     */
    public function getOpenRecommendations(): array
    {
        try {
            $limit = $this->config->recommendationsMaxOpen();
        } catch (\Throwable $e) {
            $limit = 50;
        }
        return $this->dataSource->openRecommendations($this->getStoreId(), $limit);
    }

    /**
     * Recommendations per status
     *
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return $this->dataSource->counts();
    }

    /**
     * Real applied actions of the selected store view, newest first (simulated actions are left out)
     *
     * @return array[]
     */
    public function getActions(): array
    {
        if ($this->actions === null) {
            $storeId = $this->getStoreId();
            $this->actions = array_values(array_filter(
                $this->dataSource->recentActions(self::ACTION_LIMIT),
                static fn (array $row): bool => $storeId === null
                    || (int)($row['store_id'] ?? 0) === 0
                    || (int)($row['store_id'] ?? 0) === $storeId
            ));
        }
        return $this->actions;
    }

    /**
     * "How to fix" link of a recommendation, or null
     *
     * @param array $recommendation
     * @return array{url: string, label: string}|null
     */
    public function getFixLink(array $recommendation): ?array
    {
        try {
            return $this->fixLinks->resolve($recommendation);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Status of an action in plain words
     *
     * @param array $action
     * @return string
     */
    public function getActionStatusText(array $action): string
    {
        $status = (string)($action['status'] ?? '');
        if ($status === ActionLogInterface::STATUS_UNDONE) {
            $undone = $this->formatUtcDate((string)($action['undone_at'] ?? ''));
            return $undone === ''
                ? (string)__('Undone, not verified')
                : (string)__('Undone on %1, not verified', $undone);
        }
        if ($action['impact'] !== null && $status !== ActionLogInterface::STATUS_PENDING) {
            return $this->presenter->effectSentence($action['impact'], (string)($action['subject_type'] ?? ''));
        }
        if ($status === ActionLogInterface::STATUS_INSUFFICIENT) {
            return $this->presenter->verdictLabel('insufficient');
        }
        $day = $this->formatUtcDay((string)($action['verify_after'] ?? ''));
        return $day === ''
            ? (string)__('Waiting for data')
            : (string)__('Waiting for data, checked on or after %1', $day);
    }

    /**
     * CSS tone of an action status: good | bad | neutral | unknown | pending | undone
     *
     * @param array $action
     * @return string
     */
    public function getActionTone(array $action): string
    {
        $status = (string)($action['status'] ?? '');
        if ($status === ActionLogInterface::STATUS_UNDONE) {
            return 'undone';
        }
        if ($action['impact'] !== null && $status !== ActionLogInterface::STATUS_PENDING) {
            return $this->presenter->verdictTone((string)($action['impact']['verdict'] ?? ''));
        }
        return $status === ActionLogInterface::STATUS_INSUFFICIENT ? 'unknown' : 'pending';
    }

    /**
     * Whether the action can still be undone
     *
     * @param array $action
     * @return bool
     */
    public function canUndo(array $action): bool
    {
        return (string)($action['status'] ?? '') === ActionLogInterface::STATUS_PENDING;
    }

    /**
     * Whether any listed action can still be undone (the table then shows its Action column)
     *
     * @return bool
     */
    public function hasUndoableActions(): bool
    {
        foreach ($this->getActions() as $action) {
            if ($this->canUndo($action)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Before / after SVG of a verified action ('' when not verified); markup is escaped already
     *
     * @param array $action
     * @return string
     */
    public function getBarsSvg(array $action): string
    {
        if ($action['impact'] === null || (string)($action['status'] ?? '') === ActionLogInterface::STATUS_UNDONE) {
            return '';
        }
        try {
            return $this->bars->render($action['impact'], (string)($action['metric'] ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Who applied the action
     *
     * @param array $action
     * @return string
     */
    public function getAppliedBy(array $action): string
    {
        $by = trim((string)($action['applied_by'] ?? ''));
        return $by === '' ? (string)__('command line') : $by;
    }

    /**
     * UTC 'Y-m-d H:i:s' as admin-locale date and time
     *
     * @param string|null $utc
     * @return string
     */
    public function formatUtcDate(?string $utc): string
    {
        if ($utc === null || $utc === '') {
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
     * UTC day 'Y-m-d' as an admin-locale date (no time-zone shift)
     *
     * @param string|null $day
     * @return string
     */
    public function formatUtcDay(?string $day): string
    {
        if ($day === null || $day === '') {
            return '';
        }
        try {
            return $this->timezone->formatDateTime(
                new \DateTime(substr($day, 0, 10), new \DateTimeZone('UTC')),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::NONE,
                null,
                'UTC'
            );
        } catch (\Throwable $e) {
            return substr($day, 0, 10);
        }
    }

    /**
     * URL of a POST action of this page
     *
     * @param string $action apply | dismiss | feedback | undo | refresh
     * @return string
     */
    public function getPostUrl(string $action): string
    {
        return $this->getUrl('panth_aivisibility/recommendation/' . $action);
    }

    /**
     * URL of this page for a store view filter
     *
     * @return string
     */
    public function getFilterUrl(): string
    {
        return $this->getUrl('panth_aivisibility/recommendation/index');
    }

    /**
     * URL of the configuration section
     *
     * @return string
     */
    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => Config::SECTION]);
    }

    /**
     * URL of the dashboard
     *
     * @return string
     */
    public function getDashboardUrl(): string
    {
        return $this->getUrl('panth_aivisibility/dashboard/index');
    }
}
