<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\ObjectManager as AppObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Escaper;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\ViewModel\Recommendation\BeforeAfterBars;
use Panth\AiVisibility\ViewModel\Recommendation\DataSource;
use Panth\AiVisibility\ViewModel\Recommendation\FixLinkResolver;
use Panth\AiVisibility\ViewModel\Recommendation\Presenter;
use Psr\Log\LoggerInterface;

/**
 * Real presenter / data source / bars over stubbed repositories, a stubbed block context and a real
 * escaper; renders module templates the way the PHP template engine does.
 */
trait RecommendationFixtureTrait
{
    /** @var array[] */
    private array $openRows = [];

    /** @var array<string, int> */
    private array $countRows = ['open' => 0, 'applied' => 0, 'dismissed' => 0, 'resolved' => 0];

    /** @var array[] */
    private array $actionRows = [];

    /** @var array<int, array> */
    private array $recById = [];

    private bool $repositoryFails = false;

    private array $requestParams = [];

    private bool $aclAllowed = true;

    private array $aclAsked = [];

    private array $enabledModules = ['Panth_AiVisibility', 'Magento_Catalog', 'Panth_RobotsSeo', 'Panth_LlmsTxt'];

    private array $pageAssets = [];

    private array $loggedWarnings = [];

    private bool $recommendationsEnabled = true;

    private function escaper(): Escaper
    {
        $escaper = new Escaper();
        $ref = new \ReflectionProperty(Escaper::class, 'escaper');
        $ref->setAccessible(true);
        $ref->setValue($escaper, new \Magento\Framework\ZendEscaper());
        // Without this, escapeHtmlAttr() asks the global object manager for the inline translator, so the
        // result would depend on which test ran before.
        $inline = $this->createStub(\Magento\Framework\Translate\InlineInterface::class);
        $inline->method('isAllowed')->willReturn(false);
        $translate = new \ReflectionProperty(Escaper::class, 'translateInline');
        $translate->setAccessible(true);
        $translate->setValue($escaper, $inline);
        return $escaper;
    }

