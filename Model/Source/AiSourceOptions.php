<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Psr\Log\LoggerInterface;

/**
 * Known AI sources (built-in, custom config, confirmed rules) for the order grid filter.
 *
 * Values are source codes, labels are display names, sorted by label.
 */
class AiSourceOptions implements OptionSourceInterface
{
    /**
     * @var array<int, array{value: string, label: string}>|null
     */
    private ?array $options = null;

    /**
     * @param SourceClassifierInterface $sourceClassifier
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SourceClassifierInterface $sourceClassifier,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }
        $options = [];
        try {
            foreach ($this->sourceClassifier->allSources() as $code => $name) {
                $code = (string)$code;
                if ($code === '') {
                    continue;
                }
                $name = trim((string)$name);
                $options[] = ['value' => $code, 'label' => $name !== '' ? $name : $code];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] AI source options unavailable: ' . $e->getMessage());
            $options = [];
        }
        usort(
            $options,
            static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label'])
                ?: strcmp($a['value'], $b['value'])
        );
        return $this->options = $options;
    }
}
