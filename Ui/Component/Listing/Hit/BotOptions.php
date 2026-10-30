<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Ui\Component\Listing\Hit;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\AiVisibility\Service\BotNames;

/**
 * Bot filter options of the Crawler Log (built-in and confirmed bots).
 */
class BotOptions implements OptionSourceInterface
{
    /**
     * @param BotNames $botNames
     */
    public function __construct(
        private readonly BotNames $botNames
    ) {
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->botNames->all() as $code => $name) {
            $options[] = ['value' => (string)$code, 'label' => $name];
        }
        return $options;
    }
}
