<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\ViewModel\Recommendation;

use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Read side of the recommendations page and dashboard cards.
 *
 * Never throws: a failing repository is logged and shows as an empty list, so the admin page and the
 * dashboard always render.
 */
class DataSource
{
    /**
     * Actions read to find the latest real ones (simulated actions are skipped)
     */
    public const ACTION_SCAN_LIMIT = 500;

    /**
     * @param RecommendationRepositoryInterface $repository
     * @param ActionLogInterface $actionLog
     * @param Presenter $presenter
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RecommendationRepositoryInterface $repository,
        private readonly ActionLogInterface $actionLog,
        private readonly Presenter $presenter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Open recommendations, ranked best first; evidence is always an array
     *
     * @param int|null $storeId
     * @param int $limit
     * @return array[]
     */
    public function openRecommendations(?int $storeId, int $limit): array
    {
        try {
            $rows = $this->repository->listOpen($storeId, max(1, $limit));
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] recommendations could not be read: ' . $e->getMessage());
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int)($row['rec_id'] ?? 0) <= 0) {
                continue;
            }
            $result[] = $this->normalizeRecommendation($row);
        }
        return $result;
    }

    /**
     * Number of recommendations per status (every status present)
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(RecommendationRepositoryInterface::STATUSES, 0);
        try {
            foreach ($this->repository->counts() as $status => $count) {
                if (isset($counts[$status])) {
                    $counts[$status] = max(0, (int)$count);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] recommendation counts could not be read: ' . $e->getMessage());
        }
        return $counts;
    }

    /**
     * Latest real applied actions, newest first, each with "title", "impact" (array|null) and "subjects" (list)
     *
     * The admin is real-only (like the open list and the counts): actions planted by the simulator
     * (is_simulated = 1) are left out, so they never show an Undo button or count as a verdict.
     *
     * @param int $limit
     * @return array[]
     */
    public function recentActions(int $limit): array
    {
        $limit = max(1, $limit);
        try {
            $rows = $this->actionLog->listRecent(max($limit, self::ACTION_SCAN_LIMIT));
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] applied actions could not be read: ' . $e->getMessage());
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int)($row['action_id'] ?? 0) <= 0 || !empty($row['is_simulated'])) {
                continue;
            }
            $row['impact'] = is_array($row['impact'] ?? null) ? $row['impact'] : null;
            $row['title'] = $this->actionTitle($row);
            $row['subjects'] = $this->decodeList($row['subjects'] ?? null);
            $row['status'] = (string)($row['status'] ?? ActionLogInterface::STATUS_PENDING);
            $result[] = $row;
            if (count($result) >= $limit) {
                break;
            }
        }
        return $result;
    }

    /**
     * Recommendation row with evidence decoded to an array
     *
     * @param array $row
     * @return array
     */
    private function normalizeRecommendation(array $row): array
    {
        $evidence = $row['evidence'] ?? [];
        if (is_string($evidence)) {
            $evidence = json_decode($evidence, true);
        }
        $row['evidence'] = is_array($evidence) ? $evidence : [];
        $row['title'] = trim((string)($row['title'] ?? ''));
        if ($row['title'] === '') {
            $row['title'] = $this->presenter->ruleLabel((string)($row['rule_code'] ?? ''));
        }
        return $row;
    }

    /**
     * Title of an action: the recommendation's (joined by listRecent()), or the rule name
     *
     * @param array $action
     * @return string
     */
    private function actionTitle(array $action): string
    {
        // listRecent() joins the recommendation title, so no per-row lookup is needed here.
        $title = trim((string)($action['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }
        return $this->presenter->ruleLabel((string)($action['rule_code'] ?? ''));
    }

    /**
     * JSON or array list of strings
     *
     * @param mixed $value
     * @return string[]
     */
    private function decodeList($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map('strval', array_filter($value, 'is_scalar')));
    }
}
