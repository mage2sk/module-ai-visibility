<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\AiVisibility\ViewModel\Recommendation\DataSource;
use Panth\AiVisibility\ViewModel\Recommendation\Presenter;

/**
 * "Top recommendations" card on the dashboard (layout block panth_aiv.recommendations_card).
 *
 * Shows the three best open recommendations. Prints nothing when the admin may not open the
 * Recommendations page, or when no recommendation was ever made.
 */
class RecommendationsCard extends Template
{
    public const LIMIT = 3;
    public const ACL_RESOURCE = 'Panth_AiVisibility::recommendations';
    public const CSS_ASSET = 'Panth_AiVisibility::css/recommendations.css';

    /**
     * @var string
     */
    protected $_template = 'Panth_AiVisibility::recommendation/card.phtml';

    /**
     * @var array[]|null
     */
    private ?array $top = null;

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
     * Three best open recommendations (all store views)
     *
     * @return array[]
     */
    public function getTopRecommendations(): array
    {
        if ($this->top === null) {
            $this->top = $this->dataSource->openRecommendations(null, self::LIMIT);
        }
        return $this->top;
    }

    /**
     * Number of open recommendations
     *
     * @return int
     */
    public function getOpenCount(): int
    {
        return (int)($this->dataSource->counts()['open'] ?? 0);
    }

    /**
     * Whether any recommendation exists in any status
     *
     * @return bool
     */
    public function hasHistory(): bool
    {
        return array_sum($this->dataSource->counts()) > 0;
    }

    /**
     * URL of the Recommendations page, optionally at one recommendation
     *
     * @param int|null $recId
     * @return string
     */
    public function getPageUrl(?int $recId = null): string
    {
        $url = $this->getUrl('panth_aivisibility/recommendation/index');
        return $recId === null ? $url : $url . '#panth-aiv-rec-' . $recId;
    }

    /**
     * @inheritdoc
     */
    protected function _prepareLayout()
    {
        if ($this->pageConfig !== null) {
            $this->pageConfig->addPageAsset(self::CSS_ASSET);
        }
        return parent::_prepareLayout();
    }

    /**
     * @inheritdoc
     */
    protected function _toHtml()
    {
        if (!$this->_authorization->isAllowed(self::ACL_RESOURCE)) {
            return '';
        }
        if ($this->getTopRecommendations() === [] && !$this->hasHistory()) {
            return '';
        }
        return parent::_toHtml();
    }
}
