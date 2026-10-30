<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\Api\ImpactCalculatorInterface;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Finding;
use Psr\Log\LoggerInterface;

/**
 * Log of fixes the merchant applied (panth_aiv_action).
 *
 * apply() snapshots the recommendation's rule code, store view, metric and subject list (evidence
 * "subjects", else the subject ref) so later changes to the recommendation never change what is verified,
 * sets verify_after = applied UTC day + verify/window_days + 1 and marks the recommendation applied. The
 * status change is a conditional update (open -> applied) in the same transaction as the insert, so a
 * double submit logs one action and the second request is refused.
 * undo() is for a change the merchant reverted while its verification is still pending: it marks the
 * action undone (it is then never verified) and reopens its recommendation. Verified / insufficient
 * actions cannot be undone (their verdict stays).
 */
class ActionLog implements ActionLogInterface
{
    public const MAX_APPLIED_BY = 64;
    public const MAX_LIST = 500;

    /**
     * @param ResourceConnection $resource
     * @param RecommendationRepositoryInterface $recommendations
     * @param Config $config
     * @param DateTime $dateTime
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly RecommendationRepositoryInterface $recommendations,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(int $recId, ?string $note, ?string $adminUser): int
    {
        $rec = $this->recommendations->get($recId);
        if ($rec === null) {
            throw new LocalizedException(__('Recommendation %1 does not exist.', $recId));
        }
        if (($rec['status'] ?? '') === RecommendationRepositoryInterface::STATUS_APPLIED) {
            throw new LocalizedException(__('Recommendation %1 is already applied.', $recId));
        }
        if (($rec['status'] ?? '') !== RecommendationRepositoryInterface::STATUS_OPEN) {
            throw new LocalizedException(__('Recommendation %1 is not open any more.', $recId));
        }
        $now = (int)$this->dateTime->gmtTimestamp();
        $appliedDay = (int)strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC');
        $verifyAfter = gmdate('Y-m-d', $appliedDay + ($this->config->verifyWindowDays() + 1) * 86400);

        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            // Claim the recommendation first: only one request can move it from open to applied.
            $claimed = $connection->update(
                $this->table(RecommendationRepositoryInterface::TABLE),
                [
                    'status' => RecommendationRepositoryInterface::STATUS_APPLIED,
                    'status_changed_at' => gmdate('Y-m-d H:i:s', $now),
                ],
                ['rec_id = ?' => $recId, 'status = ?' => RecommendationRepositoryInterface::STATUS_OPEN]
            );
            if ((int)$claimed !== 1) {
                throw new LocalizedException(__('Recommendation %1 is already applied.', $recId));
            }
            $connection->insert($this->table(self::TABLE), [
                'rec_id' => $recId,
                'rule_code' => self::cut((string)($rec['rule_code'] ?? ''), Finding::MAX_RULE_CODE),
                'store_id' => max(0, (int)($rec['store_id'] ?? 0)),
                'metric' => self::cut((string)($rec['metric'] ?? ''), Finding::MAX_METRIC),
                'subjects' => (string)$this->json->serialize($this->subjects($rec)),
                'note' => self::nullable($note, self::MAX_NOTE),
                'applied_by' => self::nullable($adminUser, self::MAX_APPLIED_BY),
                'applied_at' => gmdate('Y-m-d H:i:s', $now),
                'undone_at' => null,
                'verify_after' => $verifyAfter,
                'status' => self::STATUS_PENDING,
                'is_simulated' => empty($rec['is_simulated']) ? 0 : 1,
            ]);
            $actionId = (int)$connection->lastInsertId($this->table(self::TABLE));
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        return $actionId;
    }

    /**
     * @inheritdoc
     */
    public function undo(int $actionId): void
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table(self::TABLE), ['action_id', 'rec_id', 'status', 'undone_at'])
                ->where('action_id = ?', $actionId)
                ->limit(1)
        );
        if (!$row) {
            throw new LocalizedException(__('Action %1 does not exist.', $actionId));
        }
        if (($row['status'] ?? '') === self::STATUS_UNDONE || !empty($row['undone_at'])) {
            return;
        }
        if (($row['status'] ?? '') !== self::STATUS_PENDING) {
            throw new LocalizedException(
                __('Action %1 was already verified and cannot be undone.', $actionId)
            );
        }
        $updated = $connection->update(
            $this->table(self::TABLE),
            ['status' => self::STATUS_UNDONE, 'undone_at' => gmdate('Y-m-d H:i:s', (int)$this->dateTime->gmtTimestamp())],
            ['action_id = ?' => $actionId, 'status = ?' => self::STATUS_PENDING]
        );
        if ((int)$updated !== 1) {
            // Verified between the read and the update.
            throw new LocalizedException(
                __('Action %1 was already verified and cannot be undone.', $actionId)
            );
        }
        $recId = (int)($row['rec_id'] ?? 0);
        if ($recId <= 0) {
            return;
        }
        try {
            $rec = $this->recommendations->get($recId);
            if ($rec !== null && ($rec['status'] ?? '') === RecommendationRepositoryInterface::STATUS_APPLIED) {
                $this->recommendations->setStatus($recId, RecommendationRepositoryInterface::STATUS_OPEN);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Panth AI Visibility: reopening recommendation %d failed: %s', $recId, $e->getMessage())
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function listRecent(int $limit): array
    {
        $limit = max(1, min(self::MAX_LIST, $limit));
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->table(self::TABLE)])
            ->joinLeft(
                ['r' => $this->table(RecommendationRepositoryInterface::TABLE)],
                'r.rec_id = a.rec_id',
                ['title' => 'r.title', 'subject_type' => 'r.subject_type']
            )
            ->order(['a.applied_at DESC', 'a.action_id DESC'])
            ->limit($limit);
        $actions = $connection->fetchAll($select);
        if ($actions === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => (int)$row['action_id'], $actions);
        $impacts = [];
        $impactSelect = $connection->select()
            ->from($this->table(ImpactCalculatorInterface::TABLE))
            ->where('action_id IN (?)', $ids);
        foreach ($connection->fetchAll($impactSelect) as $impact) {
            $impact['details'] = $this->decode($impact['details'] ?? null);
            $impacts[(int)$impact['action_id']] = $impact;
        }
        $out = [];
        foreach ($actions as $action) {
            $action['subjects'] = array_values(array_filter(
                $this->decode($action['subjects'] ?? null),
                'is_scalar'
            ));
            $action['impact'] = $impacts[(int)$action['action_id']] ?? null;
            $out[] = $action;
        }
        return $out;
    }

    /**
     * Subject refs of a recommendation: evidence "subjects", else [subject_ref]
     *
     * @param array $rec
     * @return string[]
     */
    private function subjects(array $rec): array
    {
        $evidence = $rec['evidence'] ?? [];
        if (is_string($evidence)) {
            $evidence = $this->decode($evidence);
        }
        $list = is_array($evidence) ? ($evidence[RecommendationRepositoryInterface::EVIDENCE_SUBJECTS] ?? []) : [];
        $out = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = self::cut((string)$item, Finding::MAX_SUBJECT_REF);
            if ($item !== '') {
                $out[$item] = true;
            }
        }
        if ($out === []) {
            $ref = self::cut((string)($rec['subject_ref'] ?? ''), Finding::MAX_SUBJECT_REF);
            return $ref === '' ? [] : [$ref];
        }
        return array_map('strval', array_keys($out));
    }

    /**
     * Decode a JSON column to an array ([] when empty or invalid)
     *
     * @param mixed $value
     * @return array
     */
    private function decode($value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = $this->json->unserialize($value);
        } catch (\InvalidArgumentException $e) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Trimmed, cut string or null when empty
     *
     * @param string|null $value
     * @param int $length
     * @return string|null
     */
    private static function nullable(?string $value, int $length): ?string
    {
        $value = self::cut((string)$value, $length);
        return $value === '' ? null : $value;
    }

    /**
     * Trim and cut a string to a number of characters
     *
     * @param string $value
     * @param int $length
     * @return string
     */
    private static function cut(string $value, int $length): string
    {
        return trim(mb_substr(trim($value), 0, $length));
    }

    /**
     * Prefixed table name
     *
     * @param string $name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