    private function presenter(): Presenter
    {
        return new Presenter();
    }

    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->loggedWarnings[] = (string)$message;
        });
        return $logger;
    }

    private function dataSource(): DataSource
    {
        $repository = $this->createStub(RecommendationRepositoryInterface::class);
        $repository->method('listOpen')->willReturnCallback(function (?int $storeId, int $limit): array {
            if ($this->repositoryFails) {
                throw new \RuntimeException('database gone');
            }
            $rows = array_values(array_filter(
                $this->openRows,
                static fn (array $r): bool => $storeId === null || (int)($r['store_id'] ?? 0) === $storeId
            ));
            return array_slice($rows, 0, $limit);
        });
        $repository->method('counts')->willReturnCallback(function (): array {
            if ($this->repositoryFails) {
                throw new \RuntimeException('database gone');
            }
            return $this->countRows;
        });
        $repository->method('get')->willReturnCallback(fn (int $id): ?array => $this->recById[$id] ?? null);

        $actionLog = $this->createStub(ActionLogInterface::class);
        $actionLog->method('listRecent')->willReturnCallback(function (int $limit): array {
            if ($this->repositoryFails) {
                throw new \RuntimeException('database gone');
            }
            return array_slice($this->actionRows, 0, $limit);
        });

        return new DataSource($repository, $actionLog, $this->presenter(), $this->logger());
    }

    private function urlBuilder(): BackendUrlInterface
    {
        $url = $this->createStub(BackendUrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static function ($route = null, $params = []): string {
            $query = [];
            foreach ((array)$params as $key => $value) {
                if (is_scalar($value)) {
                    $query[] = $key . '/' . $value;
                }
            }
            return 'https://shop.test/admin/' . $route . ($query ? '/' . implode('/', $query) : '');
        });
        return $url;
    }

    private function authorization(): AuthorizationInterface
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(function ($resource): bool {
            $this->aclAsked[] = $resource;
            return $this->aclAllowed;
        });
        return $authorization;
    }

    private function fixLinks(): FixLinkResolver
    {
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturnCallback(fn ($name): bool => in_array($name, $this->enabledModules, true));
        return new FixLinkResolver($modules, $this->urlBuilder(), $this->authorization());
    }

    private function timezone(): TimezoneInterface
    {
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('formatDateTime')->willReturnCallback(
            static fn ($date, $dateType = null, $timeType = null) => $timeType === \IntlDateFormatter::NONE
                ? 'day ' . $date->format('Y-m-d')
                : 'at ' . $date->format('Y-m-d H:i')
        );
        return $timezone;
    }

    private function moduleConfig(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('recommendationsEnabled')->willReturnCallback(fn (): bool => $this->recommendationsEnabled);
        $config->method('recommendationsMaxOpen')->willReturn(50);
        $config->method('verifyWindowDays')->willReturn(14);
        return $config;
    }

    private function blockContext(): Context
    {
        // Magento\Backend\Block\Template pulls its JSON / directory helpers from the object manager.
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn ($type) => $this->createStub($type));
        AppObjectManager::setInstance($objectManager);

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn ($key, $default = null) => $this->requestParams[$key] ?? $default
        );

        $stores = [];
        foreach ([1 => 'Main View', 2 => 'Second <b>View</b>'] as $id => $name) {
            $website = new \Magento\Framework\DataObject(['name' => 'Web']);
            $stores[] = new \Magento\Framework\DataObject(['id' => $id, 'name' => $name, 'website' => $website]);
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('addPageAsset')->willReturnCallback(function ($asset) use ($pageConfig) {
            $this->pageAssets[] = $asset;
            return $pageConfig;
        });

        $formKey = $this->createStub(FormKey::class);
        $formKey->method('getFormKey')->willReturn('fk"<x>');

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($this->urlBuilder());
        $context->method('getAuthorization')->willReturn($this->authorization());
        $context->method('getStoreManager')->willReturn($storeManager);
        $context->method('getPageConfig')->willReturn($pageConfig);
        $context->method('getFormKey')->willReturn($formKey);
        $context->method('getEscaper')->willReturn($this->escaper());
        return $context;
    }

    private function bars(): BeforeAfterBars
    {
        return new BeforeAfterBars($this->escaper(), $this->presenter());
    }

    /**
     * Render a module template with $block and $escaper, like the PHP template engine
     */
    private function renderTemplate(object $block, string $template): string
    {
        $file = dirname(__DIR__, 4) . '/view/adminhtml/templates/' . $template;
        $this->assertFileExists($file);
        $escaper = $this->escaper();
        $render = function (string $__file, Escaper $escaper): string {
            $block = $this;
            ob_start();
            try {
                include $__file;
            } finally {
                $html = (string)ob_get_clean();
            }
            return $html;
        };
        return \Closure::bind($render, $block, get_class($block))($file, $escaper);
    }

    private function callProtected(object $object, string $method)
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invoke($object);
    }

    private static function hostileRecommendation(int $id = 7, array $overrides = []): array
    {
        return array_merge([
            'rec_id' => $id,
            'rule_code' => 'bot_404',
            'store_id' => 1,
            'subject_type' => 'path',
            'subject_ref' => '/old',
            'title' => '<script>alert("t")</script> broken pages',
            'evidence' => json_encode([
                'hits_404' => 123,
                'note' => '<img src=x onerror=alert(1)>',
                'top_paths' => [
                    ['path' => '/a"><svg onload=alert(2)>', 'hits' => 40, 'target' => '/b'],
                    ['path' => '/c', 'hits' => 2],
                ],
                'subjects' => ['/a', '/c'],
            ]),
            'affected_count' => 1234,
            'impact_score' => 87,
            'metric' => 'bot_404',
            'status' => 'open',
            'feedback' => null,
            'is_simulated' => 0,
        ], $overrides);
    }

    private static function action(int $id, string $status, ?array $impact, array $overrides = []): array
    {
        return array_merge([
            'action_id' => $id,
            'rec_id' => null,
            'rule_code' => 'never_crawled_products',
            'store_id' => 1,
            'metric' => 'bot_hits',
            'subjects' => json_encode(['11', '12', '13']),
            'note' => null,
            'applied_by' => 'admin',
            'applied_at' => '2026-10-01 09:30:00',
            'undone_at' => null,
            'verify_after' => '2026-10-16',
            'status' => $status,
            'is_simulated' => 0,
            'impact' => $impact,
        ], $overrides);
    }

    private static function impact(string $verdict, ?float $effect, ?float $low, ?float $high): array
    {
        return [
            'window_days' => 14,
            'method' => 'did',
            'treat_before' => '100.0000',
            'treat_after' => '138.0000',
            'control_before' => '200.0000',
            'control_after' => '200.0000',
            'effect' => $effect,
            'ci_low' => $low,
            'ci_high' => $high,
            'events' => 538,
            'subjects_treated' => 3,
            'subjects_control' => 12,
            'verdict' => $verdict,
        ];
    }
}
