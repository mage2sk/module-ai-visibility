<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Service\Recommendation\RedirectGuesser;
use PHPUnit\Framework\TestCase;

class RedirectGuesserTest extends TestCase
{
    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $queries = [];

    protected function setUp(): void
    {
        $this->queries = [];
    }

    /**
     * @dataProvider segments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('segments')]
    public function testLastSegment(string $path, string $expected): void
    {
        $this->assertSame($expected, RedirectGuesser::lastSegment($path));
    }

    public static function segments(): array
    {
        return [
            'html suffix' => ['/gear/Blue-Trail-Jacket.html', 'blue-trail-jacket'],
            'trailing slash' => ['/women/tops/', 'tops'],
            'query dropped' => ['/a/b-c.htm?x=1', 'b-c'],
            'root' => ['/', ''],
        ];
    }

    public function testTokensDropShortAndNumericWords(): void
    {
        $this->assertSame(['blue', 'trail', 'jacket'], RedirectGuesser::tokens('blue-trail-jacket-xl-2024'));
    }

    public function testSimilarity(): void
    {
        $this->assertSame(1.0, RedirectGuesser::similarity('blue-jacket', 'blue-jacket'));
        $this->assertSame(0.0, RedirectGuesser::similarity('', 'blue'));
        $this->assertGreaterThan(0.8, RedirectGuesser::similarity('blue-trail-jacket', 'blue-trail-jackets'));
        $this->assertLessThan(RedirectGuesser::MIN_SCORE, RedirectGuesser::similarity('blue-trail-jacket', 'red-mug'));
    }

    public function testPicksTheMostSimilarLiveRewriteOfTheStore(): void
    {
        $guesser = $this->guesser([[
            ['request_path' => 'gear/blue-trail-jacket-2.html', 'entity_type' => 'product', 'entity_id' => '7'],
            ['request_path' => 'gear/blue-trail-jacket-new.html', 'entity_type' => 'product', 'entity_id' => '8'],
            ['request_path' => 'trail.html', 'entity_type' => 'category', 'entity_id' => '3'],
        ]]);

        $guess = $guesser->guess(2, '/gear/blue-trail-jacket.html');

        $this->assertSame('/gear/blue-trail-jacket-2.html', $guess['target']);
        $this->assertSame('product', $guess['entity_type']);
        $this->assertSame(7, $guess['entity_id']);
        $this->assertGreaterThanOrEqual(RedirectGuesser::MIN_SCORE, $guess['score']);
        [$sql, $bind] = $this->queries[0];
        $this->assertStringContainsString('u.redirect_type = 0', $sql);
        $this->assertStringContainsString('u.store_id = :store', $sql);
        $this->assertSame(2, $bind['store']);
        $this->assertSame('%jacket%', $bind['w0']);
        $this->assertSame('%trail%', $bind['w1']);
        $this->assertStringEndsWith('LIMIT ' . RedirectGuesser::CANDIDATE_LIMIT, $sql);
    }

    public function testFallsBackToTheLongestWordAndIgnoresTheSamePath(): void
    {
        $guesser = $this->guesser([
            [['request_path' => 'gear/blue-trail-jacket.html', 'entity_type' => 'product', 'entity_id' => '1']],
            [['request_path' => 'gear/blue-hiking-jacket.html', 'entity_type' => 'product', 'entity_id' => '2']],
        ]);

        $guess = $guesser->guess(0, '/gear/blue-trail-jacket.html');

        $this->assertSame('/gear/blue-hiking-jacket.html', $guess['target']);
        $this->assertCount(2, $this->queries);
        $this->assertArrayNotHasKey('store', $this->queries[1][1]);
        $this->assertSame(['t0', 't1', 't2', 'w0'], array_keys($this->queries[1][1]));
    }

    public function testNoGuessForWeakMatchesPathsWithoutWordsOrFailingQueries(): void
    {
        $this->assertNull($this->guesser([[['request_path' => 'mug.html', 'entity_type' => 'product', 'entity_id' => 1]]])
            ->guess(1, '/old-ceramic-mug-set.html'));
        $this->queries = [];
        $this->assertNull($this->guesser([])->guess(1, '/12/ab'));
        $this->assertSame([], $this->queries);

        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('fetchAll')->willThrowException(new \RuntimeException('db down'));
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->assertNull((new RedirectGuesser($resource))->guess(1, '/blue-jacket.html'));
    }

    /**
     * Guesser whose adapter answers fetchAll from the given result sets in order
     *
     * @param array $results
     * @return RedirectGuesser
     */
    private function guesser(array $results): RedirectGuesser
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('fetchAll')->willReturnCallback(function (string $sql, array $bind) use (&$results) {
            $this->queries[] = [$sql, $bind];
            return array_shift($results) ?? [];
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);
        return new RedirectGuesser($resource);
    }
}
