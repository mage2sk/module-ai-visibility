<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Simulator;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Model\RuleWriter;
use Panth\AiVisibility\Service\VisitWriter;

/**
 * Writes deterministic simulated AI traffic for testing, and removes it again.
 *
 * Every row it writes has is_simulated = 1 (hits also origin 'sim'). Rows come from
 * TrafficGenerator (pure, seeded) and are bulk inserted in chunks; the daily tables are then
 * rebuilt through RollupInterface. A day is not re-rolled when it has real daily rows but its
 * real raw rows were already pruned by retention, because the rollup would otherwise replace
 * those real daily rows with nothing.
 */
class Simulator
{
    public const T_HIT = 'panth_aiv_hit';
    public const T_OVERFLOW = 'panth_aiv_overflow_daily';
    public const T_CRAWL_DAILY = 'panth_aiv_crawl_daily';
    public const T_PATH_DAILY = 'panth_aiv_path_daily';
    public const T_VISIT = 'panth_aiv_visit';
    public const T_VISIT_DAILY = 'panth_aiv_visit_daily';
    public const T_LANDING = 'panth_aiv_landing';
    public const T_ORDER = 'panth_aiv_order';
    public const T_CANDIDATE = 'panth_aiv_candidate';

    /**
     * Every module table with an is_simulated column
     */
    public const SIMULATED_TABLES = [
        self::T_ORDER, self::T_HIT, self::T_VISIT, self::T_LANDING, self::T_OVERFLOW,
        self::T_CRAWL_DAILY, self::T_PATH_DAILY, self::T_VISIT_DAILY, self::T_CANDIDATE,
    ];

    /**
     * Raw tables (created_at) and daily tables (day)
     */
    private const RAW_TABLES = [self::T_HIT, self::T_VISIT, self::T_LANDING];
    private const DAILY_TABLES = [self::T_OVERFLOW, self::T_CRAWL_DAILY, self::T_PATH_DAILY, self::T_VISIT_DAILY];

    public const CHUNK_SIZE = 1000;
    public const MAX_DAYS = 400;
    public const MAX_ORDERS = 10000;

    /**
     * @param ResourceConnection $resource
     * @param RollupInterface $rollup
     * @param RuleWriter $ruleWriter
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly RollupInterface $rollup,
        private readonly RuleWriter $ruleWriter
    ) {
    }

    /**
     * Module tables that do not exist (empty when the module schema is installed)
     *
     * @return string[]
     */
    public function missingTables(): array
    {
        $connection = $this->resource->getConnection();
        $missing = [];
        foreach (array_merge(self::SIMULATED_TABLES, [RuleProvider::TABLE]) as $table) {
            if (!$connection->isTableExists($this->resource->getTableName($table))) {
                $missing[] = $table;
            }
        }
        return $missing;
    }

