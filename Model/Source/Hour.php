<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Hours of the day in UTC (0-23) for the weekly summary email.
 */
class Hour implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $options[] = ['value' => $hour, 'label' => __('%1 UTC', sprintf('%02d:00', $hour))];
        }
        return $options;
    }
}
