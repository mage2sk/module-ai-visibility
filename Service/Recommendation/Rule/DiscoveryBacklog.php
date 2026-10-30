<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation\Rule;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\GuardedRule;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\RuleSupport;
use Panth\AiVisibility\Service\Report\ReportRepository;
use Panth\AiVisibility\Service\Rollup;
use Panth\AiVisibility\Service\VisitWriter;
use Psr\Log\LoggerInterface;

/**
 * Rule R10 (discovery_backlog): unknown AI bots and AI sources ("new" discovery candidates) that were
 * first seen more than 7 days ago and keep sending real traffic, still waiting for a decision.
 *
 * Reads panth_aiv_candidate (real rows only, at least MIN_HITS hits). For a store view, a candidate
 * counts only when its raw hits (bots) or visits (sources) of the last 30 days include that store.
 * One site finding per store view (verified with candidate_backlog).
 */
class DiscoveryBacklog extends GuardedRule
{
    public const CODE = 'discovery_backlog';

    /**
     * Candidates first seen more than this many days ago are overdue
     */
    public const WAIT_DAYS = 7;

    /**
     * Hits (bots) or visits (sources) a candidate needs to count as real traffic
     */
    public const MIN_HITS = 3;

    private const STATUS_NEW = 'new';
    private const WEIGHT = 1.5;
    private const LIST_LIMIT = 100;

    /**
     * @inheritdoc
     */
    public function code(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    public function label(): string
    {
        return (string)__('Unknown AI bots and sources wait for review');
    }

    /**
     * @inheritdoc
     */
    public function metric(): string
    {
        return MetricProviderInterface::METRIC_CANDIDATE_BACKLOG;
    }

    /**
     * @inheritdoc
     */
    protected function find(int $storeId, \DateTimeImmutable $today): array
    {
        $cutoff = RuleSupport::startOf(RuleSupport::fromDay($today, self::WAIT_DAYS + 1));
        $bind = [self::STATUS_NEW, $cutoff, self::MIN_HITS];
        $sql = 'SELECT c.candidate_id, c.type, c.key_value, c.first_seen, c.hits, c.llms_fetches'
            . ' FROM ' . $this->support->table(ReportRepository::CANDIDATE_TABLE) . ' AS c'
            . ' WHERE c.status = ? AND ' . $this->support->simulatedCondition('c.is_simulated') . ' AND c.first_seen < ? AND c.hits >= ?';
        if ($storeId > 0) {
            $since = RuleSupport::startOf(RuleSupport::fromDay($today, RuleSupport::WINDOW_DAYS));
            $sql .= ' AND ((c.type = ? AND EXISTS (SELECT 1 FROM ' . $this->support->table(Rollup::HIT_TABLE) . ' AS h'
                . ' WHERE h.bot_code = CONCAT(?, c.candidate_id) AND h.store_id = ? AND h.created_at >= ?'
                . ' AND ' . $this->support->simulatedCondition('h.is_simulated') . '))'
                . ' OR (c.type = ? AND EXISTS (SELECT 1 FROM ' . $this->support->table(VisitWriter::TABLE_VISIT) . ' AS v'
                . ' WHERE v.source_code = CONCAT(?, c.candidate_id) AND v.store_id = ? AND v.created_at >= ?'
                . ' AND ' . $this->support->simulatedCondition('v.is_simulated') . ')))';
            array_push(
                $bind,
                RuleProvider::TYPE_BOT,
                VisitWriter::CANDIDATE_PREFIX,
                $storeId,
                $since,
                RuleProvider::TYPE_SOURCE,
                VisitWriter::CANDIDATE_PREFIX,
                $storeId,
                $since
            );
        }
        $sql .= ' ORDER BY c.hits DESC, c.candidate_id ASC LIMIT ' . self::LIST_LIMIT;
        $rows = $this->support->connection()->fetchAll($sql, $bind);
        if ($rows === []) {
            return [];
        }
        $hits = 0;
        $bots = 0;
        foreach ($rows as $row) {
            $hits += (int)$row['hits'];
            $bots += (string)$row['type'] === RuleProvider::TYPE_BOT ? 1 : 0;
        }
        if ($hits < $this->support->minVolume()) {
            return [];
        }
        $count = count($rows);
        $evidence = [
            'wait_days' => self::WAIT_DAYS,
            'candidates' => $count,
            'bots' => $bots,
            'sources' => $count - $bots,
            'hits' => $hits,
            'min_hits' => self::MIN_HITS,
            'examples' => [],
        ];
        foreach (array_slice($rows, 0, RuleSupport::TOP_EXAMPLES) as $row) {
            $evidence['examples'][] = [
                'candidate_id' => (int)$row['candidate_id'],
                'type' => (string)$row['type'],
                'value' => RuleSupport::clip((string)$row['key_value']),
                'first_seen' => (string)$row['first_seen'],
                'hits' => (int)$row['hits'],
                'llms_fetches' => (int)$row['llms_fetches'],
            ];
        }
        $title = $count === 1
            ? __('1 unknown AI bot or source has waited more than %1 days for your review', self::WAIT_DAYS)
            : __('%1 unknown AI bots or sources have waited more than %2 days for your review', $count, self::WAIT_DAYS);

        return [new Finding(
            self::CODE,
            $storeId,
            Finding::SUBJECT_SITE,
            Finding::SUBJECT_SITE,
            (string)$title,
            $evidence,
            $count,
            RuleSupport::impactScore(self::WEIGHT, $hits),
            $this->metric(),
            [Finding::SUBJECT_SITE]
        )];
    }
}