    /**
     * Generate simulated traffic
     *
     * @param array{days: int, seed: int, store: int, scale_permille: int, with_orders: int, force: bool,
     *     until: string} $options
     * @param Scenario|null $scenario Null for Scenario::standard() at the given scale
     * @param callable|null $progress Receives progress lines
     * @return array<string, mixed> Summary
     * @throws LocalizedException
     */
    public function simulate(array $options, ?Scenario $scenario = null, ?callable $progress = null): array
    {
        $this->assertTables();
        $days = (int) $options['days'];
        $seed = (int) $options['seed'];
        $storeId = (int) $options['store'];
        $withOrders = (int) $options['with_orders'];
        if ($days < 1 || $days > self::MAX_DAYS || $withOrders < 0 || $withOrders > self::MAX_ORDERS) {
            throw new LocalizedException(__('Invalid simulator options.'));
        }
        $scenario = $scenario ?? Scenario::standard((int) $options['scale_permille']);
        $connection = $this->resource->getConnection();
        $this->assertStore($connection, $storeId);

        $lastDayStart = TrafficGenerator::dayStart((string) $options['until']);
        $firstDayStart = $lastDayStart - ($days - 1) * 86400;
        $periodEnd = $lastDayStart + 86399;
        $period = [
            'from' => gmdate('Y-m-d H:i:s', $firstDayStart),
            'to' => gmdate('Y-m-d H:i:s', $periodEnd),
            'first_day' => gmdate('Y-m-d', $firstDayStart),
            'last_day' => gmdate('Y-m-d', $lastDayStart),
        ];

        $existing = $this->countSimulated($connection, $storeId, $period);
        $replaced = [];
        if ($existing > 0) {
            if (empty($options['force'])) {
                throw new LocalizedException(__(
                    'Simulated data already exists for store %1 between %2 and %3 (%4 raw rows). '
                    . 'Run again with --force to replace it, or with --purge to remove all simulated data.',
                    $storeId,
                    $period['first_day'],
                    $period['last_day'],
                    $existing
                ));
            }
            $replaced = $this->deletePeriod($connection, $storeId, $period);
            $this->say($progress, sprintf('Removed %d earlier simulated raw row(s) for the period.', $existing));
        }

        $paths = $this->loadPaths($connection, $storeId);
        if (!$paths['product'] && !$paths['category'] && !$paths['cms']) {
            throw new LocalizedException(__(
                'Store %1 has no product, category or CMS URL rewrites to simulate traffic on.',
                $storeId
            ));
        }

        $botCandidate = $scenario->get('bot_candidate');
        $sourceCandidate = $scenario->get('source_candidate');
        $botCandidateId = $this->ensureCandidate(
            $connection,
            RuleProvider::TYPE_BOT,
            (string) $botCandidate['user_agent'],
            sha1(strtolower((string) $botCandidate['user_agent'])),
            $period['from']
        );
        $sourceKey = VisitWriter::hostCandidateKey((string) $sourceCandidate['host']);
        $sourceCandidateId = $this->ensureCandidate(
            $connection,
            RuleProvider::TYPE_SOURCE,
            $sourceKey,
            VisitWriter::candidateKeyHash($sourceKey),
            $period['from']
        );

        $generator = new TrafficGenerator($scenario, $seed, $storeId, $paths, $periodEnd);
        $totals = [
            'hits' => 0, 'live_fetches' => 0, 'clicks' => 0, 'hidden' => 0, 'hidden_sampled' => 0,
            'visits' => 0, 'landings' => 0, 'candidate_hits' => 0, 'candidate_visits' => 0,
        ];
        $buffers = [self::T_HIT => [], self::T_VISIT => [], self::T_LANDING => []];
        $generatedDays = [];
        for ($index = 0; $index < $days; $index++) {
            $day = gmdate('Y-m-d', $firstDayStart + $index * 86400);
            $generatedDays[] = $day;
            $result = $generator->generateDay($day, $index, $days, $botCandidateId, $sourceCandidateId);
            foreach ($result['stats'] as $key => $value) {
                $totals[$key] += $value;
            }
            foreach ([self::T_HIT => 'hits', self::T_VISIT => 'visits', self::T_LANDING => 'landings'] as $t => $k) {
                foreach ($result[$k] as $row) {
                    $buffers[$t][] = $row;
                }
                $this->flush($connection, $t, $buffers[$t], false);
            }
        }
        foreach (array_keys($buffers) as $table) {
            $this->flush($connection, $table, $buffers[$table], true);
        }
        $this->say($progress, sprintf('Generated %d day(s) of simulated traffic.', $days));

        $orders = $withOrders > 0 ? $this->attributeOrders($connection, $generator, $storeId, $withOrders) : 0;
        $candidates = [
            'bot' => $botCandidateId === null ? null : $this->refreshCandidate($connection, $botCandidateId, true),
            'source' => $sourceCandidateId === null
                ? null
                : $this->refreshCandidate($connection, $sourceCandidateId, false),
        ];

        $rolled = $this->rollupDays($connection, $generatedDays, $storeId, $progress);

        return [
            'store' => $storeId,
            'seed' => $seed,
            'scale_permille' => $scenario->scalePermille(),
            'first_day' => $period['first_day'],
            'last_day' => $period['last_day'],
            'days' => $days,
            'replaced' => $replaced,
            'hits' => $totals['hits'],
            'live_fetches' => $totals['live_fetches'],
            'visits' => $totals['visits'],
            'landings' => $totals['landings'],
            'ai_clicks' => $totals['clicks'],
            'hidden_clicks' => $totals['hidden'],
            'hidden_landings' => $totals['hidden_sampled'],
            'candidate_hits' => $totals['candidate_hits'],
            'candidate_visits' => $totals['candidate_visits'],
            'bot_candidate' => $candidates['bot'],
            'source_candidate' => $candidates['source'],
            'bot_candidate_ua' => (string) $botCandidate['user_agent'],
            'source_candidate_host' => (string) $sourceCandidate['host'],
            'orders' => $orders,
            'rolled_up' => $rolled['rolled'],
            'rollup_skipped' => $rolled['skipped'],
            'rollup_failed' => $rolled['failed'],
        ];
    }

