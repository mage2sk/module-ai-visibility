<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation\Rule;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Model\Source\Category;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\GuardedRule;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\ProductContentLookup;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\RuleSupport;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use Panth\AiVisibility\Service\Rollup;
use Psr\Log\LoggerInterface;

/**
 * Rule R6 (thin_content_ai_read): product pages AI bots read in the last 30 days whose description
 * has fewer than 150 words or whose gallery images have no label (alt text).
 *
 * Reads come from panth_aiv_entity_daily (every product, uncapped; every known bot category), the content from
 * the product's description attribute and media gallery labels of the store view (default values as
 * fallback). One finding per store view; its subjects are the product IDs (verified with live_fetches).
 */
class ThinContentAiRead extends GuardedRule
{
    public const CODE = 'thin_content_ai_read';

    /**
     * Descriptions shorter than this many words count as thin
     */
    public const MIN_WORDS = 150;

    private const WEIGHT = 2.0;

    /**
     * @param RuleSupport $support
     * @param LoggerInterface $logger
     * @param ProductContentLookup $contentLookup
     * @param CatalogLookup $catalogLookup
     */
    public function __construct(
        RuleSupport $support,
        LoggerInterface $logger,
        private readonly ProductContentLookup $contentLookup,
        private readonly CatalogLookup $catalogLookup
    ) {
        parent::__construct($support, $logger);
    }

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
        return (string)__('AI-read products with thin content');
    }

    /**
     * @inheritdoc
     */
    public function metric(): string
    {
        return MetricProviderInterface::METRIC_LIVE_FETCHES;
    }

    /**
     * @inheritdoc
     */
    protected function find(int $storeId, \DateTimeImmutable $today): array
    {
        $from = RuleSupport::fromDay($today, RuleSupport::WINDOW_DAYS);
        $to = RuleSupport::toDay($today);
        $reads = $this->productReads($storeId, $from, $to);
        if ($reads === []) {
            return [];
        }
        $ids = array_keys($reads);
        $words = $this->contentLookup->descriptionWords($ids, $storeId);
        $images = $this->contentLookup->imageLabels($ids, $storeId);

        $flagged = [];
        $thin = 0;
        $noAlt = 0;
        foreach ($reads as $id => $read) {
            if (!isset($words[$id])) {
                continue; // product no longer exists
            }
            $isThin = $words[$id] < self::MIN_WORDS;
            $missing = (int)($images[$id]['missing'] ?? 0);
            if (!$isThin && $missing === 0) {
                continue;
            }
            $thin += $isThin ? 1 : 0;
            $noAlt += $missing > 0 ? 1 : 0;
            $flagged[$id] = $read + [
                'words' => $words[$id],
                'images' => (int)($images[$id]['images'] ?? 0),
                'images_without_label' => $missing,
            ];
        }
        $totalReads = (int)array_sum(array_column($flagged, 'reads'));
        if ($flagged === [] || $totalReads < $this->support->minVolume()) {
            return [];
        }
        $flagged = array_slice($flagged, 0, RuleSupport::MAX_SUBJECTS, true);
        $examples = array_slice($flagged, 0, RuleSupport::TOP_EXAMPLES, true);
        $refs = [];
        foreach (array_keys($examples) as $id) {
            $refs[] = ['entity_type' => Rollup::TYPE_PRODUCT, 'entity_id' => $id];
        }
        $names = $this->catalogLookup->entityNames($refs, $storeId);

        $evidence = [
            'window_days' => RuleSupport::WINDOW_DAYS,
            'from' => $from,
            'to' => $to,
            'products' => count($flagged),
            'ai_reads' => $totalReads,
            'live_fetches' => (int)array_sum(array_column($flagged, 'live')),
            'thin_description' => $thin,
            'images_without_label' => $noAlt,
            'min_words' => self::MIN_WORDS,
            'examples' => [],
        ];
        foreach ($examples as $id => $product) {
            $evidence['examples'][] = [
                'product_id' => $id,
                'name' => $names[Rollup::TYPE_PRODUCT . ':' . $id] ?? null,
                'ai_reads' => $product['reads'],
                'live_fetches' => $product['live'],
                'description_words' => $product['words'],
                'images' => $product['images'],
                'images_without_label' => $product['images_without_label'],
            ];
        }
        $count = count($flagged);
        $title = $count === 1
            ? __('1 product read by AI bots has a short description or images without alt text')
            : __('%1 products read by AI bots have short descriptions or images without alt text', $count);

        return [new Finding(
            self::CODE,
            $storeId,
            Finding::SUBJECT_PRODUCT,
            'products',
            (string)$title,
            $evidence,
            $totalReads,
            RuleSupport::impactScore(self::WEIGHT, $totalReads),
            $this->metric(),
            array_map('strval', array_keys($flagged))
        )];
    }

    /**
     * AI reads per product page, most read first: product ID => [reads, live]
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @return array<int, array{reads: int, live: int}>
     */
    private function productReads(int $storeId, string $from, string $to): array
    {
        $categories = Category::BOT_CATEGORIES;
        $bind = array_merge([Category::LIVE, $from, $to, Rollup::TYPE_PRODUCT], $categories);
        $sql = 'SELECT p.entity_id, SUM(p.hits) AS reads_total,'
            . ' SUM(CASE WHEN p.category = ? THEN p.hits ELSE 0 END) AS live'
            . ' FROM ' . $this->support->table(Rollup::ENTITY_DAILY_TABLE) . ' AS p'
            . ' WHERE p.day >= ? AND p.day <= ? AND p.entity_type = ?'
            . ' AND p.category IN (' . RuleSupport::placeholders(count($categories)) . ') AND ' . $this->support->simulatedCondition('p.is_simulated')
            . RuleSupport::storeSql('p.store_id', $storeId, $bind)
            . ' GROUP BY p.entity_id ORDER BY reads_total DESC, p.entity_id ASC LIMIT ' . RuleSupport::SCAN_LIMIT;
        $reads = [];
        foreach ($this->support->connection()->fetchAll($sql, $bind) as $row) {
            $id = (int)$row['entity_id'];
            if ($id > 0) {
                $reads[$id] = ['reads' => (int)$row['reads_total'], 'live' => (int)$row['live']];
            }
        }
        return $reads;
    }
}
