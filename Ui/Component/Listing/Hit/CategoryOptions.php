<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Ui\Component\Listing\Hit;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\AiVisibility\Model\Source\Category;

/**
 * Category filter options of the Crawler Log, including "unknown" for discovery candidates.
 */
class CategoryOptions implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Category::TRAINING, 'label' => __('Training')],
            ['value' => Category::SEARCH, 'label' => __('Search')],
            ['value' => Category::LIVE, 'label' => __('Live answer')],
            ['value' => Category::OTHER, 'label' => __('Other')],
            ['value' => Category::UNKNOWN, 'label' => __('Unknown (discovery candidate)')],
        ];
    }
}
