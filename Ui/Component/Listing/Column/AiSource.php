<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Panth\AiVisibility\Api\SourceClassifierInterface;

/**
 * Order grid "AI Source" column: turns the stored source code into its display name.
 *
 * The cell is rendered with the default text cell template, which escapes the value.
 */
class AiSource extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param SourceClassifierInterface $sourceClassifier
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly SourceClassifierInterface $sourceClassifier,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritdoc
     */
    public function prepareDataSource(array $dataSource)
    {
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return $dataSource;
        }
        $field = (string)($this->getData('name') ?: 'ai_source');
        $names = [];
        foreach ($dataSource['data']['items'] as &$item) {
            $code = isset($item[$field]) && is_scalar($item[$field]) ? trim((string)$item[$field]) : '';
            if ($code === '') {
                $item[$field] = '';
                continue;
            }
            if (!isset($names[$code])) {
                $names[$code] = $this->label($code);
            }
            $item[$field] = $names[$code];
        }
        unset($item);
        return $dataSource;
    }

    /**
     * Display name for a source code; the code itself when the name cannot be resolved
     *
     * @param string $code
     * @return string
     */
    private function label(string $code): string
    {
        try {
            $name = trim($this->sourceClassifier->nameFor($code));
        } catch (\Throwable $e) {
            return $code;
        }
        return $name !== '' ? $name : $code;
    }
}
