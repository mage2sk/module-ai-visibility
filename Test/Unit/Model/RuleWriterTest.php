<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Model\RuleWriter;
use PHPUnit\Framework\TestCase;

class RuleWriterTest extends TestCase
{
    /**
     * @var array<int, array>
     */
    private array $calls = [];

    /**
     * @var array<int, array>
     */
    private array $cleaned = [];

    /**
     * @var int
     */
    private int $resets = 0;

    /**
     * Value returned by fetchOne()
     *
     * @var mixed
     */
    private $fetchOneResult = '42';

    private function writer(): RuleWriter
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select) {
            $this->calls[] = ['where', $condition, $value];
            return $select;
        });

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnCallback(fn () => $this->fetchOneResult);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function ($table, array $data, array $fields = []): int {
                $this->calls[] = ['insertOnDuplicate', $table, $data, $fields];
                return 1;
            }
        );
        $connection->method('update')->willReturnCallback(function ($table, array $bind, $where = ''): int {
            $this->calls[] = ['update', $table, $bind, $where];
            return 1;
        });
        $connection->method('delete')->willReturnCallback(function ($table, $where = ''): int {
            $this->calls[] = ['delete', $table, $where];
            return 1;
        });

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(fn (string $name) => 'prefix_' . $name);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('clean')->willReturnCallback(function ($tags = []): bool {
            $this->cleaned[] = $tags;
            return true;
        });

        $provider = $this->createStub(RuleProvider::class);
        $provider->method('reset')->willReturnCallback(function (): void {
            $this->resets++;
        });

        return new RuleWriter($resource, $cache, $provider);
    }

    private function callsOf(string $type): array
    {
        return array_values(array_filter($this->calls, fn (array $call) => $call[0] === $type));
    }

    public function testInsertBotRuleNormalisesAndUpserts(): void
    {
        $id = $this->writer()->save([
            'type' => ' BOT ',
            'code' => 'Example_Bot',
            'name' => '  ExampleBot  ',
            'pattern' => ' ExampleBot/ ',
            'category' => 'Live',
            'company' => 'Example Inc',
            'candidate_id' => '12',
        ]);

        $this->assertSame(42, $id);
        $inserts = $this->callsOf('insertOnDuplicate');
        $this->assertCount(1, $inserts);
        [, $table, $data, $fields] = $inserts[0];
        $this->assertSame('prefix_panth_aiv_rule', $table);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['created_at']);
        unset($data['created_at']);
        $this->assertSame(
            [
                'type' => 'bot', 'code' => 'example_bot', 'name' => 'ExampleBot', 'match_on' => 'ua',
                'pattern' => 'ExampleBot/', 'category' => 'live', 'company' => 'Example Inc', 'candidate_id' => 12,
            ],
            $data
        );
        $this->assertSame(['name', 'match_on', 'pattern', 'category', 'company', 'candidate_id'], $fields);
        $this->assertContains(['where', 'type = ?', 'bot'], $this->calls);
        $this->assertContains(['where', 'code = ?', 'example_bot'], $this->calls);
        $this->assertSame([[RuleProvider::CACHE_TAG]], $this->cleaned);
        $this->assertSame(1, $this->resets);
    }

    public function testInsertSourceRuleLowercasesPatternAndDropsCategory(): void
    {
        $this->writer()->save([
            'type' => 'source',
            'code' => 'shop_chat',
            'name' => 'Shop Chat',
            'match_on' => 'HOST',
            'pattern' => 'Chat.Shop.Test',
            'category' => 'live',
        ]);

        $data = $this->callsOf('insertOnDuplicate')[0][2];
        $this->assertSame('host', $data['match_on']);
        $this->assertSame('chat.shop.test', $data['pattern']);
        $this->assertNull($data['category']);
        $this->assertNull($data['company']);
        $this->assertNull($data['candidate_id']);
    }

    public function testBotCategoryDefaultsToOther(): void
    {
        $this->writer()->save(['type' => 'bot', 'code' => 'a_bot', 'name' => 'A Bot', 'pattern' => 'ABot']);
        $this->assertSame('other', $this->callsOf('insertOnDuplicate')[0][2]['category']);
    }

    public function testUpdateByRuleIdKeepsCreatedAt(): void
    {
        $this->fetchOneResult = '5';
        $id = $this->writer()->save([
            'rule_id' => 5,
            'type' => 'source',
            'code' => 'shop_utm',
            'name' => 'Shop UTM',
            'match_on' => 'utm',
            'pattern' => 'Shop-AI',
        ]);

        $this->assertSame(5, $id);
        $updates = $this->callsOf('update');
        $this->assertCount(1, $updates);
        [, $table, $bind, $where] = $updates[0];
        $this->assertSame('prefix_panth_aiv_rule', $table);
        $this->assertSame(['rule_id = ?' => 5], $where);
        $this->assertArrayNotHasKey('created_at', $bind);
        $this->assertSame('shop-ai', $bind['pattern']);
        $this->assertSame([], $this->callsOf('insertOnDuplicate'));
        $this->assertCount(1, $this->cleaned);
    }

    public function testUpdateOfMissingRuleThrows(): void
    {
        $this->fetchOneResult = false;
        $this->expectException(LocalizedException::class);
        try {
            $this->writer()->save([
                'rule_id' => 99, 'type' => 'bot', 'code' => 'x_bot', 'name' => 'X', 'pattern' => 'XBot',
            ]);
        } finally {
            $this->assertSame([], $this->callsOf('update'));
            $this->assertSame([], $this->cleaned);
        }
    }

    public function testInvalidRulesAreRejectedWithoutWriting(): void
    {
        $valid = ['type' => 'bot', 'code' => 'ok_bot', 'name' => 'OK', 'pattern' => 'OkBot', 'category' => 'search'];
        $invalid = [
            'unknown type' => ['type' => 'agent'],
            'empty code' => ['code' => ''],
            'code with dash' => ['code' => 'ok-bot'],
            'code with colon' => ['code' => 'cand:1'],
            'code too long' => ['code' => str_repeat('a', 65)],
            'empty name' => ['name' => '   '],
            'bot on host' => ['match_on' => 'host'],
            'empty pattern' => ['pattern' => ''],
            'pattern too long' => ['pattern' => str_repeat('a', 513)],
            'token too short' => ['pattern' => 'ab'],
            'bad category' => ['category' => 'unknown'],
            'source without match_on' => ['type' => 'source', 'pattern' => 'chat.shop.test'],
            'source on ua' => ['type' => 'source', 'match_on' => 'ua', 'pattern' => 'chat.shop.test'],
            'bad host' => ['type' => 'source', 'match_on' => 'host', 'pattern' => 'bad host.test'],
            'host with double dot' => ['type' => 'source', 'match_on' => 'host', 'pattern' => 'a..b.test'],
            'bad utm' => ['type' => 'source', 'match_on' => 'utm', 'pattern' => 'has space'],
        ];
        foreach ($invalid as $label => $override) {
            $this->calls = [];
            $this->cleaned = [];
            $thrown = false;
            try {
                $this->writer()->save(array_replace($valid, $override));
            } catch (LocalizedException $e) {
                $thrown = true;
            }
            $this->assertTrue($thrown, 'Expected a validation error for: ' . $label);
            $this->assertSame([], $this->callsOf('insertOnDuplicate'), $label);
            $this->assertSame([], $this->cleaned, $label);
        }
    }

    public function testDeleteRemovesRowAndCleansCache(): void
    {
        $this->writer()->delete(8);

        $this->assertSame([['delete', 'prefix_panth_aiv_rule', ['rule_id = ?' => 8]]], $this->callsOf('delete'));
        $this->assertSame([[RuleProvider::CACHE_TAG]], $this->cleaned);
        $this->assertSame(1, $this->resets);
    }

    public function testDeleteIgnoresNonPositiveIds(): void
    {
        $this->writer()->delete(0);
        $this->assertSame([], $this->callsOf('delete'));
        $this->assertSame([], $this->cleaned);
    }
}
