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
 * Dismisses an open recommendation (POST, form key).
 */
class Dismiss extends Action implements HttpPostActionInterface
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

        try {
            $recommendation = RequestParams::openRecommendation($this->repository, $recId);
            $this->repository->setStatus($recId, RecommendationRepositoryInterface::STATUS_DISMISSED);
            $this->messageManager->addSuccessMessage(__(
                'Dismissed "%1". The same recommendation is not suggested again for 30 days.',
                (string)$recommendation['title']
            ));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] recommendation dismiss failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('The recommendation could not be dismissed. Look in var/log/system.log for details and try again.')
            );
        }
        return $redirect;
    }
}
