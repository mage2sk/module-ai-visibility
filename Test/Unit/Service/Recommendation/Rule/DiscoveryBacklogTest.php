<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\Rule\DiscoveryBacklog;
use PHPUnit\Framework\TestCase;
use Panth\AiVisibility\Service\DataScope;

class DiscoveryBacklogTest extends TestCase
{
    use RuleDbTrait;

    private function rule(int $minVolume = 20): DiscoveryBacklog
    {
        return new DiscoveryBacklog($this->support($minVolume), $this->logger());
    }

    private function seed(): void
    {
        $this->route('fetchAll', 'FROM `panth_aiv_candidate`', [
            ['candidate_id' => 4, 'type' => 'bot', 'key_value' => 'NewAgent/2.0 (+https://example.invalid/bot)',
                'first_seen' => '2026-10-01 08:00:00', 'hits' => 30, 'llms_fetches' => 2],
            ['candidate_id' => 9, 'type' => 'source', 'key_value' => 'host:assistant.example.invalid',
                'first_seen' => '2026-10-10 08:00:00', 'hits' => 5, 'llms_fetches' => 0],
        ]);
    }

    public function testOverdueCandidatesWithTrafficAreReported(): void
    {
        $this->seed();
        $findings = $this->rule()->evaluate(0, self::today());

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame(Finding::SUBJECT_SITE, $finding->subjectType);
        $this->assertSame(MetricProviderInterface::METRIC_CANDIDATE_BACKLOG, $finding->metric);
        $this->assertSame(2, $finding->affectedCount);
        $this->assertSame(1, $finding->evidence['bots']);
        $this->assertSame(1, $finding->evidence['sources']);
        $this->assertSame(35, $finding->evidence['hits']);
        $this->assertSame('NewAgent/2.0 (+https://example.invalid/bot)', $finding->evidence['examples'][0]['value']);
        $this->assertStringContainsString('2 unknown AI bots or sources', $finding->title);
        $this->assertStringContainsString('7 days', $finding->title);

        $query = $this->queries[0];
        $this->assertStringContainsString('c.is_simulated = 0', $query['sql']);
        $this->assertStringContainsString('c.first_seen < ?', $query['sql']);
        $this->assertStringNotContainsString('EXISTS', $query['sql']);
        $this->assertSame(['new', '2026-10-23 00:00:00', 3], $query['bind']);
    }

    public function testBelowMinimumVolumeFindsNothing(): void
    {
        $this->seed();
        $this->assertSame([], $this->rule(36)->evaluate(0, self::today()));
    }

    public function testEmptyDataFindsNothing(): void
    {
        $this->assertSame([], $this->rule()->evaluate(1, self::today()));
        $this->assertCount(1, $this->queries);
    }

    public function testStoreFilterNeedsTrafficInThatStore(): void
    {
        $this->seed();
        $this->rule()->evaluate(2, self::today());

        $this->assertRealRowsOnly(['panth_aiv_candidate', 'panth_aiv_hit', 'panth_aiv_visit']);
        $query = $this->queries[0];
        $this->assertStringContainsString('h.bot_code = CONCAT(?, c.candidate_id) AND h.store_id = ?', $query['sql']);
        $this->assertStringContainsString('v.source_code = CONCAT(?, c.candidate_id) AND v.store_id = ?', $query['sql']);
        $this->assertSame(3, substr_count($query['sql'], 'is_simulated = 0'));
        $this->assertSame(0, substr_count($query['sql'], 'is_simulated IN (0, 1)'));
        $this->assertSame(
            ['new', '2026-10-23 00:00:00', 3,
                'bot', 'cand:', 2, '2026-10-01 00:00:00',
                'source', 'cand:', 2, '2026-10-01 00:00:00'],
            $query['bind']
        );
    }

    public function testSimulatedPreviewReadsSimulatedRowsToo(): void
    {
        $this->scope = new DataScope();
        $this->scope->includeSimulated(true);
        $this->rule()->evaluate(1, self::today());

        $sql = $this->queriesWith('panth_aiv_candidate')[0]['sql'];
        $this->assertSame(3, substr_count($sql, 'is_simulated IN (0, 1)'));
        $this->assertSame(0, substr_count($sql, 'is_simulated = 0'));
    }
}
