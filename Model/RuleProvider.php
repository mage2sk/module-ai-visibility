<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Bots and sources confirmed on the Discovery page (panth_aiv_rule).
 *
 * The rules are read with one query, cached in the application cache under the tag
 * PANTH_AIV_RULES (cleaned by RuleWriter) and kept in memory for the rest of the request,
 * so classifiers can call this on every request without touching the database.
 *
 * Each rule is an array with the keys rule_id (int), type ('bot'|'source'), code, name,
 * match_on ('ua'|'host'|'utm'), pattern, category (?string), company (?string),
 * candidate_id (?int) and created_at ('Y-m-d H:i:s' UTC), ordered by rule_id.
 */
class RuleProvider
{
    public const TABLE = 'panth_aiv_rule';
    public const CACHE_TAG = 'PANTH_AIV_RULES';
    public const CACHE_KEY = 'panth_aiv_rules';
    public const CACHE_LIFETIME = 86400;

    /**
     * Tags of the cache entry: its own tag, plus the configuration cache tag so that
     * "bin/magento cache:clean config" also clears it
     */
    public const CACHE_TAGS = [self::CACHE_TAG, 'CONFIG'];

    public const TYPE_BOT = 'bot';
    public const TYPE_SOURCE = 'source';

    public const MATCH_UA = 'ua';
    public const MATCH_HOST = 'host';
    public const MATCH_UTM = 'utm';

    /**
     * @var array{bot: array, source: array}|null
     */
    private ?array $rules = null;

    /**
     * @param ResourceConnection $resource
     * @param CacheInterface $cache
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Confirmed bot rules (match_on "ua", pattern = User-Agent token)
     *
     * @return array<int, array>
     */
    public function botRules(): array
    {
        return $this->load()[self::TYPE_BOT];
    }

    /**
     * Confirmed source rules (match_on "host" or "utm", pattern = host or utm_source value)
     *
     * @return array<int, array>
     */
    public function sourceRules(): array
    {
        return $this->load()[self::TYPE_SOURCE];
    }

    /**
     * Forget the in-memory copy (the next call reads the cache or the database again)
     *
     * @return void
     */
    public function reset(): void
    {
        $this->rules = null;
    }

    /**
     * Load the rules from memory, the application cache or the database
     *
     * @return array{bot: array, source: array}
     */
    private function load(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }
        $cached = $this->fromCache();
        if ($cached !== null) {
            return $this->rules = $cached;
        }
        try {
            $rows = $this->fetchRows();
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] confirmed rules could not be loaded: ' . $e->getMessage());
            return $this->rules = [self::TYPE_BOT => [], self::TYPE_SOURCE => []];
        }
        $rules = [self::TYPE_BOT => [], self::TYPE_SOURCE => []];
        foreach ($rows as $row) {
            $rule = $this->normalize($row);
            if ($rule !== null) {
                $rules[$rule['type']][] = $rule;
            }
        }
        try {
            $this->cache->save(
                $this->serializer->serialize($rules),
                self::CACHE_KEY,
                self::CACHE_TAGS,
                self::CACHE_LIFETIME
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] confirmed rules could not be cached: ' . $e->getMessage());
        }
        return $this->rules = $rules;
    }

    /**
     * Rules from the application cache, or null on a miss or unreadable entry
     *
     * @return array{bot: array, source: array}|null
     */
    private function fromCache(): ?array
    {
        try {
            $raw = $this->cache->load(self::CACHE_KEY);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $data = $this->serializer->unserialize($raw);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($data)
            || !isset($data[self::TYPE_BOT], $data[self::TYPE_SOURCE])
            || !is_array($data[self::TYPE_BOT])
            || !is_array($data[self::TYPE_SOURCE])
        ) {
            return null;
        }
        return [self::TYPE_BOT => $data[self::TYPE_BOT], self::TYPE_SOURCE => $data[self::TYPE_SOURCE]];
    }

    /**
     * All rule rows ordered by id
     *
     * @return array
     */
    private function fetchRows(): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName(self::TABLE),
                [
                    'rule_id', 'type', 'code', 'name', 'match_on', 'pattern',
                    'category', 'company', 'candidate_id', 'created_at',
                ]
            )
            ->order('rule_id ASC');
        return $connection->fetchAll($select);
    }

    /**
     * Typed rule array, or null for a row with an unknown type or empty pattern
     *
     * @param array $row
     * @return array|null
     */
    private function normalize(array $row): ?array
    {
        $type = (string)($row['type'] ?? '');
        $pattern = trim((string)($row['pattern'] ?? ''));
        $code = (string)($row['code'] ?? '');
        if (($type !== self::TYPE_BOT && $type !== self::TYPE_SOURCE) || $pattern === '' || $code === '') {
            return null;
        }
        $matchOn = (string)($row['match_on'] ?? '');
        if ($type === self::TYPE_BOT) {
            $matchOn = self::MATCH_UA;
        } elseif ($matchOn !== self::MATCH_HOST && $matchOn !== self::MATCH_UTM) {
            return null;
        }
        $category = isset($row['category']) && $row['category'] !== '' ? (string)$row['category'] : null;
        $company = isset($row['company']) && $row['company'] !== '' ? (string)$row['company'] : null;
        $candidateId = isset($row['candidate_id']) && (int)$row['candidate_id'] > 0 ? (int)$row['candidate_id'] : null;
        return [
            'rule_id' => (int)($row['rule_id'] ?? 0),
            'type' => $type,
            'code' => $code,
            'name' => (string)($row['name'] ?? '') !== '' ? (string)$row['name'] : $code,
            'match_on' => $matchOn,
            'pattern' => $pattern,
            'category' => $category,
            'company' => $company,
            'candidate_id' => $candidateId,
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }
}
