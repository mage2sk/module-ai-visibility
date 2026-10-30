<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * ISO weekdays (1 = Monday ... 7 = Sunday) for the weekly summary email.
 *
 * Magento's own locale weekday source counts 0 = Sunday, so it is not used here.
 */
class Weekday implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 1, 'label' => __('Monday')],
            ['value' => 2, 'label' => __('Tuesday')],
            ['value' => 3, 'label' => __('Wednesday')],
            ['value' => 4, 'label' => __('Thursday')],
            ['value' => 5, 'label' => __('Friday')],
            ['value' => 6, 'label' => __('Saturday')],
            ['value' => 7, 'label' => __('Sunday')],
        ];
    }
}
