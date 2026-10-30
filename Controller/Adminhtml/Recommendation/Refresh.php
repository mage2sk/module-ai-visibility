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
use Panth\AiVisibility\Api\RecommendationEngineInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Recommendation\RequestParams;
use Panth\AiVisibility\ViewModel\Recommendation\Presenter;
use Psr\Log\LoggerInterface;

/**
 * Runs the recommendation rules now for the store view shown on the page, or all (POST, form key).
 *
 * Rules that failed (engine result "errors", e.g. "store 1, rule bot_404: ...") keep their open
 * recommendations; the admin gets a warning naming those checks, the details go to the log.
 */
class Refresh extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AiVisibility::recommendations';

    /**
     * @param Context $context
     * @param RecommendationEngineInterface $engine
     * @param Config $config
     * @param LoggerInterface $logger
     * @param Presenter|null $presenter Rule names in the warning (rule codes without it)
     */
    public function __construct(
        Context $context,
        private readonly RecommendationEngineInterface $engine,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ?Presenter $presenter = null
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
        $store = $request->getParam('store');
        $storeId = RequestParams::positiveInt($request, 'store');
        if ($storeId === null && $store !== null && $store !== '' && $store !== '0' && $store !== 0) {
            $this->messageManager->addErrorMessage(__('The store view could not be read.'));
            return $redirect;
        }
        if (!$this->config->recommendationsEnabled()) {
            $this->messageManager->addErrorMessage(
                __('Recommendations are switched off. Turn them on in the AI Visibility configuration first.')
            );
            return $redirect;
        }

        try {
            $result = $this->engine->run($storeId);
            $this->messageManager->addSuccessMessage(__(
                'Recommendations refreshed: %1 new, %2 updated, %3 resolved.',
                (int)($result['created'] ?? 0),
                (int)($result['updated'] ?? 0),
                (int)($result['resolved'] ?? 0)
            ));
            $this->reportErrors($result['errors'] ?? []);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] recommendation refresh failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('The recommendations could not be refreshed. Look in var/log/system.log for details and try again.')
            );
        }
        return $redirect;
    }

    /**
     * Warn about checks that could not run (their open recommendations were kept)
     *
     * @param mixed $errors Engine errors: "store <id>, rule <code>: <message>" or "store <id>: <message>"
     * @return void
     */
    private function reportErrors($errors): void
    {
        if (!is_array($errors) || $errors === []) {
            return;
        }
        $labels = [];
        foreach ($errors as $error) {
            $error = is_scalar($error) ? (string)$error : '';
            $this->logger->warning('[Panth_AiVisibility] recommendation check failed: ' . $error);
            $labels[] = preg_match('/\brule ([a-z0-9_]+):/', $error, $m) === 1
                ? ($this->presenter !== null ? $this->presenter->ruleLabel($m[1]) : $m[1])
                : (string)__('all checks of a store view');
        }
        $this->messageManager->addWarningMessage(__(
            'Some checks could not run: %1. Their open recommendations were left unchanged. '
            . 'Look in var/log/system.log for details.',
            implode(', ', array_unique($labels))
        ));
    }
}
