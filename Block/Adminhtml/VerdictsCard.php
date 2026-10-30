<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\ViewModel\Recommendation\DataSource;
use Panth\AiVisibility\ViewModel\Recommendation\Presenter;

/**
 * "Latest verdicts" card on the dashboard (layout block panth_aiv.verdicts_card).
 *
 * Shows the three latest verified fixes and how many still wait for data. Prints nothing when the
 * admin may not open the Recommendations page, or when no fix was applied yet.
 */
class VerdictsCard extends Template
{
    public const LIMIT = 3;
    public const SCAN_LIMIT = 50;

    /**
     * @var string
     */
    protected $_template = 'Panth_AiVisibility::recommendation/verdicts_card.phtml';

    /**
     * @var array[]|null
     */
    private ?array $actions = null;

    /**
     * @param Context $context
     * @param DataSource $dataSource
     * @param Presenter $presenter
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly DataSource $dataSource,
        private readonly Presenter $presenter,
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
     * Latest three actions with a verdict (not undone), newest first
     *
     * @return array[]
     */
    public function getLatestVerdicts(): array
    {
        $result = [];
        foreach ($this->actions() as $action) {
            if ($action['impact'] === null
                || in_array($action['status'], [ActionLogInterface::STATUS_PENDING, ActionLogInterface::STATUS_UNDONE], true)
            ) {
                continue;
            }
            $result[] = $action;
            if (count($result) >= self::LIMIT) {
                break;
            }
        }
        return $result;
    }

    /**
     * Number of applied fixes still waiting for enough data (among the latest SCAN_LIMIT)
     *
     * @return int
     */
    public function getPendingCount(): int
    {
        return count(array_filter(
            $this->actions(),
            static fn (array $action): bool => $action['status'] === ActionLogInterface::STATUS_PENDING
        ));
    }

    /**
     * "1 applied fix is waiting ..." / "3 applied fixes are waiting ..." ('' when none)
     *
     * @return string
     */
    public function getPendingText(): string
    {
        $pending = $this->getPendingCount();
        if ($pending <= 0) {
            return '';
        }
        return $pending === 1
            ? (string)__('1 applied fix is waiting for enough data.')
            : (string)__('%1 applied fixes are waiting for enough data.', $pending);
    }

    /**
     * Verdict sentence of an action (coverage results worded as shares)
     *
     * @param array $action
     * @return string
     */
    public function getVerdictText(array $action): string
    {
        return $this->presenter->effectSentence($action['impact'], (string)($action['subject_type'] ?? ''));
    }

    /**
     * CSS tone of a verdict
     *
     * @param array $action
     * @return string
     */
    public function getTone(array $action): string
    {
        return $this->presenter->verdictTone((string)($action['impact']['verdict'] ?? ''));
    }

    /**
     * URL of the Recommendations page
     *
     * @return string
     */
    public function getPageUrl(): string
    {
        return $this->getUrl('panth_aivisibility/recommendation/index');
    }

    /**
     * @inheritdoc
     */
    protected function _prepareLayout()
    {
        if ($this->pageConfig !== null) {
            $this->pageConfig->addPageAsset(RecommendationsCard::CSS_ASSET);
        }
        return parent::_prepareLayout();
    }

    /**
     * @inheritdoc
     */
    protected function _toHtml()
    {
        if (!$this->_authorization->isAllowed(RecommendationsCard::ACL_RESOURCE)) {
            return '';
        }
        if ($this->actions() === []) {
            return '';
        }
        return parent::_toHtml();
    }

    /**
     * Latest actions (cached)
     *
     * @return array[]
     */
    private function actions(): array
    {
        if ($this->actions === null) {
            $this->actions = $this->dataSource->recentActions(self::SCAN_LIMIT);
        }
        return $this->actions;
    }
}
