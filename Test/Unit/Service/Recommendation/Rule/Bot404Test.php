<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\CrawlFacts;
use Panth\AiVisibility\Service\Recommendation\RedirectGuesser;
use Panth\AiVisibility\Service\Recommendation\Rule\Bot404;
use Panth\AiVisibility\Service\Report\BotDirectory;
use PHPUnit\Framework\TestCase;

class Bot404Test extends TestCase
{
    public function testNoDataGivesNoFinding(): void
    {
        $facts = $this->createMock(CrawlFacts::class);
        $facts->method('hitSummary')->willReturn(['hits' => 0, 'paths' => 0, 'bots' => 0]);
        $facts->expects($this->never())->method('topHitPaths');

        $this->assertSame([], $this->rule($facts, 1)->evaluate(1, $this->today()));
    }

    /**
     * @dataProvider volumes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('volumes')]
    public function testMinVolumeThreshold(int $hits, int $minVolume, int $expected): void
    {
        $facts = $this->facts($hits);
        $this->assertCount($expected, $this->rule($facts, $minVolume)->evaluate(1, $this->today()));
    }

    public static function volumes(): array
    {
        return [
            'below' => [19, 20, 0],
            'at' => [20, 20, 1],
            'above' => [500, 20, 1],
        ];
    }

    public function testFindingCarriesEvidenceSubjectsAndRedirectGuesses(): void
    {
        $guesser = $this->createStub(RedirectGuesser::class);
        $guesser->method('guess')->willReturnCallback(static function (int $store, string $path): ?array {
            return $path === '/old-jacket.html'
                ? ['target' => '/jacket.html', 'entity_type' => 'product', 'entity_id' => 4, 'score' => 0.8]
                : null;
        });
        $finding = $this->rule($this->facts(120), 20, $guesser)->evaluate(3, $this->today())[0];

        $this->assertInstanceOf(Finding::class, $finding);
        $this->assertSame(Bot404::CODE, $finding->ruleCode);
        $this->assertSame(3, $finding->storeId);
        $this->assertSame(Finding::SUBJECT_PATH, $finding->subjectType);
        $this->assertSame(Bot404::SUBJECT_REF, $finding->subjectRef);
        $this->assertSame(MetricProviderInterface::METRIC_BOT_404, $finding->metric);
        $this->assertSame(120, $finding->affectedCount);
        $this->assertSame(['/old-jacket.html', '/gone'], $finding->subjects);
        $this->assertSame('AI bots got 120 client errors (4xx, such as 404 Not Found) on 2 pages in the last 7 days', $finding->title);
        $evidence = $finding->evidence;
        $this->assertSame(['from' => '2026-03-03', 'to' => '2026-03-09'], $evidence['window']);
        $this->assertSame(120, $evidence['hits_4xx']);
        $this->assertSame(2, $evidence['paths_4xx']);
        $this->assertSame(10.0, $evidence['share_pct']);
        $this->assertSame('/jacket.html', $evidence['top_paths'][0]['redirect_to']['target']);
        $this->assertNull($evidence['top_paths'][1]['redirect_to']);
        $this->assertSame(1, $evidence['redirect_guesses']);
        $this->assertSame([['code' => 'gptbot', 'name' => 'GPTBot', 'hits' => 100]], $evidence['top_bots']);
        $this->assertNotSame('', $evidence['how_to_fix']);
    }

    public function testPassesStoreWindowStatusAndExclusionsToTheQueries(): void
    {
        $facts = $this->createStub(CrawlFacts::class);
        $calls = [];
        $facts->method('hitSummary')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return ['hits' => 50, 'paths' => 1, 'bots' => 1];
        });
        $countCalls = [];
        $facts->method('hitCount')->willReturnCallback(function (...$args) use (&$countCalls): int {
            $countCalls[] = $args;
            return 500;
        });
        $facts->method('topHitPaths')->willReturn([]);
        $facts->method('topHitBots')->willReturn([]);

        $this->rule($facts, 20)->evaluate(2, new \DateTimeImmutable('2026-03-10 04:20:00', new \DateTimeZone('UTC')));

        $this->assertSame(
            [2, '2026-03-03 00:00:00', '2026-03-10 00:00:00', 400, 499, Bot404::EXCLUDED_PATHS, Bot404::EXCLUDED_PREFIXES],
            $calls[0]
        );
        $this->assertCount(1, $calls, 'all bot hits come from hitCount(), not a second summary');
        $this->assertSame([[2, '2026-03-03 00:00:00', '2026-03-10 00:00:00']], $countCalls);
    }

    public function testCountsEvery4xxStatusInEveryQuery(): void
    {
        $ranges = [];
        $facts = $this->createStub(CrawlFacts::class);
        $facts->method('hitSummary')->willReturnCallback(function (...$args) use (&$ranges): array {
            $ranges[] = ['summary', $args[3] ?? 0, $args[4] ?? 0];
            return ['hits' => 50, 'paths' => 1, 'bots' => 1];
        });
        $facts->method('topHitPaths')->willReturnCallback(function (...$args) use (&$ranges): array {
            $ranges[] = ['paths', $args[3], $args[4]];
            return [['path' => '/gone', 'hits' => 50, 'bots' => 1, 'avg_ms' => 0, 'max_status' => 410,
                'last_seen' => '2026-03-08 10:00:00']];
        });
        $facts->method('topHitBots')->willReturnCallback(function (...$args) use (&$ranges): array {
            $ranges[] = ['bots', $args[3], $args[4]];
            return [];
        });

        $this->assertCount(1, $this->rule($facts, 20)->evaluate(2, $this->today()));
        $this->assertSame(
            [['summary', 400, 499], ['paths', 400, 499], ['bots', 400, 499]],
            $ranges
        );
    }

    /**
     * Facts stub with a 4xx total, two paths and one bot
     *
     * @param int $hits
     * @return CrawlFacts
     */
    private function facts(int $hits): CrawlFacts
    {
        $facts = $this->createStub(CrawlFacts::class);
        $facts->method('hitSummary')->willReturnCallback(static function (...$args) use ($hits): array {
            return ($args[3] ?? 0) === 400
                ? ['hits' => $hits, 'paths' => 2, 'bots' => 1]
                : ['hits' => $hits * 10, 'paths' => 30, 'bots' => 4];
        });
        $facts->method('hitCount')->willReturn($hits * 10);
        $facts->method('topHitPaths')->willReturn([
            ['path' => '/old-jacket.html', 'hits' => 100, 'bots' => 1, 'avg_ms' => 50, 'max_status' => 404,
                'last_seen' => '2026-03-09 10:00:00'],
            ['path' => '/gone', 'hits' => 20, 'bots' => 1, 'avg_ms' => 40, 'max_status' => 404,
                'last_seen' => '2026-03-08 10:00:00'],
        ]);
        $facts->method('topHitBots')->willReturn(['gptbot' => 100]);
        return $facts;
    }

    /**
     * Rule under test
     *
     * @param CrawlFacts $facts
     * @param int $minVolume
     * @param RedirectGuesser|null $guesser
     * @return Bot404
     */
    private function rule(CrawlFacts $facts, int $minVolume, ?RedirectGuesser $guesser = null): Bot404
    {
        $config = $this->createStub(Config::class);
        $config->method('recommendationsMinVolume')->willReturn($minVolume);
        $directory = $this->createStub(BotDirectory::class);
        $directory->method('describe')->willReturn(['name' => 'GPTBot', 'company' => 'OpenAI', 'category' => 'training']);
        return new Bot404($facts, $guesser ?? $this->createStub(RedirectGuesser::class), $directory, $config);
    }

    /**
     * Evaluation day
     *
     * @return \DateTimeImmutable
     */
    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-10', new \DateTimeZone('UTC'));
    }
}
