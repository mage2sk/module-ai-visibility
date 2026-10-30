<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Simulator;

/**
 * Pure row generator for one simulated UTC day (no database access).
 *
 * Every random decision comes from a Prng stream keyed by the day and the scenario element
 * ("2026-01-31|bot:gptbot", "...|click:chatgpt_user", ...), so the output depends only on the
 * seed, the scenario, the store's path list and the day, never on the order days are generated.
 * Rows use the exact column names of panth_aiv_hit, panth_aiv_visit and panth_aiv_landing.
 */
class TrafficGenerator
{
    public const ORIGIN = 'sim';
    public const PAGE_KINDS = ['product', 'category', 'cms'];

    private const FIXED_PATHS = [
        'robots' => '/robots.txt',
        'llms' => '/llms.txt',
        'sitemap' => '/sitemap.xml',
        'home' => '/',
    ];

    /**
     * @param Scenario $scenario
     * @param int $seed
     * @param int $storeId
     * @param array<string, string[]> $paths product|category|cms => list of URL paths ('/...')
     * @param int $periodEnd Unix time of the last second of the simulated period
     */
    public function __construct(
        private readonly Scenario $scenario,
        private readonly int $seed,
        private readonly int $storeId,
        private readonly array $paths,
        private readonly int $periodEnd
    ) {
        $hasPage = false;
        foreach (self::PAGE_KINDS as $kind) {
            $hasPage = $hasPage || !empty($this->paths[$kind]);
        }
        if (!$hasPage) {
            throw new \InvalidArgumentException('At least one store page path is required.');
        }
    }