    /**
     * Remove every simulated row from every module table and re-roll the affected days
     *
     * Rules confirmed in Discovery from a simulated candidate are removed as well.
     *
     * @param callable|null $progress
     * @return array<string, mixed> Summary: deleted (table => rows), rules, rolled_up, rollup_skipped, rollup_failed
     * @throws LocalizedException
     */
    public function purge(?callable $progress = null): array
    {
        $this->assertTables();
        $connection = $this->resource->getConnection();
        $affected = $this->affectedDays($connection);

        $candidateIds = array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->table(self::T_CANDIDATE), ['candidate_id'])
                ->where('is_simulated = ?', 1)
        ));
        $rules = 0;
        if ($candidateIds) {
            $ruleIds = $connection->fetchCol(
                $connection->select()
                    ->from($this->table(RuleProvider::TABLE), ['rule_id'])
                    ->where('candidate_id IN (?)', $candidateIds)
            );
            foreach ($ruleIds as $ruleId) {
                $this->ruleWriter->delete((int) $ruleId);
                $rules++;
            }
        }

        $deleted = [];
        foreach (self::SIMULATED_TABLES as $table) {
            $deleted[$table] = (int) $connection->delete($this->table($table), ['is_simulated = ?' => 1]);
        }
        $this->say($progress, sprintf('Deleted %d simulated row(s).', array_sum($deleted)));

        $rolled = 0;
        $skipped = [];
        $failed = [];
        foreach ($affected as $storeId => $days) {
            $result = $this->rollupDays($connection, $days, (int) $storeId, $progress);
            $rolled += $result['rolled'];
            $skipped = array_merge($skipped, $result['skipped']);
            $failed = array_merge($failed, $result['failed']);
        }

        return [
            'deleted' => $deleted,
            'rules' => $rules,
            'rolled_up' => $rolled,
            'rollup_skipped' => $skipped,
            'rollup_failed' => $failed,
        ];
    }

    /**
     * Throw when the module schema is not installed
     *
     * @return void
     * @throws LocalizedException
     */
    private function assertTables(): void
    {
        $missing = $this->missingTables();
        if ($missing) {
            throw new LocalizedException(__(
                'The AI Visibility tables do not exist (%1). Install the module with bin/magento setup:upgrade first.',
                implode(', ', $missing)
            ));
        }
    }

    /**
     * Throw unless the store view exists (and is not the admin store)
     *
     * @param AdapterInterface $connection
     * @param int $storeId
     * @return void
     * @throws LocalizedException
     */
    private function assertStore(AdapterInterface $connection, int $storeId): void
    {
        $found = $storeId > 0 ? $connection->fetchOne(
            $connection->select()
                ->from($this->table('store'), ['store_id'])
                ->where('store_id = ?', $storeId)
        ) : false;
        if ($found === false || $found === null) {
            throw new LocalizedException(__('Store view %1 does not exist.', $storeId));
        }
    }

    /**
     * Simulated raw rows of a store in a period
     *
     * @param AdapterInterface $connection
     * @param int $storeId
     * @param array<string, string> $period
     * @return int
     */
    private function countSimulated(AdapterInterface $connection, int $storeId, array $period): int
    {
        $total = 0;
        foreach (self::RAW_TABLES as $table) {
            $total += (int) $connection->fetchOne(
                $connection->select()
                    ->from($this->table($table), ['n' => new Expression('COUNT(*)')])
                    ->where('is_simulated = ?', 1)
                    ->where('store_id = ?', $storeId)
                    ->where('created_at >= ?', $period['from'])
                    ->where('created_at <= ?', $period['to'])
            );
        }
        return $total;
    }

    /**
     * Delete the simulated rows of a store in a period (--force)
     *
     * Simulated order attributions of the store are removed too, so --with-orders can pick again.
     * Candidates are kept (their counters are recomputed after the run).
     *
     * @param AdapterInterface $connection
     * @param int $storeId
     * @param array<string, string> $period
     * @return array<string, int> table => deleted rows
     */
    private function deletePeriod(AdapterInterface $connection, int $storeId, array $period): array
    {
        $deleted = [];
        foreach (self::RAW_TABLES as $table) {
            $deleted[$table] = (int) $connection->delete($this->table($table), [
                'is_simulated = ?' => 1,
                'store_id = ?' => $storeId,
                'created_at >= ?' => $period['from'],
                'created_at <= ?' => $period['to'],
            ]);
        }
        foreach (self::DAILY_TABLES as $table) {
            $deleted[$table] = (int) $connection->delete($this->table($table), [
                'is_simulated = ?' => 1,
                'store_id = ?' => $storeId,
                'day >= ?' => $period['first_day'],
                'day <= ?' => $period['last_day'],
            ]);
        }
        $orderIds = $connection->fetchCol(
            $connection->select()
                ->from(['a' => $this->table(self::T_ORDER)], ['order_id'])
                ->join(['o' => $this->table('sales_order')], 'o.entity_id = a.order_id', [])
                ->where('a.is_simulated = ?', 1)
                ->where('o.store_id = ?', $storeId)
        );
        $deleted[self::T_ORDER] = 0;
        foreach (array_chunk($orderIds, self::CHUNK_SIZE) as $chunk) {
            $deleted[self::T_ORDER] += (int) $connection->delete($this->table(self::T_ORDER), [
                'is_simulated = ?' => 1,
                'order_id IN (?)' => array_map('intval', $chunk),
            ]);
        }
        return $deleted;
    }

    /**
     * Product, category and CMS paths of the store from url_rewrite (direct rewrites only)
     *
     * @param AdapterInterface $connection
     * @param int $storeId
     * @return array<string, string[]> product|category|cms => ['/path', ...] sorted by path
     */
    private function loadPaths(AdapterInterface $connection, int $storeId): array
    {
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->table('url_rewrite'), ['request_path', 'entity_type'])
                ->where('store_id = ?', $storeId)
                ->where('redirect_type = ?', 0)
                ->where('entity_type IN (?)', ['product', 'category', 'cms-page'])
                ->order('request_path ASC')
        );
        $paths = ['product' => [], 'category' => [], 'cms' => []];
        foreach ($rows as $row) {
            $path = '/' . ltrim((string) $row['request_path'], '/');
            if ($path === '/' || strlen($path) > 512 || strpos($path, '?') !== false) {
                continue;
            }
            $kind = $row['entity_type'] === 'cms-page' ? 'cms' : (string) $row['entity_type'];
            $paths[$kind][$path] = $path;
        }
        foreach ($paths as $kind => $list) {
            ksort($list, SORT_STRING);
            $paths[$kind] = array_values($list);
        }
        return $paths;
    }

    /**
     * Candidate ID for a planted candidate, inserting it when missing
     *
     * Returns null (planted candidate skipped) when a real, non-simulated candidate already
     * has the same key, so real discovery data is never changed.
     *
     * @param AdapterInterface $connection
     * @param string $type
     * @param string $keyValue
     * @param string $keyHash
     * @param string $seenAt
     * @return int|null
     */
    private function ensureCandidate(
        AdapterInterface $connection,
        string $type,
        string $keyValue,
        string $keyHash,
        string $seenAt
    ): ?int {
        $table = $this->table(self::T_CANDIDATE);
        $find = $connection->select()
            ->from($table, ['candidate_id', 'is_simulated'])
            ->where('type = ?', $type)
            ->where('key_hash = ?', $keyHash);
        $row = $connection->fetchRow($find);
        if (!$row) {
            $connection->insert($table, [
                'type' => $type,
                'key_hash' => $keyHash,
                'key_value' => substr($keyValue, 0, 512),
                'first_seen' => $seenAt,
                'last_seen' => $seenAt,
                'hits' => 0,
                'llms_fetches' => 0,
                'robots_fetches' => 0,
                'evidence' => 0,
                'status' => 'new',
                'is_simulated' => 1,
            ]);
            $row = $connection->fetchRow($find);
        }
        if (!$row || (int) $row['is_simulated'] !== 1) {
            return null;
        }
        return (int) $row['candidate_id'];
    }

    /**
     * Recompute a planted candidate's counters from all its simulated rows
     *
     * @param AdapterInterface $connection
     * @param int $candidateId
     * @param bool $isBot
     * @return int Candidate ID
     */
    private function refreshCandidate(AdapterInterface $connection, int $candidateId, bool $isBot): int
    {
        $code = \Panth\AiVisibility\Api\HitWriterInterface::CANDIDATE_PREFIX . $candidateId;
        if ($isBot) {
            $stats = $connection->fetchRow(
                $connection->select()
                    ->from($this->table(self::T_HIT), [
                        'hits' => new Expression('COUNT(*)'),
                        'llms' => new Expression("SUM(path = '/llms.txt')"),
                        'robots' => new Expression("SUM(path = '/robots.txt')"),
                        'first_seen' => new Expression('MIN(created_at)'),
                        'last_seen' => new Expression('MAX(created_at)'),
                    ])
                    ->where('bot_code = ?', $code)
                    ->where('is_simulated = ?', 1)
            );
        } else {
            $stats = $connection->fetchRow(
                $connection->select()
                    ->from($this->table(self::T_VISIT), [
                        'hits' => new Expression('COUNT(*)'),
                        'first_seen' => new Expression('MIN(created_at)'),
                        'last_seen' => new Expression('MAX(created_at)'),
                    ])
                    ->where('source_code = ?', $code)
                    ->where('is_simulated = ?', 1)
            );
        }
        $data = [
            'hits' => (int) ($stats['hits'] ?? 0),
            'llms_fetches' => (int) ($stats['llms'] ?? 0),
            'robots_fetches' => (int) ($stats['robots'] ?? 0),
        ];
        if (!empty($stats['first_seen'])) {
            $data['first_seen'] = (string) $stats['first_seen'];
            $data['last_seen'] = (string) $stats['last_seen'];
        }
        $connection->update(
            $this->table(self::T_CANDIDATE),
            $data,
            ['candidate_id = ?' => $candidateId, 'is_simulated = ?' => 1]
        );
        return $candidateId;
    }

    /**
     * Attribute up to $limit of the store's newest orders that have no attribution row yet
     *
     * @param AdapterInterface $connection
     * @param TrafficGenerator $generator
     * @param int $storeId
     * @param int $limit
     * @return int Orders attributed
     */
    private function attributeOrders(
        AdapterInterface $connection,
        TrafficGenerator $generator,
        int $storeId,
        int $limit
    ): int {
        $orders = $connection->fetchAll(
            $connection->select()
                ->from(['o' => $this->table('sales_order')], ['entity_id', 'created_at'])
                ->joinLeft(['a' => $this->table(self::T_ORDER)], 'a.order_id = o.entity_id', [])
                ->where('a.order_id IS NULL')
                ->where('o.store_id = ?', $storeId)
                ->order(['o.created_at DESC', 'o.entity_id DESC'])
                ->limit($limit)
        );
        $rows = [];
        foreach ($orders as $order) {
            $orderTs = (new \DateTimeImmutable((string) $order['created_at'], new \DateTimeZone('UTC')))
                ->getTimestamp();
            $rows[] = $generator->orderAttribution((int) $order['entity_id'], $orderTs);
        }
        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            // ON DUPLICATE KEY UPDATE order_id = order_id: a real row written meanwhile stays untouched.
            $connection->insertOnDuplicate($this->table(self::T_ORDER), $chunk, ['order_id']);
        }
        return count($rows);
    }

    /**
     * Re-roll days through RollupInterface, skipping days where that would lose real data
     *
     * @param AdapterInterface $connection
     * @param string[] $days
     * @param int|null $storeId
     * @param callable|null $progress
     * @return array{rolled: int, skipped: string[], failed: string[]}
     */
    private function rollupDays(AdapterInterface $connection, array $days, ?int $storeId, ?callable $progress): array
    {
        sort($days, SORT_STRING);
        $days = array_values(array_unique($days));
        // RollupInterface::rollupDay() works per (store, is_simulated) slice and never rebuilds real rows
        // whose raw rows were pruned, so every day can be rolled up; "skipped" stays for the output format.
        $rolled = 0;
        $skipped = [];
        $failed = [];
        foreach ($days as $day) {
            try {
                $this->rollup->rollupDay($day, $storeId);
                $rolled++;
            } catch (\Throwable $e) {
                $failed[] = $day . ': ' . $e->getMessage();
            }
        }
        $this->say($progress, sprintf('Rolled up %d day(s).', $rolled));
        return ['rolled' => $rolled, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Days that have real daily rows but no real raw rows any more (raw rows pruned); informational
     *
     * @param AdapterInterface $connection
     * @param string[] $days Sorted
     * @param int|null $storeId
     * @return array<string, true>
     */
    public function unsafeDays(AdapterInterface $connection, array $days, ?int $storeId): array
    {
        $first = reset($days);
        $last = end($days);
        $pairs = [
            [self::T_CRAWL_DAILY, self::T_HIT],
            [self::T_VISIT_DAILY, self::T_VISIT],
        ];
        $unsafe = [];
        foreach ($pairs as [$dailyTable, $rawTable]) {
            $daily = $connection->select()
                ->distinct()
                ->from($this->table($dailyTable), ['day'])
                ->where('is_simulated = ?', 0)
                ->where('day >= ?', $first)
                ->where('day <= ?', $last);
            $raw = $connection->select()
                ->distinct()
                ->from($this->table($rawTable), ['d' => new Expression('DATE(created_at)')])
                ->where('is_simulated = ?', 0)
                ->where('created_at >= ?', $first . ' 00:00:00')
                ->where('created_at <= ?', $last . ' 23:59:59');
            if ($storeId !== null) {
                $daily->where('store_id = ?', $storeId);
                $raw->where('store_id = ?', $storeId);
            }
            $withRaw = array_flip(array_map('strval', $connection->fetchCol($raw)));
            foreach ($connection->fetchCol($daily) as $day) {
                $day = (string) $day;
                if (!isset($withRaw[$day]) && in_array($day, $days, true)) {
                    $unsafe[$day] = true;
                }
            }
        }
        return $unsafe;
    }

    /**
     * Days with simulated rows, per store: store_id => ['Y-m-d', ...]
     *
     * @param AdapterInterface $connection
     * @return array<int, string[]>
     */
    private function affectedDays(AdapterInterface $connection): array
    {
        $affected = [];
        $selects = [];
        foreach (self::RAW_TABLES as $table) {
            $selects[] = $connection->select()
                ->distinct()
                ->from($this->table($table), ['store_id', 'day' => new Expression('DATE(created_at)')])
                ->where('is_simulated = ?', 1);
        }
        foreach (self::DAILY_TABLES as $table) {
            $selects[] = $connection->select()
                ->distinct()
                ->from($this->table($table), ['store_id', 'day'])
                ->where('is_simulated = ?', 1);
        }
        foreach ($selects as $select) {
            foreach ($connection->fetchAll($select) as $row) {
                $affected[(int) $row['store_id']][(string) $row['day']] = (string) $row['day'];
            }
        }
        ksort($affected);
        return array_map('array_values', $affected);
    }

    /**
     * Insert buffered rows in chunks of CHUNK_SIZE (all of them when $all)
     *
     * @param AdapterInterface $connection
     * @param string $table
     * @param array $buffer Emptied as rows are written
     * @param bool $all
     * @return void
     */
    private function flush(AdapterInterface $connection, string $table, array &$buffer, bool $all): void
    {
        while (count($buffer) >= self::CHUNK_SIZE || ($all && $buffer)) {
            $chunk = array_splice($buffer, 0, self::CHUNK_SIZE);
            $connection->insertMultiple($this->table($table), $chunk);
        }
    }

    /**
     * Resolved table name
     *
     * @param string $name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    /**
     * Send a progress line
     *
     * @param callable|null $progress
     * @param string $line
     * @return void
     */
    private function say(?callable $progress, string $line): void
    {
        if ($progress !== null) {
            $progress($line);
        }
    }
}
