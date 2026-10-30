<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Panth\AiVisibility\Service\BotNames;
use Panth\AiVisibility\Service\Discovery\CandidateRepository;
use Psr\Log\LoggerInterface;

/**
 * Crawler Log "Bot" cell: readable bot name instead of the code; candidates show
 * "Unknown bot #ID" with the start of their User-Agent. Output is escaped HTML.
 */
class BotName extends Column
{
    public const UA_EXCERPT = 90;

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param BotNames $botNames
     * @param CandidateRepository $candidates
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly BotNames $botNames,
        private readonly CandidateRepository $candidates,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger,
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
        $field = (string)$this->getData('name');
        $candidateIds = [];
        foreach ($dataSource['data']['items'] as $item) {
            $id = $this->botNames->candidateId((string)($item[$field] ?? ''));
            if ($id !== null) {
                $candidateIds[] = $id;
            }
        }
        $userAgents = [];
        if ($candidateIds !== []) {
            try {
                $userAgents = $this->candidates->keyValues($candidateIds);
            } catch (\Throwable $e) {
                $this->logger->warning('[Panth_AiVisibility] candidate names could not be read: ' . $e->getMessage());
            }
        }
        foreach ($dataSource['data']['items'] as &$item) {
            $code = (string)($item[$field] ?? '');
            $html = $this->escaper->escapeHtml($this->botNames->nameFor($code));
            $id = $this->botNames->candidateId($code);
            if ($id !== null && isset($userAgents[$id])) {
                $ua = $userAgents[$id];
                if (mb_strlen($ua) > self::UA_EXCERPT) {
                    $ua = mb_substr($ua, 0, self::UA_EXCERPT - 3) . '...';
                }
                $html .= '<br/><small>' . $this->escaper->escapeHtml($ua) . '</small>';
            }
            $item[$field] = $html;
        }
        unset($item);
        return $dataSource;
    }
}
