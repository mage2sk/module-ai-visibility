<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation;

use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\RecommendationEngineInterface;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface as Repo;
use Panth\AiVisibility\Api\RecommendationRuleInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Finding;
use Psr\Log\LoggerInterface;

/**
 * Recommendation engine: runs every rule per store view and keeps panth_aiv_recommendation in sync.
 *
 * Lifecycle per store view and fingerprint (sha1 rule|store|subject):
 * - new finding                          -> created (open)
 * - open and found again                 -> updated (title, evidence, counts, score, last_seen_at)
 * - resolved and found again             -> reopened (counted as created)
 * - applied / dismissed, found again     -> left alone while the status changed less than
 *                                           SUPPRESS_DAYS days ago, reopened after that
 * - open and not found again             -> resolved (unless its rule failed in this run)
 * At most Config::recommendationsMaxOpen() open rows are kept per store view, ranked by impact score
 * (halved for rules the merchant marked "not useful" recently); lower-ranked ones are not created or
 * are resolved. A rule that throws is logged and skipped; the other rules still run and the open rows
 * of the failing rule stay untouched. Simulator rows are never changed.
 *
 * Impact score (0-100) = rule weight x max(rule's own score, volume score), where the volume score is
 * 25 x log10(1 + affected count), capped at 100 (10 -> 26, 100 -> 50, 1,000 -> 75, 10,000 -> 100).
 *
 * run() returns the counts plus, for the CLI and dry runs, "findings" (one entry per finding or
 * resolved row with its planned "action") and "errors" (failed rules or store views).
 */
class Engine implements RecommendationEngineInterface
{
    public const SUPPRESS_DAYS = 30;
    public const DEFAULT_WEIGHT = 0.6;

    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_REOPEN = 'reopen';
    public const ACTION_SUPPRESSED = 'suppressed';
    public const ACTION_OVER_LIMIT = 'over_limit';
    public const ACTION_RESOLVE = 'resolve';
    public const ACTION_SIMULATED = 'simulated';

    /**
     * Rule weights by rule code (how much a fix of this kind usually matters)
     */
    public const WEIGHTS = [
        'robots_blocks_ai_search' => 1.0,
        'bot_404' => 0.8,
        'bot_errors_or_slow' => 0.9,
        'never_crawled_products' => 0.6,
        'live_answers_no_sales' => 0.7,
        'thin_content_ai_read' => 0.5,
        'llms_not_served' => 0.6,
        'parameter_crawl_waste' => 0.5,
        'ai_visitors_out_of_stock' => 0.7,
        'discovery_backlog' => 0.4,
    ];

    /**
     * @var RecommendationRuleInterface[]
     */
    private array $rules;

    /**
     * @param Repository $repository
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param RecommendationRuleInterface[] $rules di.xml item name (rule code) => rule
     */
    public function __construct(
        private readonly Repository $repository,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        array $rules = []
    ) {
        $this->rules = array_filter(
            $rules,
            static fn ($rule): bool => $rule instanceof RecommendationRuleInterface
        );
    }

    /**
     * Registered rules: di.xml item name => rule
     *
     * @return RecommendationRuleInterface[]
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * @inheritdoc
     */
    public function run(?int $storeId = null, bool $dryRun = false): array
    {
        return $this->runAt($storeId, $dryRun, time());
    }

