<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Controller\Adminhtml\Dashboard\Export;
use Panth\AiVisibility\Service\Export\CsvWriter;
use Panth\AiVisibility\Service\Export\ReportExporter;
use Panth\AiVisibility\Service\Report\DateRange;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExportTest extends TestCase
{
    private array $params = [];

    private bool $formKeyValid = true;

    private bool $secretKeys = false;

    private array $errors = [];

    private ?string $redirectPath = null;

    private array $headers = [];

    private ?string $contents = null;

    private array $aclChecks = [];

    private array $deniedResources = [];

    /**
     * export() calls: [report, from, to, store, sim]
     *
     * @var array<int, array>
     */
    private array $exports = [];

    private ?\Throwable $exportFailure = null;

    private int $warnings = 0;

    protected function setUp(): void
    {
        $this->params = [];
        $this->formKeyValid = true;
        $this->secretKeys = false;
        $this->errors = [];
        $this->redirectPath = null;
        $this->headers = [];
        $this->contents = null;
        $this->aclChecks = [];
        $this->deniedResources = [];
        $this->exports = [];
        $this->exportFailure = null;
        $this->warnings = 0;
    }

    private function controller(): Export
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn ($key, $default = null) => $this->params[$key] ?? $default
        );
        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->errors[] = (string)$message;
            return $messages;
        });
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path) use ($redirect) {
            $this->redirectPath = $path;
            return $redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(function ($resource): bool {
            $this->aclChecks[] = $resource;
            return !in_array($resource, $this->deniedResources, true);
        });
        $validator = $this->createStub(Validator::class);
        $validator->method('validate')->willReturnCallback(fn () => $this->formKeyValid);
        $backendUrl = $this->createStub(BackendUrl::class);
        $backendUrl->method('useSecretKey')->willReturnCallback(fn () => $this->secretKeys);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getAuthorization')->willReturn($authorization);
        $context->method('getFormKeyValidator')->willReturn($validator);
        $context->method('getBackendUrl')->willReturn($backendUrl);

        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use ($raw) {
            $this->headers[$name] = $value;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function ($contents) use ($raw) {
            $this->contents = $contents;
            return $raw;
        });
        $raw->method('setHttpResponseCode')->willReturn($raw);
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $exporter = $this->createStub(ReportExporter::class);
        $exporter->method('export')->willReturnCallback(
            function (string $report, DateRange $range, int $store, bool $sim): array {
                $this->exports[] = [$report, $range->from(), $range->to(), $store, $sim];
                if ($this->exportFailure !== null) {
                    throw $this->exportFailure;
                }
                return ['headers' => ['path', 'hits'], 'rows' => [['=1+1', 3], ['/a,b', 2]]];
            }
        );

        $stores = [];
        foreach ([1, 2] as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $stores[$id] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function () {
            $this->warnings++;
        });

        return new Export($context, $rawFactory, $exporter, new CsvWriter(), $storeManager, $logger);
    }

    public function testAclResourceAndGetOnly(): void
    {
        $this->assertSame('Panth_AiVisibility::dashboard', Export::ADMIN_RESOURCE);
        $this->assertTrue(is_subclass_of(Export::class, HttpGetActionInterface::class));
        $this->assertFalse(is_subclass_of(Export::class, HttpPostActionInterface::class));
        $controller = $this->controller();
        $method = new \ReflectionMethod($controller, '_isAllowed');
        $this->assertTrue($method->invoke($controller));
        $this->assertSame(['Panth_AiVisibility::dashboard'], $this->aclChecks);
    }

    public function testSendsEscapedCsvWithDownloadHeaders(): void
    {
        $this->params = ['report' => 'pages', 'period' => 'custom', 'from' => '2026-01-05', 'to' => '2026-01-20'];
        $result = $this->controller()->execute();

        $this->assertInstanceOf(Raw::class, $result);
        $this->assertSame(CsvWriter::BOM . "path,hits\r\n'=1+1,3\r\n\"/a,b\",2\r\n", $this->contents);
        $this->assertSame('text/csv; charset=UTF-8', $this->headers['Content-Type']);
        $this->assertSame(
            'attachment; filename="panth-ai-visibility-pages-2026-01-05-2026-01-20.csv"',
            $this->headers['Content-Disposition']
        );
        $this->assertSame((string)strlen($this->contents), $this->headers['Content-Length']);
        $this->assertStringContainsString('no-store', $this->headers['Cache-Control']);
        $this->assertSame('nosniff', $this->headers['X-Content-Type-Options']);
        $this->assertSame([['pages', '2026-01-05', '2026-01-20', 0, false]], $this->exports);
        $this->assertSame([], $this->errors);
    }

    public function testPassesStoreAndSimulationFilters(): void
    {
        $this->params = ['report' => 'daily', 'period' => '7', 'store' => '2', 'sim' => '1', 'bom' => '0'];
        $this->controller()->execute();
        $this->assertSame('daily', $this->exports[0][0]);
        $this->assertSame(2, $this->exports[0][3]);
        $this->assertTrue($this->exports[0][4]);
        $this->assertStringStartsWith('path,hits', (string)$this->contents);
    }

    public function testUnknownStoreFallsBackToAllStores(): void
    {
        $this->params = ['report' => 'bots', 'store' => '99', 'sim' => 'yes'];
        $this->controller()->execute();
        $this->assertSame(0, $this->exports[0][3]);
        $this->assertFalse($this->exports[0][4]);
    }

    /**
     * @dataProvider badReportProvider
     * @param mixed $report
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badReportProvider')]
    public function testUnknownReportRedirectsWithError($report): void
    {
        $this->params = ['report' => $report];
        $this->controller()->execute();
        $this->assertSame('panth_aivisibility/dashboard/index', $this->redirectPath);
        $this->assertCount(1, $this->errors);
        $this->assertSame([], $this->exports);
        $this->assertNull($this->contents);
    }

    /**
     * @return array<string, array>
     */
    public static function badReportProvider(): array
    {
        return [
            'missing' => [null],
            'unknown' => ['orders'],
            'array' => [['daily']],
            'path trick' => ['../daily'],
        ];
    }

    public function testInvalidFormKeyIsRejectedWhenSecretKeysAreOff(): void
    {
        $this->formKeyValid = false;
        $this->params = ['report' => 'daily'];
        $this->controller()->execute();
        $this->assertSame('panth_aivisibility/dashboard/index', $this->redirectPath);
        $this->assertStringContainsString('form key', $this->errors[0]);
        $this->assertSame([], $this->exports);
    }

    public function testSecretKeyUrlNeedsNoFormKey(): void
    {
        // With secret keys on, the backend validates the URL key before execute() runs.
        $this->secretKeys = true;
        $this->formKeyValid = false;
        $this->params = ['report' => 'verdicts'];
        $this->controller()->execute();
        $this->assertSame([['verdicts', $this->exports[0][1], $this->exports[0][2], 0, false]], $this->exports);
        $this->assertNotNull($this->contents);
    }

    public function testExportFailureIsLoggedAndRedirects(): void
    {
        $this->exportFailure = new \RuntimeException('Table missing');
        $this->params = ['report' => 'recommendations'];
        $this->controller()->execute();
        $this->assertSame(1, $this->warnings);
        $this->assertSame('panth_aivisibility/dashboard/index', $this->redirectPath);
        $this->assertStringNotContainsString('Table missing', $this->errors[0]);
        $this->assertNull($this->contents);
    }

    /**
     * @dataProvider recommendationReportProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('recommendationReportProvider')]
    public function testRecommendationReportsNeedTheRecommendationsPermission(string $report): void
    {
        $this->deniedResources = ['Panth_AiVisibility::recommendations'];
        $this->params = ['report' => $report];
        $this->controller()->execute();
        $this->assertSame([], $this->exports);
        $this->assertNull($this->contents);
        $this->assertSame('panth_aivisibility/dashboard/index', $this->redirectPath);
        $this->assertStringContainsString('not allowed', $this->errors[0]);

        $this->setUp();
        $this->params = ['report' => $report];
        $this->controller()->execute();
        $this->assertSame($report, $this->exports[0][0]);
        $this->assertContains('Panth_AiVisibility::recommendations', $this->aclChecks);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function recommendationReportProvider(): array
    {
        return ['recommendations' => ['recommendations'], 'verdicts' => ['verdicts']];
    }

    public function testFileName(): void
    {
        $range = DateRange::fromInput('custom', '2026-09-01', '2026-09-30', 1793361600);
        $this->assertSame(
            'panth-ai-visibility-sources-2026-09-01-2026-09-30.csv',
            Export::fileName('sources', $range)
        );
        $this->assertSame(
            'panth-ai-visibility-xy-2026-09-01-2026-09-30.csv',
            Export::fileName('x"; y', $range)
        );
    }
}
