<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Ui\Component\Listing\Hit;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\AiVisibility\Api\HitWriterInterface;

/**
 * Origin filter options of the Crawler Log.
 */
class OriginOptions implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => HitWriterInterface::ORIGIN_PHP, 'label' => __('Magento (live request)')],
            ['value' => HitWriterInterface::ORIGIN_LOG, 'label' => __('Access log import')],
            ['value' => HitWriterInterface::ORIGIN_SIM, 'label' => __('Simulator (test data)')],
        ];
    }
}
