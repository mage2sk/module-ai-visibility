<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Service\Export\CsvWriter;
use Panth\AiVisibility\Service\Export\ReportExporter;
use Panth\AiVisibility\Service\Report\DateRange;
use Psr\Log\LoggerInterface;

/**
 * CSV export of a dashboard report (GET).
 *
 * Parameters: report = daily | pages | bots | sources | recommendations | verdicts, and the dashboard
 * filters period / from / to / store / sim. bom=0 leaves out the UTF-8 byte order mark. The request
 * must carry a valid admin URL secret key or, when secret keys are switched off, a valid form_key.
 * The recommendations and verdicts reports (admin user names, notes) also need the Recommendations
 * permission, like the dashboard cards that show the same data.
 */
class Export extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AiVisibility::dashboard';

    public const FILE_PREFIX = 'panth-ai-visibility-';

    /**
     * Extra permission per report (reports not listed need only ADMIN_RESOURCE)
     */
    public const REPORT_RESOURCES = [
        ReportExporter::REPORT_RECOMMENDATIONS => 'Panth_AiVisibility::recommendations',
        ReportExporter::REPORT_VERDICTS => 'Panth_AiVisibility::recommendations',
    ];

    /**
     * @param Context $context
     * @param RawFactory $rawFactory
     * @param ReportExporter $exporter
     * @param CsvWriter $csvWriter
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly RawFactory $rawFactory,
        private readonly ReportExporter $exporter,
        private readonly CsvWriter $csvWriter,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Send the CSV file, or go back to the dashboard with an error
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $request = $this->getRequest();
        if (!$this->_backendUrl->useSecretKey() && !$this->_formKeyValidator->validate($request)) {
            $this->messageManager->addErrorMessage(__('Invalid form key. Please refresh the page and try again.'));
            return $this->backToDashboard();
        }
        $report = $request->getParam('report');
        $report = is_scalar($report) ? (string)$report : '';
        if (!in_array($report, ReportExporter::REPORTS, true)) {
            $this->messageManager->addErrorMessage(__('Unknown report. Choose one of the export links.'));
            return $this->backToDashboard();
        }
        if (!self::isReportAllowed($report, $this->_authorization)) {
            $this->messageManager->addErrorMessage(__('Sorry, you are not allowed to download this report.'));
            return $this->backToDashboard();
        }
        $range = DateRange::fromInput($request->getParam('period'), $request->getParam('from'), $request->getParam('to'));
        $storeId = $this->storeId($request->getParam('store', 0));
        $sim = $request->getParam('sim', '');
        $includeSim = is_scalar($sim) && (string)$sim === '1';
        $bom = $request->getParam('bom', '1');
        $withBom = !(is_scalar($bom) && (string)$bom === '0');

        try {
            $data = $this->exporter->export($report, $range, $storeId, $includeSim);
            $csv = $this->csvWriter->document($data['headers'], $data['rows'], $withBom);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth AI Visibility: CSV export failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('The export could not be created. Please try again later.'));
            return $this->backToDashboard();
        }

        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(200);
        $result->setHeader('Content-Type', 'text/csv; charset=UTF-8', true);
        $result->setHeader(
            'Content-Disposition',
            'attachment; filename="' . self::fileName($report, $range) . '"',
            true
        );
        $result->setHeader('Content-Length', (string)strlen($csv), true);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);
        $result->setContents($csv);
        return $result;
    }

    /**
     * Whether the admin may download a report (its extra permission, if any)
     *
     * @param string $report
     * @param AuthorizationInterface $authorization
     * @return bool
     */
    public static function isReportAllowed(string $report, AuthorizationInterface $authorization): bool
    {
        $resource = self::REPORT_RESOURCES[$report] ?? null;
        return $resource === null || $authorization->isAllowed($resource);
    }

    /**
     * File name: panth-ai-visibility-<report>-<from>-<to>.csv
     *
     * @param string $report
     * @param DateRange $range
     * @return string
     */
    public static function fileName(string $report, DateRange $range): string
    {
        $name = self::FILE_PREFIX . $report . '-' . $range->from() . '-' . $range->to() . '.csv';
        return (string)preg_replace('/[^A-Za-z0-9._-]/', '', $name);
    }

    /**
     * Store view id from the request; unknown ids fall back to 0 (all store views)
     *
     * @param mixed $value
     * @return int
     */
    private function storeId($value): int
    {
        $storeId = is_scalar($value) ? (int)$value : 0;
        if ($storeId <= 0) {
            return 0;
        }
        try {
            foreach ($this->storeManager->getStores() as $store) {
                if ((int)$store->getId() === $storeId) {
                    return $storeId;
                }
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        return 0;
    }

    /**
     * Redirect to the dashboard
     *
     * @return ResultInterface
     */
    private function backToDashboard(): ResultInterface
    {
        return $this->resultRedirectFactory->create()->setPath('panth_aivisibility/dashboard/index');
    }
}
