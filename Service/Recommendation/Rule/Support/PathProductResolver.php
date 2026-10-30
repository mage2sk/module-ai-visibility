<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation\Rule\Support;

use Panth\AiVisibility\Service\Rollup;

/**
 * Product pages behind landing paths, resolved like the 1.0 rollup does: "/index.php" and the store
 * code are removed, then url_rewrite is looked up by request_path for the store (batched, product
 * rewrites only, a direct rewrite wins over a redirect).
 */
class PathProductResolver
{
    private const ENTITY_TYPE_PRODUCT = 'product';
    private const MAX_REQUEST_PATH = 255;

    /**
     * @param RuleSupport $support
     */
    public function __construct(
        private readonly RuleSupport $support
    ) {
    }

    /**
     * Product ID per path key for one store view
     *
     * @param array<string, string> $paths key (e.g. path hash) => URL path without query
     * @param int $storeId Store view the paths were requested on (> 0)
     * @return array<string, int> key => product ID (unresolved keys left out)
     */
    public function productIds(array $paths, int $storeId): array
    {
        if ($paths === [] || $storeId <= 0) {
            return [];
        }
        $storeCode = $this->support->config()->urlStoreCode($storeId);
        $pending = [];
        foreach ($paths as $key => $path) {
            $path = Rollup::stripStoreCode(Rollup::stripIndexPhp((string)$path), $storeCode);
            foreach (self::requestPaths($path) as $requestPath) {
                $pending[mb_strtolower($requestPath)][] = (string)$key;
            }
        }
        if ($pending === []) {
            return [];
        }
        $found = [];
        $redirect = [];
        $connection = $this->support->connection();
        foreach (array_chunk(array_map('strval', array_keys($pending)), RuleSupport::SCAN_LIMIT) as $chunk) {
            $sql = 'SELECT u.request_path, u.entity_id, u.redirect_type FROM ' . $this->support->table(Rollup::URL_REWRITE_TABLE)
                . ' AS u WHERE u.store_id = ? AND u.entity_type = ?'
                . ' AND u.request_path IN (' . RuleSupport::placeholders(count($chunk)) . ')';
            $rows = $connection->fetchAll($sql, array_merge([$storeId, self::ENTITY_TYPE_PRODUCT], $chunk));
            foreach ($rows as $row) {
                $requestPath = mb_strtolower((string)$row['request_path']);
                $isRedirect = (int)$row['redirect_type'] !== 0;
                if (isset($found[$requestPath]) && ($isRedirect || !$redirect[$requestPath])) {
                    continue;
                }
                $found[$requestPath] = (int)$row['entity_id'];
                $redirect[$requestPath] = $isRedirect;
            }
        }
        $result = [];
        foreach ($pending as $requestPath => $keys) {
            if (!isset($found[$requestPath]) || $found[$requestPath] <= 0) {
                continue;
            }
            foreach ($keys as $key) {
                $result[$key] ??= $found[$requestPath];
            }
        }
        return $result;
    }

    /**
     * url_rewrite request_path candidates for a URL path (as stored, and URL-decoded)
     *
     * @param string $path
     * @return string[]
     */
    private static function requestPaths(string $path): array
    {
        $result = [];
        $plain = ltrim($path, '/');
        foreach ([$plain, rawurldecode($plain)] as $candidate) {
            if ($candidate !== ''
                && strlen($candidate) <= self::MAX_REQUEST_PATH
                && !in_array($candidate, $result, true)
            ) {
                $result[] = $candidate;
            }
        }
        return $result;
    }
}
