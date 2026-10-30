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
use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Recommendation\RequestParams;
use Psr\Log\LoggerInterface;

/**
 * Logs that the merchant applied a recommendation, with an optional note (POST, form key).
 */
class Apply extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AiVisibility::recommendations';

    /**
     * @param Context $context
     * @param ActionLogInterface $actionLog
     * @param RecommendationRepositoryInterface $repository
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly ActionLogInterface $actionLog,
        private readonly RecommendationRepositoryInterface $repository,
        private readonly Config $config,
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
        $note = $request->getParam('note');
        if ($note !== null && (!is_scalar($note) || is_bool($note))) {
            $this->messageManager->addErrorMessage(__('The note could not be read. Enter plain text.'));
            return $redirect;
        }
        $note = trim((string)$note);
        if (mb_strlen($note) > ActionLogInterface::MAX_NOTE) {
            $this->messageManager->addErrorMessage(
                __('The note can have at most %1 characters.', ActionLogInterface::MAX_NOTE)
            );
            return $redirect;
        }

        try {
            $recommendation = RequestParams::openRecommendation($this->repository, $recId);
            $this->actionLog->apply($recId, $note === '' ? null : $note, $this->adminUsername());
            $this->messageManager->addSuccessMessage(__(
                'Marked "%1" as applied. Its effect is checked automatically once %2 days of new data exist.',
                (string)$recommendation['title'],
                $this->config->verifyWindowDays()
            ));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] recommendation apply failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('The action could not be saved. Look in var/log/system.log for details and try again.')
            );
        }
        return $redirect;
    }

    /**
     * Username of the signed-in admin, null when unknown
     *
     * @return string|null
     */
    private function adminUsername(): ?string
    {
        try {
            $user = $this->_auth ? $this->_auth->getUser() : null;
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_object($user) || !is_callable([$user, 'getUserName'])) {
            return null;
        }
        $name = trim((string)$user->getUserName());
        return $name === '' ? null : mb_substr($name, 0, 64);
    }
}
