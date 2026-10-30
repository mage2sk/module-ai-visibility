<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Panth\AiVisibility\Controller\Adminhtml\Dashboard\Export;
use Panth\AiVisibility\Service\Export\ReportExporter;
use Panth\AiVisibility\Service\Report\DateRange;

/**
 * CSV export links under the dashboard, carrying the dashboard's current filters.
 *
 * The links point to panth_aivisibility/dashboard/export. Admin URLs carry the secret key when it is
 * enabled, which is all the controller checks then; only stores that switched secret keys off get the
 * form key in the link. The recommendations and verdicts links are left out for admins without the
 * Recommendations permission (the controller refuses those reports too).
 */
class ExportLinks extends Template
{
    /**
     * Export links in display order
     *
     * @return array<int, array{report: string, label: string, url: string}>
     */
    public function getLinks(): array
    {
        $query = $this->filterQuery();
        if (!$this->usesSecretKey()) {
            $query['form_key'] = $this->getFormKey();
        }
        $links = [];
        foreach (ReportExporter::REPORTS as $report) {
            $allowed = $this->_authorization === null
                ? !isset(Export::REPORT_RESOURCES[$report])
                : Export::isReportAllowed($report, $this->_authorization);
            if (!$allowed) {
                continue;
            }
            $links[] = [
                'report' => $report,
                'label' => $this->label($report),
                'url' => $this->getUrl(
                    'panth_aivisibility/dashboard/export',
                    ['_query' => ['report' => $report] + $query]
                ),
            ];
        }
        return $links;
    }

    /**
     * Whether admin URLs carry the secret key (the controller then needs no form key in the link)
     *
     * @return bool
     */
    private function usesSecretKey(): bool
    {
        return $this->_urlBuilder instanceof BackendUrlInterface && $this->_urlBuilder->useSecretKey();
    }

    /**
     * Range as text, e.g. "2026-10-01 to 2026-10-30 (UTC)"
     *
     * @return string
     */
    public function getRangeLabel(): string
    {
        $range = $this->range();
        return (string)__('%1 to %2 (UTC)', $range->from(), $range->to());
    }

    /**
     * Whether simulated rows are included (?sim=1)
     *
     * @return bool
     */
    public function includesSimulated(): bool
    {
        $sim = $this->getRequest()->getParam('sim', '');
        return is_scalar($sim) && (string)$sim === '1';
    }

    /**
     * Current dashboard filters as query parameters (normalised like the dashboard)
     *
     * @return array<string, int|string>
     */
    public function filterQuery(): array
    {
        $range = $this->range();
        $query = ['period' => $range->period()];
        if ($range->isCustom()) {
            $query['from'] = $range->from();
            $query['to'] = $range->to();
        }
        $store = $this->getRequest()->getParam('store', 0);
        $store = is_scalar($store) ? (int)$store : 0;
        if ($store > 0) {
            $query['store'] = $store;
        }
        if ($this->includesSimulated()) {
            $query['sim'] = 1;
        }
        return $query;
    }

    /**
     * Link label of a report
     *
     * @param string $report
     * @return string
     */
    private function label(string $report): string
    {
        switch ($report) {
            case ReportExporter::REPORT_DAILY:
                return (string)__('Daily series');
            case ReportExporter::REPORT_PAGES:
                return (string)__('Top pages');
            case ReportExporter::REPORT_BOTS:
                return (string)__('Bots');
            case ReportExporter::REPORT_SOURCES:
                return (string)__('AI sources');
            case ReportExporter::REPORT_RECOMMENDATIONS:
                return (string)__('Recommendations');
            default:
                return (string)__('Verdicts');
        }
    }

    /**
     * Selected date range
     *
     * @return DateRange
     */
    private function range(): DateRange
    {
        $request = $this->getRequest();
        return DateRange::fromInput($request->getParam('period'), $request->getParam('from'), $request->getParam('to'));
    }
}
