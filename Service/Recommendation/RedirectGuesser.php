<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation;

use Magento\Framework\App\ResourceConnection;

/**
 * Guesses a redirect target for a path that answers 404, from Magento's url_rewrite table.
 *
 * Takes the last path segment (without .html and similar suffixes), looks up live (non-redirect)
 * product, category and CMS rewrites of the store view that contain its two longest words, and picks
 * the candidate whose last segment is most similar. One bounded query per path; a guess is only
 * returned when the similarity reaches MIN_SCORE. Never throws: a failing lookup gives no guess.
 */
class RedirectGuesser
{
    public const MIN_SCORE = 0.6;
    public const CANDIDATE_LIMIT = 100;

    private const ENTITY_TYPES = ['product', 'category', 'cms-page'];
    private const MIN_TOKEN_LENGTH = 3;
    private const SEARCH_TOKENS = 2;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Best redirect target for a 404 path, or null
     *
     * @param int $storeId 0 = any store view
     * @param string $path
     * @return array{target: string, entity_type: string, entity_id: int, score: float}|null
     */
    public function guess(int $storeId, string $path): ?array
    {
        $segment = self::lastSegment($path);
        $tokens = self::tokens($segment);
        if ($tokens === []) {
            return null;
        }
        usort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
        $searches = [array_slice($tokens, 0, self::SEARCH_TOKENS)];
        if (count($searches[0]) > 1) {
            $searches[] = [$tokens[0]];
        }
        foreach ($searches as $search) {
            try {
                $best = $this->best($segment, $path, $this->candidates($storeId, $search));
            } catch (\Throwable $e) {
                return null;
            }
            if ($best !== null) {
                return $best;
            }
        }
        return null;
    }

    /**
     * Most similar candidate at or above MIN_SCORE
     *
     * @param string $segment
     * @param string $path
     * @param array $candidates
     * @return array{target: string, entity_type: string, entity_id: int, score: float}|null
     */
    private function best(string $segment, string $path, array $candidates): ?array
    {
        $own = ltrim(trim($path), '/');
        $best = null;
        foreach ($candidates as $row) {
            $requestPath = (string)$row['request_path'];
            if ($requestPath === '' || $requestPath === $own) {
                continue;
            }
            $score = self::similarity($segment, self::lastSegment($requestPath));
            if ($score >= self::MIN_SCORE && ($best === null || $score > $best['score'])) {
                $best = [
                    'target' => '/' . ltrim($requestPath, '/'),
                    'entity_type' => (string)$row['entity_type'],
                    'entity_id' => (int)$row['entity_id'],
                    'score' => round($score, 2),
                ];
            }
        }
        return $best;
    }

    /**
     * Last path segment, lower case, without a file suffix such as .html
     *
     * @param string $path
     * @return string
     */
    public static function lastSegment(string $path): string
    {
        $path = strtolower(trim($path));
        $path = (string)preg_replace('/[?#].*$/', '', $path);
        $parts = array_values(array_filter(explode('/', $path), static fn (string $p): bool => $p !== ''));
        $last = $parts === [] ? '' : (string)end($parts);
        return (string)preg_replace('/\.(html?|php|aspx?)$/', '', $last);
    }

    /**
     * Words of a segment (letters and digits, at least 3 characters, not digits only)
     *
     * @param string $segment
     * @return string[]
     */
    public static function tokens(string $segment): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($segment)) ?: [];
        $result = [];
        foreach ($words as $word) {
            if (strlen($word) >= self::MIN_TOKEN_LENGTH && !ctype_digit($word)) {
                $result[$word] = $word;
            }
        }
        return array_values($result);
    }

    /**
     * Similarity of two segments, 0-1: the better of character similarity and word overlap
     *
     * @param string $a
     * @param string $b
     * @return float
     */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $percent);
        $wordsA = self::tokens($a);
        $wordsB = self::tokens($b);
        $union = count(array_unique(array_merge($wordsA, $wordsB)));
        $jaccard = $union > 0 ? count(array_intersect($wordsA, $wordsB)) / $union : 0.0;
        return max($percent / 100, $jaccard);
    }

    /**
     * Live rewrites containing every search word
     *
     * @param int $storeId
     * @param string[] $search
     * @return array<int, array{request_path: string, entity_type: string, entity_id: int}>
     */
    private function candidates(int $storeId, array $search): array
    {
        $bind = [];
        $types = [];
        foreach (self::ENTITY_TYPES as $i => $type) {
            $types[] = ':t' . $i;
            $bind['t' . $i] = $type;
        }
        $sql = 'SELECT u.request_path, u.entity_type, u.entity_id FROM '
            . $this->resource->getTableName('url_rewrite') . ' AS u'
            . ' WHERE u.redirect_type = 0 AND u.entity_type IN (' . implode(', ', $types) . ')';
        if ($storeId > 0) {
            $sql .= ' AND u.store_id = :store';
            $bind['store'] = $storeId;
        }
        foreach (array_values($search) as $i => $token) {
            $sql .= ' AND u.request_path LIKE :w' . $i;
            $bind['w' . $i] = '%' . $token . '%';
        }
        $sql .= ' LIMIT ' . self::CANDIDATE_LIMIT;
        return $this->resource->getConnection()->fetchAll($sql, $bind);
    }
}