    /**
     * Generate all raw rows of one day
     *
     * @param string $day UTC day 'Y-m-d'
     * @param int $dayIndex 0 for the first simulated day
     * @param int $totalDays Number of simulated days
     * @param int|null $botCandidateId Planted bot candidate, null to skip it
     * @param int|null $sourceCandidateId Planted source candidate, null to skip it
     * @return array{hits: array, visits: array, landings: array, stats: array<string, int>}
     */
    public function generateDay(
        string $day,
        int $dayIndex,
        int $totalDays,
        ?int $botCandidateId = null,
        ?int $sourceCandidateId = null
    ): array {
        $dayStart = self::dayStart($day);
        $weekday = (int) gmdate('N', $dayStart);
        $stats = [
            'hits' => 0, 'live_fetches' => 0, 'clicks' => 0, 'hidden' => 0, 'hidden_sampled' => 0,
            'visits' => 0, 'landings' => 0, 'candidate_hits' => 0, 'candidate_visits' => 0,
        ];
        $hits = [];
        $visits = [];
        $landings = [];
        $liveFetches = [];

        foreach ($this->scenario->bots() as $code => $bot) {
            $prng = new Prng($this->seed, $day . '|bot:' . $code);
            $count = $this->dailyCount($prng, (int) $bot['per_day'], $dayIndex, $weekday);
            for ($i = 0; $i < $count; $i++) {
                $ts = $this->timeOfDay($prng, $dayStart, (string) $bot['hours']);
                [$path, $kind] = $this->botPath($prng, $bot);
                $status = $this->status($prng, $bot['status']);
                if ($status === 404) {
                    $path = '/retired-item-' . $prng->int(1, (int) $this->scenario->get('status_404_paths')) . '.html';
                    $kind = 'missing';
                }
                $ms = $status >= 500
                    ? $prng->int(1500, 9000)
                    : $prng->skewedLow((int) $bot['ms'][0], (int) $bot['ms'][1]);
                $hasQuery = in_array($kind, self::PAGE_KINDS, true) && $prng->chance((int) $bot['query_permille']);
                $hits[] = $this->hitRow($ts, (string) $code, (string) $bot['category'], $path, $hasQuery, $status, $ms);
                if ($bot['category'] === 'live' && $status === 200 && in_array($kind, self::PAGE_KINDS, true)) {
                    $liveFetches[$code][] = [$ts, $path];
                }
            }
        }

        $allLive = [];
        foreach ($this->scenario->bots() as $code => $bot) {
            foreach ($liveFetches[$code] ?? [] as $fetch) {
                $allLive[] = $fetch;
            }
            $source = $bot['source'] ?? null;
            if ($source === null || !isset($this->scenario->sources()[$source])) {
                continue;
            }
            $prng = new Prng($this->seed, $day . '|click:' . $code);
            foreach ($liveFetches[$code] ?? [] as [$ts, $path]) {
                if (!$prng->chance((int) $bot['click_permille'])) {
                    continue;
                }
                $at = $ts + $this->lag($prng);
                if ($at > $this->periodEnd) {
                    continue;
                }
                $stats['clicks']++;
                if ($prng->chance((int) $this->scenario->get('hidden_permille'))) {
                    $stats['hidden']++;
                    if ($prng->chance((int) $this->scenario->get('landing_sample_permille'))) {
                        $stats['hidden_sampled']++;
                        $landings[] = $this->landingRow($at, $path);
                    }
                    continue;
                }
                $visits[] = $this->visitRow($prng, $at, (string) $source, $path);
            }
        }
        $stats['live_fetches'] = count($allLive);

        foreach ($this->scenario->sources() as $code => $source) {
            $prng = new Prng($this->seed, $day . '|src:' . $code);
            $count = $this->dailyCount($prng, (int) $source['direct_per_day'], $dayIndex, $weekday);
            for ($i = 0; $i < $count; $i++) {
                $ts = $this->timeOfDay($prng, $dayStart, 'human');
                $visits[] = $this->visitRow($prng, $ts, (string) $code, $this->pagePath($prng, null));
            }
        }

        $prng = new Prng($this->seed, $day . '|land');
        $count = $this->dailyCount($prng, (int) $this->scenario->get('direct_landings_per_day'), $dayIndex, $weekday);
        for ($i = 0; $i < $count; $i++) {
            $ts = $this->timeOfDay($prng, $dayStart, 'human');
            $path = $this->pagePath($prng, null);
            if ($prng->chance((int) $this->scenario->get('landing_sample_permille'))) {
                $landings[] = $this->landingRow($ts, $path);
            }
        }

        $candidate = $this->scenario->get('bot_candidate');
        if ($botCandidateId !== null && $dayIndex >= $totalDays - (int) $candidate['last_days']) {
            $prng = new Prng($this->seed, $day . '|candbot');
            $plan = [
                ['/llms.txt', (int) $candidate['llms_per_day']],
                ['/robots.txt', (int) $candidate['robots_per_day']],
                [null, (int) $candidate['pages_per_day']],
            ];
            foreach ($plan as [$fixedPath, $times]) {
                for ($i = 0; $i < $times; $i++) {
                    $ts = $this->timeOfDay($prng, $dayStart, 'flat');
                    $path = $fixedPath ?? $this->pagePath($prng, null);
                    $ms = $prng->skewedLow((int) $candidate['ms'][0], (int) $candidate['ms'][1]);
                    $hits[] = $this->hitRow($ts, \Panth\AiVisibility\Api\HitWriterInterface::CANDIDATE_PREFIX . $botCandidateId, 'unknown', $path, false, 200, $ms);
                    $stats['candidate_hits']++;
                }
            }
        }

        $candidate = $this->scenario->get('source_candidate');
        if ($sourceCandidateId !== null && $dayIndex >= $totalDays - (int) $candidate['last_days']) {
            $prng = new Prng($this->seed, $day . '|candsrc');
            for ($i = 0; $i < (int) $candidate['visits_per_day']; $i++) {
                if ($allLive) {
                    [$ts, $path] = $allLive[$prng->int(0, count($allLive) - 1)];
                    $at = $ts + $this->lag($prng);
                } else {
                    $at = $this->timeOfDay($prng, $dayStart, 'human');
                    $path = $this->pagePath($prng, null);
                }
                if ($at > $this->periodEnd) {
                    continue;
                }
                $visits[] = [
                    'created_at' => gmdate('Y-m-d H:i:s', $at),
                    'store_id' => $this->storeId,
                    'source_code' => \Panth\AiVisibility\Api\HitWriterInterface::CANDIDATE_PREFIX . $sourceCandidateId,
                    'medium' => 'referrer',
                    'referrer_host' => (string) $candidate['host'],
                    'landing_path' => $path,
                    'path_hash' => sha1($path),
                    'is_simulated' => 1,
                ];
                $stats['candidate_visits']++;
            }
        }

        $byTime = static fn(array $a, array $b): int => strcmp($a['created_at'], $b['created_at']);
        usort($hits, $byTime);
        usort($visits, $byTime);
        usort($landings, $byTime);
        $stats['hits'] = count($hits);
        $stats['visits'] = count($visits);
        $stats['landings'] = count($landings);

        return ['hits' => $hits, 'visits' => $visits, 'landings' => $landings, 'stats' => $stats];
    }

