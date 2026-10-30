<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\ViewModel\Recommendation;

use Panth\AiVisibility\Test\Unit\Block\Adminhtml\RecommendationFixtureTrait;
use PHPUnit\Framework\TestCase;

class DataSourceTest extends TestCase
{
    use RecommendationFixtureTrait;

    public function testOpenRowsAreNormalised(): void
    {
        $this->openRows = [
            self::hostileRecommendation(1, ['evidence' => '{broken', 'title' => '  ']),
            self::hostileRecommendation(2, ['evidence' => ['subjects' => ['/a']]]),
            ['rec_id' => 0, 'title' => 'invalid'],
        ];

        $rows = $this->dataSource()->openRecommendations(null, 10);

        $this->assertCount(2, $rows);
        $this->assertSame([], $rows[0]['evidence']);
        $this->assertSame('Pages answer client errors (4xx) to AI bots', $rows[0]['title']);
        $this->assertSame(['subjects' => ['/a']], $rows[1]['evidence']);
    }

    public function testCountsAlwaysHaveEveryStatus(): void
    {
        $this->countRows = ['open' => '4', 'bogus' => 9];
        $this->assertSame(
            ['open' => 4, 'applied' => 0, 'dismissed' => 0, 'resolved' => 0],
            $this->dataSource()->counts()
        );
    }

    public function testFailuresAreLoggedAndEmpty(): void
    {
        $this->repositoryFails = true;
        $source = $this->dataSource();

        $this->assertSame([], $source->openRecommendations(1, 5));
        $this->assertSame([], $source->recentActions(5));
        $this->assertSame(0, array_sum($source->counts()));
        $this->assertCount(3, $this->loggedWarnings);
    }

    public function testActionsDecodeSubjectsAndImpact(): void
    {
        $this->actionRows = [
            self::action(1, 'pending', null, ['subjects' => 'not json', 'impact' => 'bad']),
            self::action(2, 'verified', self::impact('helped', 1.2, 1.1, 1.3)),
            ['action_id' => 'x'],
        ];

        $rows = $this->dataSource()->recentActions(10);

        $this->assertCount(2, $rows);
        $this->assertSame([], $rows[0]['subjects']);
        $this->assertNull($rows[0]['impact']);
        $this->assertSame(['11', '12', '13'], $rows[1]['subjects']);
        $this->assertSame('helped', $rows[1]['impact']['verdict']);
    }

    /**
     * The admin lists are real-only: simulated actions are skipped (no Undo, no verdict), and the limit counts
     * real actions only
     */
    public function testSimulatedActionsAreLeftOut(): void
    {
        $this->actionRows = [
            self::action(1, 'pending', null, ['is_simulated' => 1]),
            self::action(2, 'pending', null),
            self::action(3, 'verified', self::impact('helped', 1.2, 1.1, 1.3), ['is_simulated' => '1']),
            self::action(4, 'verified', self::impact('helped', 1.2, 1.1, 1.3)),
            self::action(5, 'pending', null),
        ];

        $this->assertSame([2, 4], array_column($this->dataSource()->recentActions(2), 'action_id'));
        $this->assertSame([2, 4, 5], array_column($this->dataSource()->recentActions(10), 'action_id'));
    }
}
