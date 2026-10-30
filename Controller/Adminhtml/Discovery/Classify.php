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
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Discovery\CandidateClassifier;
use Panth\AiVisibility\Service\Discovery\CandidateRepository;
use Psr\Log\LoggerInterface;

/**
 * Confirms a discovery candidate as an AI bot / source, or marks it Not AI / Ignored (POST, form key).
 */
class Classify extends Action implements HttpPostActionInterface
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
        $returnStatus = $request->getParam('return_status', '');
        $returnStatus = is_scalar($returnStatus) ? (string)$returnStatus : '';
        $redirect->setPath(
            '*/*/index',
            in_array($returnStatus, CandidateRepository::STATUSES, true) && $returnStatus !== CandidateRepository::STATUS_NEW
                ? ['status' => $returnStatus]
                : []
        );

        if (!$this->_formKeyValidator->validate($request)) {
            $this->messageManager->addErrorMessage(
                __('Your session has expired or the form was out of date. Reload the page and try again.')
            );
            return $redirect;
        }

        $candidateId = $request->getParam('candidate_id');
        $decision = $request->getParam('decision');
        if (!is_scalar($candidateId) || !ctype_digit((string)$candidateId) || (int)$candidateId <= 0) {
            $this->messageManager->addErrorMessage(__('No candidate was selected.'));
            return $redirect;
        }
        $allowed = array_merge(
            Category::BOT_CATEGORIES,
            [
                CandidateClassifier::DECISION_CONFIRM,
                CandidateClassifier::DECISION_NOT_AI,
                CandidateClassifier::DECISION_IGNORED,
                CandidateClassifier::DECISION_RESTORE,
            ]
        );
        if (!is_string($decision) || !in_array($decision, $allowed, true)) {
            $this->messageManager->addErrorMessage(__('Choose what this candidate is.'));
            return $redirect;
        }

        $input = [];
        foreach (['name', 'token', 'company'] as $field) {
            $value = $request->getParam($field, '');
            $input[$field] = is_scalar($value) ? (string)$value : '';
        }

        try {
            $this->messageManager->addSuccessMessage(
                $this->classifier->classify((int)$candidateId, $decision, $input)
            );
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] discovery classify failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('The candidate could not be saved. Look in var/log/system.log for details and try again.')
            );
        }
        return $redirect;
    }
}