    /**
     * Attribution row for an existing order (--with-orders)
     *
     * Keyed by the order ID, so an order always gets the same attribution for a seed.
     *
     * @param int $orderId sales_order.entity_id
     * @param int $orderTs Order creation time (unix, UTC)
     * @return array<string, int|string|null> Row for panth_aiv_order
     */
    public function orderAttribution(int $orderId, int $orderTs): array
    {
        $prng = new Prng($this->seed, 'order:' . $orderId);
        $weights = array_intersect_key(
            (array) $this->scenario->get('order_sources'),
            $this->scenario->sources()
        );
        $first = (string) $prng->weighted($weights);
        $last = $prng->chance((int) $this->scenario->get('order_switch_permille'))
            ? (string) $prng->weighted($weights)
            : $first;
        $lag = $this->scenario->get('order_lag');
        $firstAt = $orderTs - $prng->int((int) $lag['min'], (int) $lag['max']);
        $lastAt = $last === $first ? $firstAt : $prng->int($firstAt, $orderTs);
        return [
            'order_id' => $orderId,
            'channel' => 'referral',
            'first_source' => $first,
            'first_at' => gmdate('Y-m-d H:i:s', $firstAt),
            'last_source' => $last,
            'last_at' => gmdate('Y-m-d H:i:s', $lastAt),
            'landing_path' => $this->pagePath($prng, ['product' => 1, 'category' => 0, 'cms' => 0]),
            'probability' => null,
            'created_at' => gmdate('Y-m-d H:i:s', $orderTs),
            'is_simulated' => 1,
        ];
    }

