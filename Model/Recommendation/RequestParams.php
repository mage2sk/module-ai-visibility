<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model\Recommendation;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;

/**
 * Request parameter checks shared by the recommendation admin actions.
 */
class RequestParams
{
    /**
     * Positive integer parameter (digits only), null when missing or invalid
     *
     * @param RequestInterface $request
     * @param string $name
     * @return int|null
     */
    public static function positiveInt(RequestInterface $request, string $name): ?int
    {
        $value = $request->getParam($name);
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 10 || !ctype_digit($value) || (int)$value <= 0) {
            return null;
        }
        return (int)$value;
    }

    /**
     * Route params that keep the store view filter of the page: ['store' => id] or []
     *
     * @param RequestInterface $request
     * @return array
     */
    public static function storeRouteParams(RequestInterface $request): array
    {
        $storeId = self::positiveInt($request, 'store');
        return $storeId === null ? [] : ['store' => $storeId];
    }

    /**
     * The recommendation, which must exist and still be open
     *
     * @param RecommendationRepositoryInterface $repository
     * @param int $recId
     * @return array
     * @throws LocalizedException
     */
    public static function openRecommendation(RecommendationRepositoryInterface $repository, int $recId): array
    {
        $row = $repository->get($recId);
        if ($row === null) {
            throw new LocalizedException(
                __('This recommendation no longer exists. The latest refresh may have resolved it.')
            );
        }
        $status = (string)($row['status'] ?? '');
        if ($status !== RecommendationRepositoryInterface::STATUS_OPEN) {
            throw new LocalizedException(__('This recommendation is no longer open (status: %1).', $status));
        }
        $row['title'] = trim((string)($row['title'] ?? ''));
        return $row;
    }
}
