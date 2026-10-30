<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation\Rule;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\GuardedRule;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\PathProductResolver;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\RuleSupport;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\StockStatusLookup;
use Panth\AiVisibility\Service\Report\CatalogLookup;
use Panth\AiVisibility\Service\Rollup;
use Panth\AiVisibility\Service\VisitWriter;
use Psr\Log\LoggerInterface;

/**
 * Rule R9 (ai_visitors_out_of_stock): shoppers sent by AI assistants in the last 30 days landed on
 * product pages that are out of stock now.
 *
 * Visits come from panth_aiv_visit (known sources, the most visited 500 landing paths per window),
 * paths are resolved to products through url_rewrite, stock through StockStatusLookup (single
 * source stock status or the MSI stock index of the website). One finding per store view; its
 * subjects are the product IDs (verified with ai_visits).
 */
class AiVisitorsOutOfStock extends GuardedRule
{
    public const CODE = 'ai_visitors_out_of_stock';

    private const WEIGHT = 2.5;

    /**
     * @param RuleSupport $support
     * @param LoggerInterface $logger
     * @param PathProductResolver $pathResolver
     * @param StockStatusLookup $stockLookup
     * @param CatalogLookup $catalogLookup
     */
    public function __construct(
        RuleSupport $support,
        LoggerInterface $logger,
        private readonly PathProductResolver $pathResolver,
        private readonly StockStatusLookup $stockLookup,
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
        return (string)__('AI visitors land on out-of-stock products');
    }

    /**
     * @inheritdoc
     */
    public function metric(): string
    {
        return MetricProviderInterface::METRIC_AI_VISITS;
    }

    /**
     * @inheritdoc
     */
    protected function find(int $storeId, \DateTimeImmutable $today): array
    {
        $from = RuleSupport::fromDay($today, RuleSupport::WINDOW_DAYS);
        $to = RuleSupport::toDay($today);
        $byStore = $this->landings($storeId, $from, $to);
        if ($byStore === []) {
            return [];
        }
        $products = [];
        $modes = [];
        foreach ($byStore as $store => $landings) {
            $paths = array_map(static fn (array $landing): string => $landing['path'], $landings);
            $resolved = $this->pathResolver->productIds($paths, (int)$store);
            $visits = [];
            foreach ($resolved as $hash => $productId) {
                $visits[$productId]['visits'] = ($visits[$productId]['visits'] ?? 0) + $landings[$hash]['visits'];
                $visits[$productId]['path'] ??= $landings[$hash]['path'];
            }
            if ($visits === []) {
                continue;
            }
            $modes[$this->stockLookup->mode((int)$store)['mode']] = true;
            foreach ($this->stockLookup->outOfStock(array_keys($visits), (int)$store) as $productId) {
                $products[$productId]['visits'] = ($products[$productId]['visits'] ?? 0)
                    + $visits[$productId]['visits'];
                $products[$productId]['path'] ??= $visits[$productId]['path'];
            }
        }
        $totalVisits = (int)array_sum(array_column($products, 'visits'));
        if ($products === [] || $totalVisits < $this->support->minVolume()) {
            return [];
        }
        uksort($products, static function (int $a, int $b) use ($products): int {
            return [$products[$b]['visits'], $a] <=> [$products[$a]['visits'], $b];
        });
        $products = array_slice($products, 0, RuleSupport::MAX_SUBJECTS, true);
        $examples = array_slice($products, 0, RuleSupport::TOP_EXAMPLES, true);
        $refs = [];
        foreach (array_keys($examples) as $id) {
            $refs[] = ['entity_type' => Rollup::TYPE_PRODUCT, 'entity_id' => $id];
        }
        $names = $this->catalogLookup->entityNames($refs, $storeId);

        $evidence = [
            'window_days' => RuleSupport::WINDOW_DAYS,
            'from' => $from,
            'to' => $to,
            'products' => count($products),
            'ai_visits' => $totalVisits,
            'stock_source' => implode(',', array_keys($modes)),
            'examples' => [],
        ];
        foreach ($examples as $id => $product) {
            $evidence['examples'][] = [
                'product_id' => $id,
                'name' => $names[Rollup::TYPE_PRODUCT . ':' . $id] ?? null,
                'path' => RuleSupport::clip($product['path']),
                'ai_visits' => $product['visits'],
            ];
        }
        $count = count($products);
        $title = $count === 1
            ? __('%1 shoppers from AI assistants landed on a product that is out of stock', $totalVisits)
            : __('%1 shoppers from AI assistants landed on %2 products that are out of stock', $totalVisits, $count);

        return [new Finding(
            self::CODE,
            $storeId,
            Finding::SUBJECT_PRODUCT,
            'products',
            (string)$title,
            $evidence,
            $totalVisits,
            RuleSupport::impactScore(self::WEIGHT, $totalVisits),
            $this->metric(),
            array_map('strval', array_keys($products))
        )];
    }

    /**
     * AI visits per landing path, grouped by store view: store => [path hash => [path, visits]]
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @return array<int, array<string, array{path: string, visits: int}>>
     */
    private function landings(int $storeId, string $from, string $to): array
    {
        $bind = [RuleSupport::startOf($from), RuleSupport::endExclusive($to), VisitWriter::CANDIDATE_PREFIX . '%'];
        $sql = 'SELECT v.store_id, v.path_hash, MIN(v.landing_path) AS path, COUNT(*) AS visits'
            . ' FROM ' . $this->support->table(VisitWriter::TABLE_VISIT) . ' AS v'
            . ' WHERE v.created_at >= ? AND v.created_at < ? AND v.source_code NOT LIKE ? AND ' . $this->support->simulatedCondition('v.is_simulated')
            . RuleSupport::storeSql('v.store_id', $storeId, $bind)
            . ' GROUP BY v.store_id, v.path_hash ORDER BY visits DESC, v.path_hash ASC LIMIT ' . RuleSupport::SCAN_LIMIT;
        $result = [];
        foreach ($this->support->connection()->fetchAll($sql, $bind) as $row) {
            $store = (int)$row['store_id'];
            if ($store <= 0) {
                continue;
            }
            $result[$store][(string)$row['path_hash']] = [
                'path' => (string)$row['path'],
                'visits' => (int)$row['visits'],
            ];
        }
        return $result;
    }
}
