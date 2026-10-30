<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * AI bot categories. "unknown" is only used for discovery candidates and is not selectable.
 */
class Category implements OptionSourceInterface
{
    public const TRAINING = 'training';
    public const SEARCH = 'search';
    public const LIVE = 'live';
    public const OTHER = 'other';
    public const UNKNOWN = 'unknown';

    /**
     * Categories a known bot can have
     */
    public const BOT_CATEGORIES = [self::TRAINING, self::SEARCH, self::LIVE, self::OTHER];

    /**
     * Check whether a value is a selectable bot category
     *
     * @param string $category
     * @return bool
     */
    public static function isValid(string $category): bool
    {
        return in_array($category, self::BOT_CATEGORIES, true);
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::TRAINING, 'label' => __('Training (collects content for AI models)')],
            ['value' => self::SEARCH, 'label' => __('Search (builds an AI search index)')],
            ['value' => self::LIVE, 'label' => __('Live answer (fetches a page for a user question)')],
            ['value' => self::OTHER, 'label' => __('Other')],
        ];
    }
}
