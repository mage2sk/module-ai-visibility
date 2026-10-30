<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Discovery\CandidateClassifier;
use Panth\AiVisibility\Service\Discovery\CandidateRepository;
use Psr\Log\LoggerInterface;

/**
 * Discovery page: candidate list with per-row decision forms, and the confirmed rules.
 */
class Discovery extends Template
{
    public const LIST_LIMIT = 200;

    /**
     * @var string
     */
    protected $_template = 'Panth_AiVisibility::discovery/index.phtml';

    /**
     * @param Context $context
     * @param CandidateRepository $candidates
     * @param CandidateClassifier $classifier
     * @param RuleProvider $ruleProvider
     * @param Category $categorySource
     * @param Config $config
     * @param TimezoneInterface $timezone
     * @param LoggerInterface $logger
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly CandidateRepository $candidates,
        private readonly CandidateClassifier $classifier,
        private readonly RuleProvider $ruleProvider,
        private readonly Category $categorySource,
        private readonly Config $config,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Status tab being shown (new | not_ai | ignored)
     *
     * @return string
     */
    public function getCurrentStatus(): string
    {
        $status = $this->getRequest()->getParam('status', CandidateRepository::STATUS_NEW);
        $status = is_scalar($status) ? (string)$status : CandidateRepository::STATUS_NEW;
        return in_array($status, $this->getTabStatuses(), true) ? $status : CandidateRepository::STATUS_NEW;
    }

    /**
     * Statuses that have a tab
     *
     * @return string[]
     */
    public function getTabStatuses(): array
    {
        return [CandidateRepository::STATUS_NEW, CandidateRepository::STATUS_NOT_AI, CandidateRepository::STATUS_IGNORED];
    }

    /**
     * Tab label of a status
     *
     * @param string $status
     * @return string
     */
    public function getStatusLabel(string $status): string
    {
        switch ($status) {
            case CandidateRepository::STATUS_NOT_AI:
                return (string)__('Not AI');
            case CandidateRepository::STATUS_IGNORED:
                return (string)__('Ignored');
            case CandidateRepository::STATUS_CONFIRMED:
                return (string)__('Confirmed');
            default:
                return (string)__('New');
        }
    }

    /**
     * Candidate counts per status
     *
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        try {
            return $this->candidates->countByStatus();
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] candidate counts could not be read: ' . $e->getMessage());
            return array_fill_keys(CandidateRepository::STATUSES, 0);
        }
    }

    /**
     * Candidates of the current tab, split by type: ['bot' => [...], 'source' => [...]]
     *
     * @return array{bot: array, source: array}
     */
    public function getCandidates(): array
    {
        $result = [RuleProvider::TYPE_BOT => [], RuleProvider::TYPE_SOURCE => []];
        try {
            $rows = $this->candidates->getList($this->getCurrentStatus(), self::LIST_LIMIT);
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] candidates could not be read: ' . $e->getMessage());
            return $result;
        }
        foreach ($rows as $row) {
            $type = (string)$row['type'];
            if (isset($result[$type])) {
                $result[$type][] = $row;
            }
        }
        return $result;
    }

    /**
     * Confirmed rules: ['bot' => [...], 'source' => [...]]
     *
     * @return array{bot: array, source: array}
     */
    public function getRules(): array
    {
        return [
            RuleProvider::TYPE_BOT => $this->ruleProvider->botRules(),
            RuleProvider::TYPE_SOURCE => $this->ruleProvider->sourceRules(),
        ];
    }

    /**
     * Whether discovery is switched on in the configuration
     *
     * @return bool
     */
    public function isDiscoveryEnabled(): bool
    {
        return $this->config->discoveryEnabled();
    }

    /**
     * Bot category options for the confirm buttons: value => label
     *
     * @return array<string, string>
     */
    public function getCategoryOptions(): array
    {
        $options = [];
        foreach ($this->categorySource->toOptionArray() as $option) {
            $options[(string)$option['value']] = (string)$option['label'];
        }
        return $options;
    }

    /**
     * Short category button label
     *
     * @param string $category
     * @return string
     */
    public function getCategoryButtonLabel(string $category): string
    {
        switch ($category) {
            case Category::TRAINING:
                return (string)__('Training bot');
            case Category::SEARCH:
                return (string)__('Search bot');
            case Category::LIVE:
                return (string)__('Live-answer bot');
            default:
                return (string)__('Other AI bot');
        }
    }

    /**
     * Suggested User-Agent token of a bot candidate
     *
     * @param array $candidate
     * @return string
     */
    public function getSuggestedToken(array $candidate): string
    {
        return $this->classifier->suggestToken((string)($candidate['key_value'] ?? ''));
    }

    /**
     * Suggested display name of a candidate
     *
     * @param array $candidate
     * @return string
     */
    public function getSuggestedName(array $candidate): string
    {
        if ((string)($candidate['type'] ?? '') === RuleProvider::TYPE_BOT) {
            return $this->getSuggestedToken($candidate);
        }
        return $this->classifier->displayValue($candidate);
    }

    /**
     * Source candidate key split into a label and value: ['Referrer host', 'chat.example']
     *
     * @param array $candidate
     * @return array{0: string, 1: string}
     */
    public function getSourceParts(array $candidate): array
    {
        $key = (string)($candidate['key_value'] ?? '');
        if (str_starts_with($key, 'host:')) {
            return [(string)__('Referrer host'), substr($key, 5)];
        }
        if (str_starts_with($key, 'utm:')) {
            return [(string)__('utm_source'), substr($key, 4)];
        }
        return [(string)__('Value'), $key];
    }

    /**
     * UTC 'Y-m-d H:i:s' as admin-locale date and time
     *
     * @param string|null $utc
     * @return string
     */
    public function formatUtcDate(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        try {
            return $this->timezone->formatDateTime(
                new \DateTime($utc, new \DateTimeZone('UTC')),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            );
        } catch (\Throwable $e) {
            return $utc . ' UTC';
        }
    }

    /**
     * URL of the classify POST action
     *
     * @return string
     */
    public function getClassifyUrl(): string
    {
        return $this->getUrl('panth_aivisibility/discovery/classify');
    }

    /**
     * URL of the delete-rule POST action
     *
     * @return string
     */
    public function getDeleteRuleUrl(): string
    {
        return $this->getUrl('panth_aivisibility/discovery/deleteRule');
    }

    /**
     * URL of a status tab
     *
     * @param string $status
     * @return string
     */
    public function getTabUrl(string $status): string
    {
        return $status === CandidateRepository::STATUS_NEW
            ? $this->getUrl('panth_aivisibility/discovery/index')
            : $this->getUrl('panth_aivisibility/discovery/index', ['status' => $status]);
    }

    /**
     * URL of the configuration section
     *
     * @return string
     */
    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => Config::SECTION]);
    }
}
