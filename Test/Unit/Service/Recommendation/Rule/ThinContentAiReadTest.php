<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\ProductContentLookup;
use Panth\AiVisibility\Service\Recommendation\Rule\ThinContentAiRead;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use PHPUnit\Framework\TestCase;

class ThinContentAiReadTest extends TestCase
{
    use RuleDbTrait;

    private function rule(int $minVolume = 20): ThinContentAiRead
    {
        $support = $this->support($minVolume);
        $catalog = $this->createStub(CatalogLookup::class);
        $catalog->method('entityNames')->willReturn(['product:11' => 'Short Tee']);
        return new ThinContentAiRead($support, $this->logger(), new ProductContentLookup($support), $catalog);
    }

    private static function words(int $count): string
    {
        return '<p>' . trim(str_repeat('word ', $count)) . '</p>';
    }

    private function seed(): void
    {
        $this->route('fetchAll', 'FROM `panth_aiv_entity_daily`', [
            ['entity_id' => 11, 'reads_total' => 14, 'live' => 6],
            ['entity_id' => 12, 'reads_total' => 9, 'live' => 1],
            ['entity_id' => 13, 'reads_total' => 8, 'live' => 0],
            ['entity_id' => 14, 'reads_total' => 99, 'live' => 50],
        ]);
        $this->route('fetchOne', 'FROM `eav_attribute`', '75');
        $this->route('fetchAll', 'FROM `catalog_product_entity` AS e LEFT JOIN `catalog_product_entity_text`', [
            ['entity_id' => 11, 'default_value' => self::words(149), 'store_value' => null],
            ['entity_id' => 12, 'default_value' => self::words(10), 'store_value' => self::words(150)],
            ['entity_id' => 13, 'default_value' => self::words(400), 'store_value' => null],
        ]);
        $this->route('fetchAll', 'catalog_product_entity_media_gallery_value_to_entity', [
            ['entity_id' => 11, 'images' => 2, 'missing' => 0],
            ['entity_id' => 12, 'images' => 3, 'missing' => 2],
            ['entity_id' => 13, 'images' => 1, 'missing' => 0],
        ]);
    }

    public function testFlagsThinDescriptionsAndImagesWithoutLabels(): void
    {
        $this->seed();
        $findings = $this->rule()->evaluate(1, self::today());

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame(Finding::SUBJECT_PRODUCT, $finding->subjectType);
        $this->assertSame(MetricProviderInterface::METRIC_LIVE_FETCHES, $finding->metric);
        $this->assertSame(['11', '12'], $finding->subjects, '13 is fine, 14 no longer exists');
        $this->assertSame(23, $finding->affectedCount);
        $this->assertSame(1, $finding->evidence['thin_description']);
        $this->assertSame(1, $finding->evidence['images_without_label']);
        $this->assertSame(149, $finding->evidence['examples'][0]['description_words']);
        $this->assertSame('Short Tee', $finding->evidence['examples'][0]['name']);
        $this->assertSame(150, $finding->evidence['examples'][1]['description_words'], 'store value wins');
        $this->assertSame(2, $finding->evidence['examples'][1]['images_without_label']);
        $this->assertStringContainsString('2 products', $finding->title);
    }

    public function testBelowMinimumVolumeFindsNothing(): void
    {
        $this->seed();
        $this->assertSame([], $this->rule(24)->evaluate(1, self::today()));
    }

    public function testEmptyDataFindsNothing(): void
    {
        $this->assertSame([], $this->rule()->evaluate(1, self::today()));
        $this->assertCount(1, $this->queries);
    }

    public function testStoreValuesAndRealRowsOnly(): void
    {
        $this->seed();
        $this->rule()->evaluate(2, self::today());

        $this->assertRealRowsOnly(['panth_aiv_entity_daily']);
        $reads = $this->queriesWith('panth_aiv_entity_daily')[0];
        $this->assertStringContainsString('p.store_id = ?', $reads['sql']);
        $this->assertSame(2, end($reads['bind']));
        $text = $this->queriesWith('catalog_product_entity_text')[0];
        $this->assertStringContainsString('s.store_id = ?', $text['sql']);
        $this->assertSame([75, 75, 2, 11, 12, 13, 14], $text['bind']);
        $gallery = $this->queriesWith('media_gallery_value_to_entity')[0];
        $this->assertStringContainsString('COALESCE(s.disabled, d.disabled, 0) = 0', $gallery['sql']);
        $this->assertStringContainsString('ge.entity_id = e.entity_id', $gallery['sql']);
        $this->assertSame(['image', 2, 11, 12, 13, 14], $gallery['bind']);
    }

    public function testDefaultScopeAndRowIdLink(): void
    {
        $this->rowIdLink = true;
        $this->seed();
        $this->rule()->evaluate(0, self::today());

        $text = $this->queriesWith('catalog_product_entity_text')[0];
        $this->assertStringNotContainsString('s.store_id', $text['sql']);
        $this->assertStringContainsString('d.row_id = e.row_id', $text['sql']);
        $gallery = $this->queriesWith('media_gallery_value_to_entity')[0];
        $this->assertStringContainsString('ge.row_id = e.row_id', $gallery['sql']);
        $this->assertStringNotContainsString('store_id = ?', $this->queries[0]['sql']);
    }

    /**
     * @dataProvider wordProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('wordProvider')]
    public function testCountWords(string $html, int $expected): void
    {
        $this->assertSame($expected, ProductContentLookup::countWords($html));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function wordProvider(): array
    {
        return [
            'empty' => ['', 0],
            'plain' => ['Soft cotton tee for everyday wear', 6],
            'html' => ['<p>Soft <strong>cotton</strong></p><ul><li>tee</li></ul>', 3],
            'tags glue words' => ['<p>one</p><p>two</p>', 2],
            'entities' => ['caf&eacute; &amp; bar', 2],
            'script and style' => ['<style>.a{color:red}</style>Real text<script>var x = 1;</script>', 2],
            'directive' => ['{{widget type="x"}} Hello world', 2],
            'apostrophe and hyphen' => ["It's a well-made shoe", 4],
            'numbers' => ['Size 42 fits 2026 runners', 5],
        ];
    }
}
