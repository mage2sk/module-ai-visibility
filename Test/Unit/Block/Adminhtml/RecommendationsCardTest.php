<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Block\Adminhtml;

use Panth\AiVisibility\Block\Adminhtml\RecommendationsCard;
use PHPUnit\Framework\TestCase;

class RecommendationsCardTest extends TestCase
{
    use RecommendationFixtureTrait;

    private function card(): RecommendationsCard
    {
        return new RecommendationsCard($this->blockContext(), $this->dataSource(), $this->presenter());
    }

    public function testPrintsNothingWithoutAnyRecommendation(): void
    {
        $this->assertSame('', $this->callProtected($this->card(), '_toHtml'));
    }

    public function testPrintsNothingWithoutAclEvenWithRecommendations(): void
    {
        $this->aclAllowed = false;
        $this->openRows = [self::hostileRecommendation()];
        $this->countRows['open'] = 1;

        $this->assertSame('', $this->callProtected($this->card(), '_toHtml'));
        $this->assertSame(['Panth_AiVisibility::recommendations'], $this->aclAsked);
    }

    public function testRendersWhenThereIsHistoryButNothingOpen(): void
    {
        $this->countRows['applied'] = 2;
        $card = $this->card();

        $this->assertSame([], $card->getTopRecommendations());
        $this->assertTrue($card->hasHistory());
        $html = $this->renderTemplate($card, 'recommendation/card.phtml');
        $this->assertStringContainsString('No open recommendations right now.', $html);
        $this->assertStringContainsString('panth_aivisibility/recommendation/index', $html);
    }

    public function testTopThreeAreListedEscapedWithAnchors(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->openRows[] = self::hostileRecommendation($i, ['title' => '<script>t' . $i . '</script>']);
        }
        $this->countRows['open'] = 5;
        $card = $this->card();

        $html = $this->renderTemplate($card, 'recommendation/card.phtml');

        $this->assertCount(3, $card->getTopRecommendations());
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;t1&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('t4', $html);
        $this->assertStringContainsString('recommendation/index#panth-aiv-rec-2', $html);
        $this->assertStringContainsString('impact 87 of 100', $html);
        $this->assertStringContainsString('1,234 affected', $html);
        $this->assertStringContainsString('Open Recommendations (5 open)', $html);
    }

    public function testAddsItsStylesheet(): void
    {
        $this->callProtected($this->card(), '_prepareLayout');
        $this->assertSame(['Panth_AiVisibility::css/recommendations.css'], $this->pageAssets);
    }
}
