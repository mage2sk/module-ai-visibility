<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation;

use Panth\AiVisibility\Api\MetricProviderInterface;
use Panth\AiVisibility\Api\RecommendationRuleInterface;
use Panth\AiVisibility\Model\Finding;
use PHPUnit\Framework\TestCase;

/**
 * The ten starter rules are wired into the engine under their own codes and verify with a known metric.
 *
 * Rules are instantiated without constructor arguments only when they have none; otherwise their
 * CODE constant is checked.
 */
class RuleWiringTest extends TestCase
{
    private const EXPECTED = [
        'robots_blocks_ai_search' => 'RobotsBlocksAiSearch',
        'bot_404' => 'Bot404',
        'bot_errors_or_slow' => 'BotErrorsOrSlow',
        'never_crawled_products' => 'NeverCrawledProducts',
        'live_answers_no_sales' => 'LiveAnswersNoSales',
        'thin_content_ai_read' => 'ThinContentAiRead',
        'llms_not_served' => 'LlmsNotServed',
        'parameter_crawl_waste' => 'ParameterCrawlWaste',
        'ai_visitors_out_of_stock' => 'AiVisitorsOutOfStock',
        'discovery_backlog' => 'DiscoveryBacklog',
    ];

    /**
     * Rule items of the engine in etc/di.xml: item name => class
     *
     * @return array<string, string>
     */
    private static function wiredRules(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/di.xml');
        $items = $xml->xpath(
            '//type[@name="Panth\AiVisibility\Service\Recommendation\Engine"]/arguments/argument[@name="rules"]/item'
        ) ?: [];
        $result = [];
        foreach ($items as $item) {
            $result[(string)$item['name']] = ltrim(trim((string)$item), '\\');
        }
        return $result;
    }

    public function testTenRulesAreWiredUnderTheirCodes(): void
    {
        $expected = [];
        foreach (self::EXPECTED as $code => $class) {
            $expected[$code] = 'Panth\\AiVisibility\\Service\\Recommendation\\Rule\\' . $class;
        }

        $this->assertSame($expected, self::wiredRules());
    }

    public function testEveryRuleImplementsTheContractWithAMatchingCode(): void
    {
        foreach (self::wiredRules() as $code => $class) {
            $this->assertTrue(is_a($class, RecommendationRuleInterface::class, true), $class);
            $this->assertSame($code, constant($class . '::CODE'), $class . '::CODE');
            $this->assertLessThanOrEqual(Finding::MAX_RULE_CODE, strlen($code));
            $ctor = (new \ReflectionClass($class))->getConstructor();
            if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            $rule = new $class();
            $this->assertSame($code, $rule->code());
            $this->assertContains($rule->metric(), MetricProviderInterface::METRICS, $class . '::metric()');
            $this->assertNotSame('', $rule->label());
        }
    }

    public function testMetricListsAreConsistent(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff(MetricProviderInterface::LESS_IS_BETTER, MetricProviderInterface::METRICS))
        );
        $this->assertSame(
            [],
            array_values(array_diff(MetricProviderInterface::RATIO_METRICS, MetricProviderInterface::METRICS))
        );
        $this->assertCount(12, array_unique(MetricProviderInterface::METRICS));
    }
}
