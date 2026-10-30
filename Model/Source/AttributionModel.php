<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Which AI touch gets the credit for an order.
 */
class AttributionModel implements OptionSourceInterface
{
    public const FIRST = 'first';
    public const LAST = 'last';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::FIRST, 'label' => __('First touch')],
            ['value' => self::LAST, 'label' => __('Last touch')],
        ];
    }
}
