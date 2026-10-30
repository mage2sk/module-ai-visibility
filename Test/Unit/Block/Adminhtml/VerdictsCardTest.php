<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Block\Adminhtml;

use Panth\AiVisibility\Block\Adminhtml\VerdictsCard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VerdictsCardTest extends TestCase
{
    use RecommendationFixtureTrait;

    private function card(): VerdictsCard
    {
        return new VerdictsCard($this->blockContext(), $this->dataSource(), $this->presenter());
    }

    public function testPrintsNothingWithoutActions(): void
    {
        $this->assertSame('', $this->callProtected($this->card(), '_toHtml'));
    }

    public function testPrintsNothingWithoutAcl(): void
    {
        $this->aclAllowed = false;
        $this->actionRows = [self::action(1, 'pending', null)];
        $this->assertSame('', $this->callProtected($this->card(), '_toHtml'));
    }

    public function testOnlyPendingShowsWaitingCount(): void
    {
        $this->actionRows = [self::action(1, 'pending', null), self::action(2, 'pending', null)];
        $card = $this->card();

        $html = $this->renderTemplate($card, 'recommendation/verdicts_card.phtml');

        $this->assertSame([], $card->getLatestVerdicts());
        $this->assertStringContainsString('No applied fix has been checked yet.', $html);
        $this->assertStringContainsString('2 applied fixes are waiting for enough data.', $html);
    }

    public static function verdictProvider(): array
    {
        return [
            'helped' => ['helped', 1.38, 1.12, 1.70, 'Likely helped: +38% (range +12% to +70%)'],
            'no effect' => ['no_effect', 0.98, 0.85, 1.10, 'No clear effect: -2% (range -15% to +10%)'],
            'worse' => ['worse', 0.6, 0.4, 0.9, 'Likely made it worse: -40% (range -60% to -10%)'],
            'insufficient with estimate' => [
                'insufficient', 1.2, 0.7, 1.9, 'Not enough data yet (rough estimate +20%, range -30% to +90%)',
            ],
        ];
    }

    /**
     * @dataProvider verdictProvider
     */
    #[DataProvider('verdictProvider')]
    public function testLatestThreeVerdictsSkipPendingAndUndone(
        string $verdict,
        float $effect,
        float $low,
        float $high,
        string $expected
    ): void {
        $impact = self::impact($verdict, $effect, $low, $high);
        $this->actionRows = [
            self::action(9, 'pending', null),
            self::action(8, 'undone', $impact),
            self::action(7, 'verified', $impact, ['title' => '<script>x</script>']),
            self::action(6, 'verified', $impact),
            self::action(5, 'insufficient', $impact),
            self::action(4, 'verified', $impact),
        ];
        $card = $this->card();

        $html = $this->renderTemplate($card, 'recommendation/verdicts_card.phtml');

        $this->assertSame([7, 6, 5], array_column($card->getLatestVerdicts(), 'action_id'));
        $this->assertSame(3, substr_count($html, $expected));
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('1 applied fix is waiting', $html);
        $this->assertStringContainsString('panth_aivisibility/recommendation/index', $html);
    }

    public function testAddsItsStylesheet(): void
    {
        $this->callProtected($this->card(), '_prepareLayout');
        $this->assertSame(['Panth_AiVisibility::css/recommendations.css'], $this->pageAssets);
    }
}
