<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Block\Adminhtml;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;
use Panth\AiVisibility\Block\Adminhtml\ExportLinks;
use Panth\AiVisibility\Service\Export\ReportExporter;
use PHPUnit\Framework\TestCase;

class ExportLinksTest extends TestCase
{
    /**
     * getUrl() calls: [route, params]
     *
     * @var array<int, array>
     */
    private array $urls = [];

    private function block(array $params, array $denied = [], ?bool $secretKeys = null): ExportLinks
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => $params[$key] ?? $default);
        if ($secretKeys === null) {
            $urlBuilder = $this->createStub(UrlInterface::class);
        } else {
            $urlBuilder = $this->createStub(BackendUrlInterface::class);
            $urlBuilder->method('useSecretKey')->willReturn($secretKeys);
        }
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn ($resource): bool => !in_array($resource, $denied, true)
        );
        $urlBuilder->method('getUrl')->willReturnCallback(function ($route, $routeParams) {
            $this->urls[] = [$route, $routeParams];
            return 'https://shop.test/admin/' . $route . '?' . http_build_query($routeParams['_query']);
        });
        $formKey = $this->createStub(FormKey::class);
        $formKey->method('getFormKey')->willReturn('fk123');

        $block = (new \ReflectionClass(ExportLinks::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($request, $urlBuilder, $formKey, $authorization): void {
            $this->_request = $request;
            $this->_authorization = $authorization;
            $this->_urlBuilder = $urlBuilder;
            $this->formKey = $formKey;
        }, $block, ExportLinks::class)();
        return $block;
    }

    public function testOneLinkPerReportWithFiltersAndFormKey(): void
    {
        $links = $this->block([
            'period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30', 'store' => '2', 'sim' => '1',
        ])->getLinks();

        $this->assertSame(ReportExporter::REPORTS, array_column($links, 'report'));
        foreach ($this->urls as $index => [$route, $params]) {
            $this->assertSame('panth_aivisibility/dashboard/export', $route);
            $this->assertSame([
                'report' => ReportExporter::REPORTS[$index],
                'period' => 'custom',
                'from' => '2026-09-01',
                'to' => '2026-09-30',
                'store' => 2,
                'sim' => 1,
                'form_key' => 'fk123',
            ], $params['_query']);
        }
        $this->assertNotSame('', $links[0]['label']);
    }

    public function testPresetPeriodHasNoDatesAndDefaultsAreLeftOut(): void
    {
        $this->block(['period' => '7', 'from' => '2026-09-01', 'store' => '0', 'sim' => '0'])->getLinks();
        $this->assertSame(
            ['report' => 'daily', 'period' => '7', 'form_key' => 'fk123'],
            $this->urls[0][1]['_query']
        );
    }

    public function testSecretKeyUrlsCarryNoFormKey(): void
    {
        $this->block(['period' => '7'], [], true)->getLinks();
        $this->assertSame(['report' => 'daily', 'period' => '7'], $this->urls[0][1]['_query']);

        $this->urls = [];
        $this->block(['period' => '7'], [], false)->getLinks();
        $this->assertSame(['report' => 'daily', 'period' => '7', 'form_key' => 'fk123'], $this->urls[0][1]['_query']);
    }

    public function testRecommendationReportsNeedTheRecommendationsPermission(): void
    {
        $links = $this->block(['period' => '7'], ['Panth_AiVisibility::recommendations'])->getLinks();
        $this->assertSame(['daily', 'pages', 'bots', 'sources'], array_column($links, 'report'));
    }

    public function testInvalidPeriodFallsBackToTheDashboardDefault(): void
    {
        $block = $this->block(['period' => 'forever']);
        $this->assertSame(['period' => '30'], $block->filterQuery());
        $this->assertFalse($block->includesSimulated());
    }

    public function testDashboardLayoutLoadsTheExportLinksHandle(): void
    {
        $dir = dirname(__DIR__, 4) . '/view/adminhtml/layout/';
        $dashboard = new \DOMDocument();
        $dashboard->load($dir . 'panth_aivisibility_dashboard_index.xml');
        $handles = [];
        foreach ((new \DOMXPath($dashboard))->query('/page/update') as $update) {
            $handles[] = $update->getAttribute('handle');
        }
        $this->assertSame(['panth_aivisibility_dashboard_export_links'], $handles);

        $links = new \DOMDocument();
        $links->load($dir . 'panth_aivisibility_dashboard_export_links.xml');
        $block = (new \DOMXPath($links))->query('//block[@name="panth_aiv.export_links"]')->item(0);
        $this->assertNotNull($block);
        $this->assertSame('Panth_AiVisibility::dashboard', $block->getAttribute('aclResource'));
    }
}
