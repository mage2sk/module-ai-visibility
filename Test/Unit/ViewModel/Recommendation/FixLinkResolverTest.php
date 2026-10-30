<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\ViewModel\Recommendation;

use Panth\AiVisibility\Test\Unit\Block\Adminhtml\RecommendationFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FixLinkResolverTest extends TestCase
{
    use RecommendationFixtureTrait;

    public static function linkProvider(): array
    {
        return [
            'robots' => ['robots_blocks_ai_search', 'site', 'site', [], 'section/panth_robots_seo'],
            'parameter waste' => ['parameter_crawl_waste', 'site', 'site', [], 'section/panth_robots_seo'],
            'llms' => ['llms_not_served', 'site', 'site', [], 'section/panth_llms_txt'],
            'discovery' => ['discovery_backlog', 'site', 'site', [], 'panth_aivisibility/discovery/index'],
            'errors' => ['bot_errors_or_slow', 'path', '/x', [], 'panth_aivisibility/log/index'],
            'product from subjects' => ['thin_content_ai_read', 'product', '42', ['subjects' => ['17', '42']],
                'catalog/product/edit/id/17'],
            'product from ref' => ['ai_visitors_out_of_stock', 'product', '42', [], 'catalog/product/edit/id/42'],
            'no page for sales rule' => ['live_answers_no_sales', 'path', '/x', [], null],
            'unknown rule' => ['other', 'site', 'site', [], null],
            'product rule with non-numeric ref' => ['never_crawled_products', 'product', 'abc', [], null],
            'product rule on path subject' => ['never_crawled_products', 'path', '12', [], null],
        ];
    }

    /**
     * @dataProvider linkProvider
     */
    #[DataProvider('linkProvider')]
    public function testResolve(string $rule, string $type, string $ref, array $evidence, ?string $expected): void
    {
        $link = $this->fixLinks()->resolve([
            'rule_code' => $rule, 'subject_type' => $type, 'subject_ref' => $ref, 'evidence' => $evidence,
        ]);
        if ($expected === null) {
            $this->assertNull($link);
            return;
        }
        $this->assertNotNull($link);
        $this->assertStringContainsString($expected, $link['url']);
        $this->assertNotSame('', $link['label']);
    }

    public function testMissingModuleOrAclMeansNoLink(): void
    {
        $this->enabledModules = ['Panth_AiVisibility'];
        $this->assertNull($this->fixLinks()->resolve(['rule_code' => 'robots_blocks_ai_search']));

        $this->enabledModules[] = 'Panth_RobotsSeo';
        $this->aclAllowed = false;
        $this->assertNull($this->fixLinks()->resolve(['rule_code' => 'robots_blocks_ai_search']));
        $this->assertContains('Panth_RobotsSeo::config', $this->aclAsked);
    }

    public function testRedirectsFallBackToUrlRewritesAndNeedAController(): void
    {
        $this->enabledModules = ['Panth_Redirects'];
        $redirects = class_exists('Panth\Redirects\Controller\Adminhtml\Redirect\Index');
        $link = $this->fixLinks()->resolve(['rule_code' => 'bot_404']);
        if ($redirects) {
            $this->assertStringContainsString('panth_redirects/redirect/index', (string)($link['url'] ?? ''));
        } else {
            $this->assertNull($link);
        }

        $this->enabledModules = ['Magento_UrlRewrite'];
        $link = $this->fixLinks()->resolve(['rule_code' => 'bot_404']);
        $this->assertStringContainsString('adminhtml/url_rewrite/index', (string)($link['url'] ?? ''));
    }
}