    /**
     * Unix time of 00:00:00 UTC of a 'Y-m-d' day
     *
     * @param string $day
     * @return int
     */
    public static function dayStart(string $day): int
    {
        return (new \DateTimeImmutable($day . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * Count for a base rate: exact expected value with random rounding, then +/- noise
     *
     * @param Prng $prng
     * @param int $perDay
     * @param int $dayIndex
     * @param int $weekday
     * @return int
     */
    public function dailyCount(Prng $prng, int $perDay, int $dayIndex, int $weekday): int
    {
        [$whole, $remainder] = $this->scenario->expected($perDay, $dayIndex, $weekday);
        if ($remainder > 0 && $prng->int(0, Scenario::denominator() - 1) < $remainder) {
            $whole++;
        }
        $spread = intdiv($whole * (int) $this->scenario->get('noise_percent'), 100);
        return max(0, $whole + $prng->int(-$spread, $spread));
    }

    /**
     * Seconds between a live-answer fetch and the click, skewed towards short lags
     *
     * @param Prng $prng
     * @return int
     */
    public function lag(Prng $prng): int
    {
        $lag = $this->scenario->get('lag');
        return $prng->skewedLow((int) $lag['min'], (int) $lag['max']);
    }

    /**
     * Random second of the day following an hourly rhythm
     *
     * @param Prng $prng
     * @param int $dayStart
     * @param string $profile
     * @return int
     */
    private function timeOfDay(Prng $prng, int $dayStart, string $profile): int
    {
        $hour = (int) $prng->weighted($this->scenario->hourWeights($profile));
        return $dayStart + $hour * 3600 + $prng->int(0, 3599);
    }

    /**
     * Path a bot fetches: a fixed file (robots, llms, sitemap, home) or a store page
     *
     * @param Prng $prng
     * @param array<string, mixed> $bot
     * @return array{0: string, 1: string} [path, kind]
     */
    private function botPath(Prng $prng, array $bot): array
    {
        $roll = $prng->int(0, 999);
        foreach ($bot['mix'] as $kind => $permille) {
            $roll -= (int) $permille;
            if ($roll < 0) {
                return [self::FIXED_PATHS[$kind], (string) $kind];
            }
        }
        $kind = $this->pageKind($prng, $bot['types']);
        return [$this->pickPath($prng, $kind), $kind];
    }

    /**
     * Random store page path
     *
     * @param Prng $prng
     * @param array<string, int>|null $types Kind weights, null for the default shopper mix
     * @return string
     */
    private function pagePath(Prng $prng, ?array $types): string
    {
        $kind = $this->pageKind($prng, $types ?? ['product' => 700, 'category' => 200, 'cms' => 100]);
        return $this->pickPath($prng, $kind);
    }

    /**
     * Page kind by weight, ignoring kinds the store has no paths for
     *
     * @param Prng $prng
     * @param array<string, int> $types
     * @return string
     */
    private function pageKind(Prng $prng, array $types): string
    {
        $weights = [];
        foreach (self::PAGE_KINDS as $kind) {
            if (!empty($this->paths[$kind])) {
                $weights[$kind] = max(0, (int) ($types[$kind] ?? 0));
            }
        }
        if (array_sum($weights) <= 0) {
            $weights = array_fill_keys(array_keys($weights), 1);
        }
        return (string) $prng->weighted($weights);
    }

    /**
     * Path of a kind with a popular head (low indexes are picked more often)
     *
     * @param Prng $prng
     * @param string $kind
     * @return string
     */
    private function pickPath(Prng $prng, string $kind): string
    {
        $list = $this->paths[$kind];
        return $list[$prng->skewedLow(0, count($list) - 1)];
    }

    /**
     * HTTP status from a per mille mix; the remainder is 200
     *
     * @param Prng $prng
     * @param array<int, int> $mix
     * @return int
     */
    private function status(Prng $prng, array $mix): int
    {
        $roll = $prng->int(0, 999);
        foreach ($mix as $status => $permille) {
            $roll -= (int) $permille;
            if ($roll < 0) {
                return (int) $status;
            }
        }
        return 200;
    }

    /**
     * Row for panth_aiv_hit
     *
     * @param int $ts
     * @param string $botCode
     * @param string $category
     * @param string $path
     * @param bool $hasQuery
     * @param int $status
     * @param int $ms
     * @return array<string, int|string>
     */
    private function hitRow(
        int $ts,
        string $botCode,
        string $category,
        string $path,
        bool $hasQuery,
        int $status,
        int $ms
    ): array {
        return [
            'created_at' => gmdate('Y-m-d H:i:s', $ts),
            'store_id' => $this->storeId,
            'bot_code' => $botCode,
            'category' => $category,
            'path' => $path,
            'path_hash' => sha1($path),
            'has_query' => $hasQuery ? 1 : 0,
            'status' => $status,
            'duration_ms' => $ms,
            'origin' => self::ORIGIN,
            'verified' => 0,
            'is_simulated' => 1,
        ];
    }

    /**
     * Row for panth_aiv_visit from a catalogue source
     *
     * @param Prng $prng
     * @param int $ts
     * @param string $sourceCode
     * @param string $path
     * @return array<string, int|string|null>
     */
    private function visitRow(Prng $prng, int $ts, string $sourceCode, string $path): array
    {
        $source = $this->scenario->sources()[$sourceCode];
        $utm = $prng->chance((int) $source['utm_permille']);
        return [
            'created_at' => gmdate('Y-m-d H:i:s', $ts),
            'store_id' => $this->storeId,
            'source_code' => $sourceCode,
            'medium' => $utm ? 'utm' : 'referrer',
            'referrer_host' => $utm ? null : (string) $source['host'],
            'landing_path' => $path,
            'path_hash' => sha1($path),
            'is_simulated' => 1,
        ];
    }

    /**
     * Row for panth_aiv_landing
     *
     * @param int $ts
     * @param string $path
     * @return array<string, int|string>
     */
    private function landingRow(int $ts, string $path): array
    {
        return [
            'created_at' => gmdate('Y-m-d H:i:s', $ts),
            'store_id' => $this->storeId,
            'path' => $path,
            'path_hash' => sha1($path),
            'is_simulated' => 1,
        ];
    }
}
