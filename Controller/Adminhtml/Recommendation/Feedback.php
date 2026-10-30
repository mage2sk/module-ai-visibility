<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Controller\Adminhtml\Recommendation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Panth\AiVisibility\Model\Recommendation\RequestParams;
use Psr\Log\LoggerInterface;

/**
 * Stores "Useful" / "Not useful" feedback on a recommendation (POST, form key).
 */
class Feedback extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AiVisibility::recommendations';

    /**
     * @param Context $context
     * @param RecommendationRepositoryInterface $repository
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly RecommendationRepositoryInterface $repository,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute(): ResultInterface
    {
        $request = $this->getRequest();
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('*/*/index', RequestParams::storeRouteParams($request));

        if (!$this->_formKeyValidator->validate($request)) {
            $this->messageManager->addErrorMessage(
                __('Your session has expired or the form was out of date. Reload the page and try again.')
            );
            return $redirect;
        }
        $recId = RequestParams::positiveInt($request, 'rec_id');
        if ($recId === null) {
            $this->messageManager->addErrorMessage(__('No recommendation was selected.'));
            return $redirect;
        }
        $value = $request->getParam('value');
        $value = is_scalar($value) && !is_bool($value) ? trim((string)$value) : '';
        $allowed = [
            (string)RecommendationRepositoryInterface::FEEDBACK_USEFUL,
            (string)RecommendationRepositoryInterface::FEEDBACK_NOT_USEFUL,
        ];
        if (!in_array($value, $allowed, true)) {
            $this->messageManager->addErrorMessage(__('Choose "Useful" or "Not useful".'));
            return $redirect;
        }

        try {
            if ($this->repository->get($recId) === null) {
                throw new LocalizedException(__('This recommendation no longer exists.'));
            }
            $this->repository->setFeedback($recId, (int)$value);
            $this->messageManager->addSuccessMessage(
                (int)$value === RecommendationRepositoryInterface::FEEDBACK_USEFUL
                    ? __('Thanks. The recommendation is marked as useful.')
                    : __('Thanks. Recommendations of this kind are ranked lower for 30 days.')
            );
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] recommendation feedback failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('The feedback could not be saved. Look in var/log/system.log for details and try again.')
            );
        }
        return $redirect;
    }
}
