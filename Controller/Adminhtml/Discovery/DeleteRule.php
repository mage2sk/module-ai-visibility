<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Controller\Adminhtml\Discovery;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Service\Discovery\CandidateClassifier;
use Psr\Log\LoggerInterface;

/**
 * Deletes a confirmed bot / source rule (POST, form key).
 */
class DeleteRule extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AiVisibility::discovery';

    /**
     * @param Context $context
     * @param CandidateClassifier $classifier
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly CandidateClassifier $classifier,
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
        $redirect->setPath('*/*/index');

        if (!$this->_formKeyValidator->validate($request)) {
            $this->messageManager->addErrorMessage(
                __('Your session has expired or the form was out of date. Reload the page and try again.')
            );
            return $redirect;
        }
        $ruleId = $request->getParam('rule_id');
        if (!is_scalar($ruleId) || !ctype_digit((string)$ruleId) || (int)$ruleId <= 0) {
            $this->messageManager->addErrorMessage(__('No rule was selected.'));
            return $redirect;
        }
        try {
            $this->messageManager->addSuccessMessage($this->classifier->deleteRule((int)$ruleId));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] discovery rule delete failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('The rule could not be deleted. Look in var/log/system.log for details and try again.')
            );
        }
        return $redirect;
    }
}
