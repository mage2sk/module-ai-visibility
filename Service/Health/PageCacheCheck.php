<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Health;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\HealthResult;

/**
 * With Varnish, cached pages never reach Magento, so crawler hits on them are only
 * counted when the store's access log is imported.
 */
class PageCacheCheck extends AbstractCheck
{
    public const CODE = 'page_cache';

    public const XML_PATH_CACHING_APPLICATION = 'system/full_page_cache/caching_application';

    /**
     * Magento\PageCache\Model\Config::VARNISH (not referenced to keep the check free of that module)
     */
    public const VARNISH = 2;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    /**
     * @inheritdoc
     */
    public function code(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    protected function execute(): HealthResult
    {
        $application = (int)$this->scopeConfig->getValue(self::XML_PATH_CACHING_APPLICATION);
        if ($application !== self::VARNISH) {
            return new HealthResult(
                HealthResult::STATUS_OK,
                (string)__(
                    'Magento\'s built-in page cache is used, so AI crawler hits on cached pages are counted. '
                    . 'If a CDN caches whole pages in front of Magento, enable Access Log Import for the CDN logs.'
                ),
                ['caching_application' => $application]
            );
        }

        $uncovered = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $storeId = (int)$store->getId();
            if (!$store->getIsActive()) {
                continue;
            }
            if ($this->config->crawlerSource($storeId) !== Config::CRAWLER_SOURCE_LOG) {
                $uncovered[] = (string)$store->getCode();
            }
        }
        if ($uncovered === []) {
            return new HealthResult(
                HealthResult::STATUS_OK,
                (string)__('Varnish is used and every store view is covered by Access Log Import.'),
                ['caching_application' => $application]
            );
        }
        return new HealthResult(
            HealthResult::STATUS_WARN,
            (string)__(
                'Varnish answers cached pages before Magento runs, so most AI crawler hits are missed for: %1. '
                . 'Fix: in Stores > Configuration > Panth Infotech > AI Visibility > Access Log Import (Varnish / CDN), '
                . 'set "Import Crawler Hits from Access Logs" to Yes and add '
                . 'a line "/path/to/varnish-or-web-server/access.log | store_code" for each of these store views.',
                implode(', ', $uncovered)
            ),
            ['caching_application' => $application, 'uncovered_stores' => $uncovered]
        );
    }
}
