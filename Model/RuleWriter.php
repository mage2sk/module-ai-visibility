<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Model\Source\Category;

/**
 * Saves and deletes confirmed bot / source rules (panth_aiv_rule) and cleans the
 * PANTH_AIV_RULES cache tag so every classifier picks up the change.
 */
class RuleWriter
{
    /**
     * Columns refreshed when a rule with the same type and code is saved again
     */
    private const UPDATABLE = ['name', 'match_on', 'pattern', 'category', 'company', 'candidate_id'];

    /**
     * @param ResourceConnection $resource
     * @param CacheInterface $cache
     * @param RuleProvider $ruleProvider
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly RuleProvider $ruleProvider
    ) {
    }

    /**
     * Insert or update a rule and return its rule_id
     *
     * Keys: type ('bot'|'source'), code ([a-z0-9_], max 64), name (max 128), match_on
     * ('ua' for bots, 'host'|'utm' for sources; bots default to 'ua'), pattern (max 512;
     * host and utm patterns are lower-cased), category (bots: training|search|live|other,
     * default other; ignored for sources), company (optional, max 64), candidate_id (optional),
     * rule_id (optional: update that row). Without rule_id an existing rule with the same
     * type and code is updated in place (created_at is kept).
     *
     * @param array $rule
     * @return int
     * @throws LocalizedException When the rule is invalid or the rule_id does not exist
     */
    public function save(array $rule): int
    {
        $row = $this->validate($rule);
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(RuleProvider::TABLE);
        $ruleId = (int)($rule['rule_id'] ?? 0);

        if ($ruleId > 0) {
            $exists = $connection->fetchOne(
                $connection->select()->from($table, ['rule_id'])->where('rule_id = ?', $ruleId)
            );
            if ($exists === false || $exists === null) {
                throw new LocalizedException(__('The rule with ID %1 does not exist.', $ruleId));
            }
            $connection->update($table, $row, ['rule_id = ?' => $ruleId]);
        } else {
            $row['created_at'] = gmdate('Y-m-d H:i:s');
            $connection->insertOnDuplicate($table, $row, self::UPDATABLE);
            $ruleId = (int)$connection->fetchOne(
                $connection->select()
                    ->from($table, ['rule_id'])
                    ->where('type = ?', $row['type'])
                    ->where('code = ?', $row['code'])
            );
        }
        $this->cleanCache();
        return $ruleId;
    }

    /**
     * Delete a rule (no-op when it does not exist)
     *
     * @param int $ruleId
     * @return void
     */
    public function delete(int $ruleId): void
    {
        if ($ruleId <= 0) {
            return;
        }
        $connection = $this->resource->getConnection();
        $connection->delete($this->resource->getTableName(RuleProvider::TABLE), ['rule_id = ?' => $ruleId]);
        $this->cleanCache();
    }

    /**
     * Validate and normalise a rule into a table row (without rule_id / created_at)
     *
     * @param array $rule
     * @return array
     * @throws LocalizedException
     */
    private function validate(array $rule): array
    {
        $type = strtolower(trim((string)($rule['type'] ?? '')));
        if ($type !== RuleProvider::TYPE_BOT && $type !== RuleProvider::TYPE_SOURCE) {
            throw new LocalizedException(__('The rule type must be "bot" or "source".'));
        }

        $code = strtolower(trim((string)($rule['code'] ?? '')));
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $code)) {
            throw new LocalizedException(
                __('The rule code may only contain a-z, 0-9 and underscores (1-64 characters).')
            );
        }

        $name = trim(mb_substr(trim((string)($rule['name'] ?? '')), 0, 128));
        if ($name === '') {
            throw new LocalizedException(__('The rule name is required.'));
        }

        $matchOn = strtolower(trim((string)($rule['match_on'] ?? '')));
        if ($type === RuleProvider::TYPE_BOT) {
            if ($matchOn === '') {
                $matchOn = RuleProvider::MATCH_UA;
            }
            if ($matchOn !== RuleProvider::MATCH_UA) {
                throw new LocalizedException(__('Bot rules can only match on the User-Agent.'));
            }
        } elseif ($matchOn !== RuleProvider::MATCH_HOST && $matchOn !== RuleProvider::MATCH_UTM) {
            throw new LocalizedException(__('Source rules must match on "host" or "utm".'));
        }

        $pattern = trim((string)($rule['pattern'] ?? ''));
        if ($pattern === '' || strlen($pattern) > 512) {
            throw new LocalizedException(__('The rule pattern is required (max 512 characters).'));
        }
        if ($matchOn === RuleProvider::MATCH_UA && strlen($pattern) < Config::MIN_BOT_TOKEN_LENGTH) {
            throw new LocalizedException(
                __('The User-Agent token needs at least %1 characters.', Config::MIN_BOT_TOKEN_LENGTH)
            );
        }
        if ($matchOn === RuleProvider::MATCH_HOST) {
            $pattern = strtolower($pattern);
            if (strlen($pattern) > 255 || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $pattern)
                || str_contains($pattern, '..')
            ) {
                throw new LocalizedException(__('The host "%1" is not a valid host name.', $pattern));
            }
        }
        if ($matchOn === RuleProvider::MATCH_UTM) {
            $pattern = strtolower($pattern);
            if (!preg_match('/^[a-z0-9._-]{1,64}$/', $pattern)) {
                throw new LocalizedException(__('The utm_source value "%1" is not valid.', $pattern));
            }
        }

        $category = null;
        if ($type === RuleProvider::TYPE_BOT) {
            $category = strtolower(trim((string)($rule['category'] ?? '')));
            if ($category === '') {
                $category = Category::OTHER;
            }
            if (!Category::isValid($category)) {
                throw new LocalizedException(__('The bot category "%1" is not valid.', $category));
            }
        }

        $company = trim(mb_substr(trim((string)($rule['company'] ?? '')), 0, 64));
        $candidateId = (int)($rule['candidate_id'] ?? 0);

        return [
            'type' => $type,
            'code' => $code,
            'name' => $name,
            'match_on' => $matchOn,
            'pattern' => $pattern,
            'category' => $category,
            'company' => $company !== '' ? $company : null,
            'candidate_id' => $candidateId > 0 ? $candidateId : null,
        ];
    }

    /**
     * Clean the rules cache tag and the in-memory copy
     *
     * @return void
     */
    private function cleanCache(): void
    {
        $this->cache->clean([RuleProvider::CACHE_TAG]);
        $this->ruleProvider->reset();
    }
}
