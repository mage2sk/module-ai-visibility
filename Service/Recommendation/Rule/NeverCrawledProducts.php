<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Recommendation\Rule;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Api\RecommendationRuleInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\Finding;
use Panth\AiVisibility\Service\Recommendation\CrawlFacts;
use Panth\AiVisibility\Service\Report\CatalogLookup;

/**
 * Rule R4 (never_crawled_products): Products AI bots never fetch.
 *
 * Enabled, visible, in-stock products of the store's website that no AI bot fetched as a product page
 * in the last 90 days. Runs only once per-product crawler data (panth_aiv_entity_daily, the table the
 * coverage reads) goes back at least MIN_DATA_DAYS days: a fresh install or an upgrade from 1.0 (whose
 * history has no per-product rows) would otherwise flag the whole catalogue. While that history is
 * shorter than 90 days the window starts on its first day and the title names the real number of days.
 * Ignores products created in the last
 * MIN_DATA_DAYS days (they had no fair chance yet). Fires when the count reaches
 * recommendations/min_volume; subjects are up to SUBJECT_LIMIT product IDs (lowest ID first).
 */
class NeverCrawledProducts implements RecommendationRuleInterface
{
    public const CODE = 'never_crawled_products';
    public const SUBJECT_REF = 'products:never_crawled';
    public const WINDOW_DAYS = 90;
    public const MIN_DATA_DAYS = 14;
    public const SUBJECT_LIMIT = 200;
    public const EXAMPLE_LIMIT = 10;

    /**
     * @param CrawlFacts $facts
     * @param CatalogLookup $catalogLookup
     * @param Config $config
     */
    public function __construct(
        private readonly CrawlFacts $facts,
        private readonly CatalogLookup $catalogLookup,
        private readonly Config $config
    ) {
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
        return (string)__('Products never fetched by AI bots');
    }

    /**
     * @inheritdoc
     */
    public function metric(): string
    {
        return MetricProviderInterface::METRIC_BOT_HITS;
    }

    /**
     * @inheritdoc
     */
    public function evaluate(int $storeId, \DateTimeImmutable $today): array
    {
        $day = $today->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $dataSince = $day->modify('-' . self::MIN_DATA_DAYS . ' days');
        $start = $this->facts->crawlDataStart($storeId);
        if ($start === null || $start > $dataSince->format('Y-m-d')) {
            return [];
        }
        $since = max($day->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d'), $start);
        $windowDays = (int)round(
            ($day->getTimestamp() - (new \DateTimeImmutable($since, new \DateTimeZone('UTC')))->getTimestamp()) / 86400
        );
        $coverage = $this->facts->productCoverage(
            $storeId,
            $since,
            $dataSince->format('Y-m-d H:i:s'),
            self::SUBJECT_LIMIT
        );
        $never = $coverage['never'];
        $eligible = $coverage['eligible'];
        if ($never <= 0 || $eligible <= 0 || $never < $this->config->recommendationsMinVolume()) {
            return [];
        }

        $exampleIds = array_slice($coverage['ids'], 0, self::EXAMPLE_LIMIT);
        $refs = array_map(static fn (int $id): array => ['entity_type' => 'product', 'entity_id' => $id], $exampleIds);
        $names = $this->catalogLookup->entityNames($refs, $storeId);
        $examples = [];
        foreach ($exampleIds as $id) {
            $examples[] = ['product_id' => $id, 'name' => $names['product:' . $id] ?? ('#' . $id)];
        }
        $share = round($never * 100 / $eligible, 1);
        return [new Finding(
            self::CODE,
            $storeId,
            Finding::SUBJECT_PRODUCT,
            self::SUBJECT_REF,
            (string)__(
                '%1 of %2 in-stock products (%3%) were never fetched by an AI bot in %4 days',
                number_format($never),
                number_format($eligible),
                number_format($share, 1),
                $windowDays
            ),
            [
                'window' => ['from' => $since, 'to' => $day->modify('-1 day')->format('Y-m-d')],
                'window_days' => $windowDays,
                'crawl_data_since' => $start,
                'never_crawled' => $never,
                'eligible' => $eligible,
                'share_pct' => $share,
                'listed' => count($coverage['ids']),
                'examples' => $examples,
                'how_to_fix' => (string)__(
                    'Link these products from category and CMS pages, keep them in the XML sitemap and llms.txt, '
                    . 'and check that robots.txt does not block their URLs.'
                ),
            ],
            $never,
            0,
            $this->metric(),
            array_map('strval', $coverage['ids'])
        )];
    }
}