    /**
     * Run as of a given time (tests and replays)
     *
     * @param int|null $storeId One store view, or null for every active store view with the module enabled
     * @param bool $dryRun
     * @param int $now Unix time
     * @return array{created: int, updated: int, resolved: int, findings: array, errors: string[]}
     */
    public function runAt(?int $storeId, bool $dryRun, int $now): array
    {
        $report = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'findings' => [], 'errors' => []];
        foreach ($this->storeIds($storeId) as $id) {
            try {
                $this->runStore($id, $dryRun, $now, $report);
            } catch (\Throwable $e) {
                $report['errors'][] = sprintf('store %d: %s', $id, $e->getMessage());
                $this->logger->warning(
                    'Panth AI Visibility: recommendations failed for store ' . $id . ': ' . $e->getMessage()
                );
            }
        }
        return $report;
    }

    /**
     * Impact score of a finding (0-100)
     *
     * @param Finding $finding
     * @return int
     */
    public static function score(Finding $finding): int
    {
        $weight = self::WEIGHTS[$finding->ruleCode] ?? self::DEFAULT_WEIGHT;
        $volume = min(100.0, 25 * log10(1 + $finding->affectedCount));
        return (int)round($weight * max((float)$finding->impactScore, $volume));
    }

    /**
     * Evaluate and sync one store view
     *
     * @param int $storeId
     * @param bool $dryRun
     * @param int $now
     * @param array $report
     * @return void
     */
    private function runStore(int $storeId, bool $dryRun, int $now, array &$report): void
    {
        [$findings, $failed] = $this->evaluate($storeId, $now, $report);
        $penalised = array_flip($this->repository->penalisedRules($storeId, $now));
        $existing = $findings === [] ? [] : $this->repository->byFingerprints(array_keys($findings));
        $suppressUntil = $now - self::SUPPRESS_DAYS * 86400;
        $nowText = gmdate('Y-m-d H:i:s', $now);

        $candidates = [];
        foreach ($findings as $fingerprint => $finding) {
            $row = $existing[$fingerprint] ?? null;
            $rank = $finding->impactScore * (isset($penalised[$finding->ruleCode]) ? Repository::FEEDBACK_FACTOR : 1.0);
            $action = $this->action($row, $suppressUntil);
            $entry = ['finding' => $finding, 'action' => $action, 'rank' => $rank, 'rec_id' => $row['rec_id'] ?? null];
            if (in_array($action, [self::ACTION_SUPPRESSED, self::ACTION_SIMULATED], true)) {
                $report['findings'][] = $this->entry($entry);
                continue;
            }
            $candidates[] = $entry;
        }
        usort($candidates, static function (array $a, array $b): int {
            return [$b['rank'], $b['finding']->affectedCount, $a['finding']->fingerprint()]
                <=> [$a['rank'], $a['finding']->affectedCount, $b['finding']->fingerprint()];
        });

        $maxOpen = $this->config->recommendationsMaxOpen();
        $kept = [];
        $insert = [];
        $refresh = [];
        foreach ($candidates as $i => $entry) {
            if ($i >= $maxOpen) {
                $entry['action'] = self::ACTION_OVER_LIMIT;
                $report['findings'][] = $this->entry($entry);
                continue;
            }
            $finding = $entry['finding'];
            $kept[$finding->fingerprint()] = true;
            if ($entry['action'] === self::ACTION_UPDATE) {
                $refresh[] = $this->row($finding, $nowText);
                $report['updated']++;
            } else {
                $insert[] = $this->row($finding, $nowText);
                $report['created']++;
            }
            $report['findings'][] = $this->entry($entry);
        }

        $resolve = [];
        foreach ($this->repository->openRows($storeId) as $open) {
            if (isset($kept[$open['fingerprint']]) || isset($failed[$open['rule_code']])) {
                continue;
            }
            $resolve[] = $open['rec_id'];
            $report['findings'][] = [
                'action' => self::ACTION_RESOLVE,
                'rec_id' => $open['rec_id'],
                'fingerprint' => $open['fingerprint'],
                'rule_code' => $open['rule_code'],
                'store_id' => $storeId,
                'title' => $open['title'],
                'impact_score' => $open['impact_score'],
                'affected_count' => $open['affected_count'],
                'rank_score' => (float)$open['impact_score'],
            ];
        }
        $report['resolved'] += count($resolve);

        if ($dryRun || ($insert === [] && $refresh === [] && $resolve === [])) {
            return;
        }
        $this->repository->transactional(function () use ($insert, $refresh, $resolve, $nowText): void {
            $this->repository->save(
                $insert,
                array_merge(Repository::REFRESH_COLUMNS, ['status', 'status_changed_at'])
            );
            $this->repository->save($refresh, Repository::REFRESH_COLUMNS);
            $this->repository->resolve($resolve, $nowText);
        });
    }

    /**
     * Run every rule for a store view; one failing rule does not stop the others
     *
     * @param int $storeId
     * @param int $now
     * @param array $report
     * @return array{0: array<string, Finding>, 1: array<string, bool>} findings by fingerprint, failed rule codes
     */
    private function evaluate(int $storeId, int $now, array &$report): array
    {
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $findings = [];
        $failed = [];
        foreach ($this->rules as $name => $rule) {
            $code = $rule->code();
            try {
                $result = $rule->evaluate($storeId, $today);
            } catch (\Throwable $e) {
                $failed[$code] = true;
                $failed[(string)$name] = true;
                $report['errors'][] = sprintf('store %d, rule %s: %s', $storeId, $code, $e->getMessage());
                $this->logger->warning(sprintf(
                    'Panth AI Visibility: recommendation rule %s failed for store %d: %s',
                    $code,
                    $storeId,
                    $e->getMessage()
                ));
                continue;
            }
            foreach ($result as $finding) {
                if (!$finding instanceof Finding || $finding->ruleCode !== $code || $finding->storeId !== $storeId) {
                    continue;
                }
                $fingerprint = $finding->fingerprint();
                if (isset($findings[$fingerprint]) && $findings[$fingerprint]->affectedCount >= $finding->affectedCount) {
                    continue;
                }
                $findings[$fingerprint] = $finding->withImpactScore(self::score($finding));
            }
        }
        return [$findings, $failed];
    }

    /**
     * Planned action for a finding given its existing row
     *
     * @param array|null $row
     * @param int $suppressUntil
     * @return string
     */
    private function action(?array $row, int $suppressUntil): string
    {
        if ($row === null) {
            return self::ACTION_CREATE;
        }
        if ((int)($row['is_simulated'] ?? 0) === 1) {
            return self::ACTION_SIMULATED;
        }
        $status = (string)$row['status'];
        if ($status === Repo::STATUS_OPEN) {
            return self::ACTION_UPDATE;
        }
        if ($status === Repo::STATUS_APPLIED || $status === Repo::STATUS_DISMISSED) {
            $changed = empty($row['status_changed_at']) ? false : strtotime($row['status_changed_at'] . ' UTC');
            if ($changed === false || $changed > $suppressUntil) {
                return self::ACTION_SUPPRESSED;
            }
        }
        return self::ACTION_REOPEN;
    }

    /**
     * Table row for a finding
     *
     * @param Finding $finding
     * @param string $now
     * @return array
     */
    private function row(Finding $finding, string $now): array
    {
        $evidence = $finding->evidence;
        $evidence[Repo::EVIDENCE_SUBJECTS] = $finding->subjects;
        return [
            'fingerprint' => $finding->fingerprint(),
            'rule_code' => $finding->ruleCode,
            'store_id' => $finding->storeId,
            'subject_type' => $finding->subjectType,
            'subject_ref' => $finding->subjectRef,
            'title' => $finding->title,
            'evidence' => $evidence,
            'affected_count' => $finding->affectedCount,
            'impact_score' => $finding->impactScore,
            'metric' => $finding->metric,
            'status' => Repo::STATUS_OPEN,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'status_changed_at' => $now,
            'is_simulated' => 0,
        ];
    }

    /**
     * Report entry for a finding
     *
     * @param array $entry
     * @return array
     */
    private function entry(array $entry): array
    {
        return $entry['finding']->toArray() + [
            'action' => $entry['action'],
            'rec_id' => $entry['rec_id'],
            'rank_score' => round((float)$entry['rank'], 2),
        ];
    }

    /**
     * Store views to evaluate
     *
     * @param int|null $storeId
     * @return int[]
     */
    private function storeIds(?int $storeId): array
    {
        if ($storeId !== null) {
            return [$storeId];
        }
        $ids = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $id = (int)$store->getId();
            if ($id > 0 && (bool)$store->getIsActive() && $this->config->isEnabled($id)) {
                $ids[] = $id;
            }
        }
        sort($ids);
        return $ids;
    }
}
