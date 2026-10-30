<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\ViewModel\Recommendation;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Panth\AiVisibility\Model\Finding;

/**
 * "How to fix" link of a recommendation: the admin page where the merchant makes the change.
 *
 * Links only to pages that exist on this store (module enabled, controller present) and that the
 * current admin user may open. Returns null when there is no such page.
 */
class FixLinkResolver
{
    /**
     * Admin pages: key => [module, controller class, route, ACL resource, label]
     */
    private const TARGETS = [
        'robots_config' => [
            'Panth_RobotsSeo',
            null,
            'adminhtml/system_config/edit/section/panth_robots_seo',
            'Panth_RobotsSeo::config',
            'Open the Robots SEO settings',
        ],
        'redirects' => [
            'Panth_Redirects',
            'Panth\Redirects\Controller\Adminhtml\Redirect\Index',
            'panth_redirects/redirect/index',
            'Panth_Redirects::redirects',
            'Open Manage Redirects',
        ],
        'url_rewrites' => [
            'Magento_UrlRewrite',
            'Magento\UrlRewrite\Controller\Adminhtml\Url\Rewrite\Index',
            'adminhtml/url_rewrite/index',
            'Magento_UrlRewrite::urlrewrite',
            'Open URL Rewrites',
        ],
        'crawler_log' => [
            'Panth_AiVisibility',
            'Panth\AiVisibility\Controller\Adminhtml\Log\Index',
            'panth_aivisibility/log/index',
            'Panth_AiVisibility::log',
            'Open the Crawler Log',
        ],
        'llms_config' => [
            'Panth_LlmsTxt',
            null,
            'adminhtml/system_config/edit/section/panth_llms_txt',
            'Panth_LlmsTxt::config',
            'Open the LLMs.txt settings',
        ],
        'discovery' => [
            'Panth_AiVisibility',
            'Panth\AiVisibility\Controller\Adminhtml\Discovery\Index',
            'panth_aivisibility/discovery/index',
            'Panth_AiVisibility::discovery',
            'Open Discovery',
        ],
        'product_edit' => [
            'Magento_Catalog',
            'Magento\Catalog\Controller\Adminhtml\Product\Edit',
            'catalog/product/edit',
            'Magento_Catalog::products',
            'Edit product ID %1',
        ],
    ];

    /**
     * Rule code => admin pages to try, first available wins
     */
    private const RULE_TARGETS = [
        'robots_blocks_ai_search' => ['robots_config'],
        'parameter_crawl_waste' => ['robots_config'],
        'bot_404' => ['redirects', 'url_rewrites'],
        'bot_errors_or_slow' => ['crawler_log'],
        'llms_not_served' => ['llms_config'],
        'discovery_backlog' => ['discovery'],
        'never_crawled_products' => ['product_edit'],
        'thin_content_ai_read' => ['product_edit'],
        'ai_visitors_out_of_stock' => ['product_edit'],
    ];

    /**
     * @var array<string, bool>
     */
    private array $available = [];

    /**
     * @param ModuleManager $moduleManager
     * @param UrlInterface $urlBuilder
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    /**
     * Link for a recommendation row: ['url' => ..., 'label' => ...] or null
     *
     * @param array $recommendation Row with rule_code, subject_type, subject_ref and decoded evidence
     * @return array{url: string, label: string}|null
     */
    public function resolve(array $recommendation): ?array
    {
        $ruleCode = (string)($recommendation['rule_code'] ?? '');
        foreach (self::RULE_TARGETS[$ruleCode] ?? [] as $key) {
            if (!$this->isAvailable($key)) {
                continue;
            }
            [, , $route, , $label] = self::TARGETS[$key];
            if ($key === 'product_edit') {
                $productId = $this->firstProductId($recommendation);
                if ($productId === null) {
                    continue;
                }
                return [
                    'url' => $this->urlBuilder->getUrl($route, ['id' => $productId]),
                    'label' => (string)__($label, $productId),
                ];
            }
            return ['url' => $this->urlBuilder->getUrl($route), 'label' => (string)__($label)];
        }
        return null;
    }

    /**
     * Whether an admin page exists here and the current user may open it
     *
     * @param string $key
     * @return bool
     */
    private function isAvailable(string $key): bool
    {
        if (!isset($this->available[$key])) {
            [$module, $controller, , $resource] = self::TARGETS[$key];
            try {
                $this->available[$key] = $this->moduleManager->isEnabled($module)
                    && ($controller === null || class_exists($controller))
                    && $this->authorization->isAllowed($resource);
            } catch (\Throwable $e) {
                $this->available[$key] = false;
            }
        }
        return $this->available[$key];
    }

    /**
     * First numeric product ID of a product recommendation
     *
     * @param array $recommendation
     * @return int|null
     */
    private function firstProductId(array $recommendation): ?int
    {
        if ((string)($recommendation['subject_type'] ?? '') !== Finding::SUBJECT_PRODUCT) {
            return null;
        }
        $evidence = is_array($recommendation['evidence'] ?? null) ? $recommendation['evidence'] : [];
        $candidates = $evidence['subjects'] ?? [];
        $candidates = is_array($candidates) ? array_values($candidates) : [];
        $candidates[] = $recommendation['subject_ref'] ?? '';
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && ctype_digit((string)$candidate) && (int)$candidate > 0) {
                return (int)$candidate;
            }
        }
        return null;
    }
}
