<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Controller\Adminhtml\Health;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Service\Health\HealthRunner;

/**
 * Runs the data-health checks on demand from the dashboard (POST with form key).
 */
class Run extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AiVisibility::dashboard';

    /**
     * @param Context $context
     * @param HealthRunner $runner
     */
    public function __construct(
        Context $context,
        private readonly HealthRunner $runner
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('*/dashboard/index');
        if (!$this->_formKeyValidator->validate($this->getRequest())) {
            $this->messageManager->addErrorMessage(
                __('Your session has expired or the form was out of date. Reload the page and try again.')
            );
            return $redirect;
        }
        try {
            $results = $this->runner->runAll();
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('The health checks could not run: %1', $e->getMessage()));
            return $redirect;
        }
        $counts = [
            HealthResult::STATUS_OK => 0,
            HealthResult::STATUS_WARN => 0,
            HealthResult::STATUS_ERROR => 0,
            HealthResult::STATUS_UNKNOWN => 0,
        ];
        foreach ($results as $result) {
            if (isset($counts[$result->status])) {
                $counts[$result->status]++;
            }
        }
        $message = __(
            'Health checks finished: %1 OK, %2 warning(s), %3 error(s), %4 unknown.',
            $counts[HealthResult::STATUS_OK],
            $counts[HealthResult::STATUS_WARN],
            $counts[HealthResult::STATUS_ERROR],
            $counts[HealthResult::STATUS_UNKNOWN]
        );
        if ($counts[HealthResult::STATUS_ERROR] > 0 || $counts[HealthResult::STATUS_WARN] > 0) {
            $this->messageManager->addWarningMessage($message);
        } else {
            $this->messageManager->addSuccessMessage($message);
        }
        return $redirect;
    }
}
