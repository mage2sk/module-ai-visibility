<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\RecommendationRuleInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\Engine;
use Panth\AiVisibility\Service\Recommendation\Repository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EngineTest extends TestCase
{
    private const NOW = 1773100800; // 2026-03-10 00:00:00 UTC

    /**
     * @var array<string, array>
     */
    private array $existing = [];

    /**
     * @var array<int, array>
     */
    private array $open = [];

    /**
     * @var string[]
     */
    private array $penalised = [];

    /**
     * @var array<int, array{0: array, 1: array}>
     */
    private array $saved = [];

    /**
     * @var int[][]
     */
    private array $resolved = [];

    private int $transactions = 0;

    protected function setUp(): void
    {
        $this->existing = [];
        $this->open = [];
        $this->penalised = [];
        $this->saved = [];
        $this->resolved = [];
        $this->transactions = 0;
    }

    public function testCreatesNewFindingsAsOpenRowsWithSubjectsInEvidence(): void
    {
        $finding = $this->finding('bot_404', 1, 'paths:404', 120, ['/a', '/b']);
        $result = $this->engine([$this->rule('bot_404', [$finding])])->runAt(1, false, self::NOW);

        $this->assertSame([1, 0, 0], [$result['created'], $result['updated'], $result['resolved']]);
        $this->assertSame(1, $this->transactions);
        [$rows, $columns] = $this->saved[0];
        $this->assertContains('status', $columns);
        $this->assertContains('status_changed_at', $columns);
        $this->assertNotContains('first_seen_at', $columns);
        $row = $rows[0];
        $this->assertSame($finding->fingerprint(), $row['fingerprint']);
        $this->assertSame('open', $row['status']);
        $this->assertSame('2026-03-10 00:00:00', $row['first_seen_at']);
        $this->assertSame(['/a', '/b'], $row['evidence']['subjects']);
        $this->assertSame(0, $row['is_simulated']);
        $this->assertSame(Engine::score($finding), $row['impact_score']);
        $this->assertSame('create', $result['findings'][0]['action']);
    }

    public function testOpenRowFoundAgainIsUpdatedWithoutTouchingStatus(): void
    {
        $finding = $this->finding('bot_404', 1, 'paths:404', 50);
        $this->existing[$finding->fingerprint()] = $this->row(7, 'open');
        $this->open = [$this->openRow(7, $finding->fingerprint(), 'bot_404')];

        $result = $this->engine([$this->rule('bot_404', [$finding])])->runAt(1, false, self::NOW);

        $this->assertSame([0, 1, 0], [$result['created'], $result['updated'], $result['resolved']]);
        $this->assertSame([], $this->saved[0][0]);
        [$rows, $columns] = $this->saved[1];
        $this->assertSame(Repository::REFRESH_COLUMNS, $columns);
        $this->assertSame($finding->fingerprint(), $rows[0]['fingerprint']);
        $this->assertSame([[]], $this->resolved);
    }

    public function testOpenRowsNotFoundAgainAreResolved(): void
    {
        $this->open = [$this->openRow(3, sha1('x'), 'bot_404'), $this->openRow(4, sha1('y'), 'never_crawled_products')];

        $result = $this->engine([$this->rule('bot_404', []), $this->rule('never_crawled_products', [])])
            ->runAt(1, false, self::NOW);

        $this->assertSame(2, $result['resolved']);
        $this->assertSame([[3, 4]], $this->resolved);
        $this->assertSame('resolve', $result['findings'][0]['action']);
    }

    public function testRowsOfRulesThatAreNoLongerRegisteredAreResolvedToo(): void
    {
        $this->open = [$this->openRow(9, sha1('old'), 'retired_rule')];
        $result = $this->engine([$this->rule('bot_404', [])])->runAt(1, false, self::NOW);
        $this->assertSame(1, $result['resolved']);
    }

    public function testOneFailingRuleNeverStopsTheOthersAndKeepsItsOpenRows(): void
    {
        $good = $this->finding('bot_404', 1, 'paths:404', 40);
        $this->open = [$this->openRow(5, sha1('slow'), 'bot_errors_or_slow')];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('bot_errors_or_slow'));

        $result = $this->engine([
            $this->rule('bot_errors_or_slow', null),
            $this->rule('bot_404', [$good]),
        ], [1], 50, $logger)->runAt(1, false, self::NOW);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['resolved']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('rule bot_errors_or_slow: boom', $result['errors'][0]);
    }

    /**
     * @dataProvider closedStates
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('closedStates')]
    public function testClosedRowsLifecycle(string $status, ?string $changedAt, string $expectedAction, int $created): void
    {
        $finding = $this->finding('bot_404', 1, 'paths:404', 40);
        $this->existing[$finding->fingerprint()] = $this->row(11, $status, $changedAt);

        $result = $this->engine([$this->rule('bot_404', [$finding])])->runAt(1, false, self::NOW);

        $this->assertSame($expectedAction, $result['findings'][0]['action']);
        $this->assertSame($created, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame($created === 0 ? 0 : 1, count($this->saved[0][0] ?? []));
        if ($created === 1) {
            $this->assertSame('open', $this->saved[0][0][0]['status']);
            $this->assertContains('status', $this->saved[0][1]);
        }
    }

    public static function closedStates(): array
    {
        return [
            'applied 5 days ago stays closed' => ['applied', '2026-03-05 10:00:00', 'suppressed', 0],
            'dismissed 29 days ago stays closed' => ['dismissed', '2026-02-09 10:00:00', 'suppressed', 0],
            'applied without date stays closed' => ['applied', null, 'suppressed', 0],
            'dismissed 31 days ago reopens' => ['dismissed', '2026-02-07 23:00:00', 'reopen', 1],
            'applied exactly 30 days ago reopens' => ['applied', '2026-02-08 00:00:00', 'reopen', 1],
            'resolved reopens at once' => ['resolved', '2026-03-09 04:20:00', 'reopen', 1],
        ];
    }

    public function testSuppressedFindingDoesNotResolveAnything(): void
    {
        $finding = $this->finding('bot_404', 1, 'paths:404', 40);
        $this->existing[$finding->fingerprint()] = $this->row(11, 'applied', '2026-03-05 10:00:00');

        $result = $this->engine([$this->rule('bot_404', [$finding])])->runAt(1, false, self::NOW);

        $this->assertSame(0, $this->transactions);
        $this->assertSame([0, 0, 0], [$result['created'], $result['updated'], $result['resolved']]);
    }

    public function testSimulatorRowsAreNeverTouched(): void
    {
        $finding = $this->finding('bot_404', 1, 'paths:404', 40);
        $this->existing[$finding->fingerprint()] = $this->row(12, 'open', null, 1);

        $result = $this->engine([$this->rule('bot_404', [$finding])])->runAt(1, false, self::NOW);

        $this->assertSame('simulated', $result['findings'][0]['action']);
        $this->assertSame(0, $this->transactions);
    }

    public function testMaxOpenKeepsTheHighestImpactAndResolvesDisplacedOpenRows(): void
    {
        $big = $this->finding('bot_404', 1, 'a', 10000);
        $mid = $this->finding('bot_404', 1, 'b', 500);
        $small = $this->finding('bot_404', 1, 'c', 25);
        $this->existing[$small->fingerprint()] = $this->row(21, 'open');
        $this->open = [$this->openRow(21, $small->fingerprint(), 'bot_404')];

        $result = $this->engine([$this->rule('bot_404', [$small, $big, $mid])], [1], 2)->runAt(1, false, self::NOW);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['resolved']);
        $this->assertSame([[21]], $this->resolved);
        $actions = array_column($result['findings'], 'action', 'subject_ref');
        $this->assertSame(['a' => 'create', 'b' => 'create', 'c' => 'over_limit'], array_intersect_key($actions, array_flip(['a', 'b', 'c'])));
    }

    public function testNotUsefulFeedbackHalvesTheRankOfThatRule(): void
    {
        $penalisedFinding = $this->finding('bot_404', 1, 'a', 1000);
        $other = $this->finding('never_crawled_products', 1, 'b', 1000);
        $this->penalised = ['bot_404'];

        $result = $this->engine([
            $this->rule('bot_404', [$penalisedFinding]),
            $this->rule('never_crawled_products', [$other]),
        ], [1], 1)->runAt(1, false, self::NOW);

        $byRule = array_column($result['findings'], null, 'rule_code');
        $this->assertSame('over_limit', $byRule['bot_404']['action']);
        $this->assertSame('create', $byRule['never_crawled_products']['action']);
        $this->assertSame(round(Engine::score($penalisedFinding) * 0.5, 2), $byRule['bot_404']['rank_score']);
    }

    public function testDryRunCountsButWritesNothing(): void
    {
        $finding = $this->finding('bot_404', 1, 'paths:404', 40);
        $this->open = [$this->openRow(3, sha1('x'), 'bot_404')];

        $result = $this->engine([$this->rule('bot_404', [$finding])])->runAt(1, true, self::NOW);

        $this->assertSame([1, 0, 1], [$result['created'], $result['updated'], $result['resolved']]);
        $this->assertSame(0, $this->transactions);
        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->resolved);
    }

    public function testRunsEveryActiveEnabledStoreAndIgnoresFindingsOfOtherStoresOrRules(): void
    {
        $seen = [];
        $rule = $this->rule('bot_404', static function (int $storeId) use (&$seen): array {
            $seen[] = $storeId;
            return [
                new Finding('bot_404', $storeId, 'path', 'ok', 'T', [], 30),
                new Finding('bot_404', $storeId + 100, 'path', 'wrong-store', 'T', [], 30),
                new Finding('other_rule', $storeId, 'path', 'wrong-rule', 'T', [], 30),
            ];
        });

        $result = $this->engine([$rule], [0, 1, 2, 3])->runAt(null, true, self::NOW);

        $this->assertSame([1, 3], $seen);
        $this->assertSame(2, $result['created']);
        $this->assertSame(['ok', 'ok'], array_column($result['findings'], 'subject_ref'));
    }

    public function testAFailingStoreDoesNotStopTheNextStore(): void
    {
        $repository = $this->repository();
        $repository->method('penalisedRules')->willReturnCallback(static function (int $storeId): array {
            if ($storeId === 1) {
                throw new \RuntimeException('db');
            }
            return [];
        });
        $engine = new Engine(
            $repository,
            $this->config(50),
            $this->stores([1, 3]),
            $this->createStub(LoggerInterface::class),
            ['bot_404' => $this->rule('bot_404', fn (int $s) => [$this->finding('bot_404', $s, 'x', 30)])]
        );

        $result = $engine->runAt(null, true, self::NOW);

        $this->assertSame(1, $result['created']);
        $this->assertSame(['store 1: db'], $result['errors']);
    }

    /**
     * @dataProvider scores
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scores')]
    public function testScore(string $rule, int $affected, int $ruleScore, int $expected): void
    {
        $this->assertSame($expected, Engine::score(new Finding($rule, 1, 'site', 's', 'T', [], $affected, $ruleScore)));
    }

    public static function scores(): array
    {
        return [
            'no volume' => ['bot_404', 0, 0, 0],
            'hundred events, weight 0.8' => ['bot_404', 99, 0, 40],
            'ten thousand events cap' => ['bot_errors_or_slow', 999999, 0, 90],
            'rule floor wins' => ['robots_blocks_ai_search', 2, 100, 100],
            'unknown rule default weight' => ['custom_rule', 9999, 0, 60],
        ];
    }

    public function testRunUsesCurrentTimeAndRulesAreFiltered(): void
    {
        $engine = $this->engine([$this->rule('bot_404', [])]);
        $this->assertSame(['created' => 0, 'updated' => 0, 'resolved' => 0], array_slice($engine->run(1, true), 0, 3));
        $engine = new Engine(
            $this->repository(),
            $this->config(50),
            $this->stores([]),
            $this->createStub(LoggerInterface::class),
            ['x' => new \stdClass(), 'bot_404' => $this->rule('bot_404', [])]
        );
        $this->assertSame(['bot_404'], array_keys($engine->rules()));
    }

    /**
     * Engine with the recording repository
     *
     * @param RecommendationRuleInterface[] $rules
     * @param int[] $activeStores
     * @param int $maxOpen
     * @param LoggerInterface|null $logger
     * @return Engine
     */
    private function engine(array $rules, array $activeStores = [1], int $maxOpen = 50, ?LoggerInterface $logger = null): Engine
    {
        $named = [];
        foreach ($rules as $rule) {
            $named[$rule->code()] = $rule;
        }
        $repository = $this->repository();
        $repository->method('penalisedRules')->willReturnCallback(fn () => $this->penalised);
        return new Engine(
            $repository,
            $this->config($maxOpen),
            $this->stores($activeStores),
            $logger ?? $this->createStub(LoggerInterface::class),
            $named
        );
    }

    /**
     * Repository mock that records writes (penalisedRules left to the caller)
     *
     * @return Repository&\PHPUnit\Framework\MockObject\MockObject
     */
    private function repository(): Repository
    {
        $repository = $this->createStub(Repository::class);
        $repository->method('byFingerprints')->willReturnCallback(
            fn (array $fps): array => array_intersect_key($this->existing, array_flip($fps))
        );
        $repository->method('openRows')->willReturnCallback(fn (): array => $this->open);
        $repository->method('save')->willReturnCallback(function (array $rows, array $columns): int {
            $this->saved[] = [$rows, $columns];
            return count($rows);
        });
        $repository->method('resolve')->willReturnCallback(function (array $ids): void {
            $this->resolved[] = $ids;
        });
        $repository->method('transactional')->willReturnCallback(function (callable $callback) {
            $this->transactions++;
            return $callback();
        });
        return $repository;
    }

    /**
     * Config stub: max_open and every store view enabled except 2
     *
     * @param int $maxOpen
     * @return Config
     */
    private function config(int $maxOpen): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('recommendationsMaxOpen')->willReturn($maxOpen);
        $config->method('isEnabled')->willReturnCallback(static fn (?int $id = null): bool => $id !== 2);
        return $config;
    }

    /**
     * Store manager with the given active store views
     *
     * @param int[] $ids
     * @return StoreManagerInterface
     */
    private function stores(array $ids): StoreManagerInterface
    {
        $stores = [];
        foreach ($ids as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getIsActive')->willReturn(true);
            $stores[] = $store;
        }
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn($stores);
        return $manager;
    }

    /**
     * Rule returning fixed findings, findings from a callback, or throwing when $findings is null
     *
     * @param string $code
     * @param array|callable|null $findings
     * @return RecommendationRuleInterface
     */
    private function rule(string $code, $findings): RecommendationRuleInterface
    {
        return new class ($code, $findings) implements RecommendationRuleInterface {
            /**
             * @param string $code
             * @param array|callable|null $findings
             */
            public function __construct(private readonly string $code, private $findings)
            {
            }

            public function code(): string
            {
                return $this->code;
            }

            public function label(): string
            {
                return $this->code;
            }

            public function metric(): string
            {
                return 'bot_hits';
            }

            public function evaluate(int $storeId, \DateTimeImmutable $today): array
            {
                if ($this->findings === null) {
                    throw new \RuntimeException('boom');
                }
                return is_callable($this->findings) ? ($this->findings)($storeId) : $this->findings;
            }
        };
    }

    /**
     * Finding of a rule
     *
     * @param string $code
     * @param int $storeId
     * @param string $ref
     * @param int $affected
     * @param string[] $subjects
     * @return Finding
     */
    private function finding(string $code, int $storeId, string $ref, int $affected, array $subjects = []): Finding
    {
        return new Finding($code, $storeId, 'path', $ref, 'Title ' . $ref, ['n' => $affected], $affected, 0, 'bot_404', $subjects);
    }

    /**
     * Existing row by fingerprint
     *
     * @param int $recId
     * @param string $status
     * @param string|null $changedAt
     * @param int $simulated
     * @return array
     */
    private function row(int $recId, string $status, ?string $changedAt = null, int $simulated = 0): array
    {
        return ['rec_id' => $recId, 'status' => $status, 'status_changed_at' => $changedAt, 'is_simulated' => $simulated];
    }

    /**
     * Open row of the store view
     *
     * @param int $recId
     * @param string $fingerprint
     * @param string $rule
     * @return array
     */
    private function openRow(int $recId, string $fingerprint, string $rule): array
    {
        return [
            'rec_id' => $recId,
            'fingerprint' => $fingerprint,
            'rule_code' => $rule,
            'title' => 'Old ' . $recId,
            'impact_score' => 10,
            'affected_count' => 30,
        ];
    }
}
