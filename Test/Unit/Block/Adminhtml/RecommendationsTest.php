<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Block\Adminhtml;

use Panth\AiVisibility\Block\Adminhtml\Recommendations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecommendationsTest extends TestCase
{
    use RecommendationFixtureTrait;

    private function block(): Recommendations
    {
        return new Recommendations(
            $this->blockContext(),
            $this->dataSource(),
            $this->presenter(),
            $this->fixLinks(),
            $this->bars(),
            $this->moduleConfig(),
            $this->timezone(),
            $this->logger()
        );
    }

    private function render(): string
    {
        return $this->renderTemplate($this->block(), 'recommendation/index.phtml');
    }

    public function testEmptyStatesRender(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('No open recommendations right now.', $html);
        $this->assertStringContainsString('No fixes applied yet.', $html);
        $this->assertStringContainsString('0 open, 0 applied, 0 dismissed, 0 resolved', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('switched off', $html);
    }

    public function testDisabledShowsWarningAndHidesRefresh(): void
    {
        $this->recommendationsEnabled = false;
        $html = $this->render();

        $this->assertStringContainsString('Recommendations are switched off', $html);
        $this->assertStringContainsString('section/panth_aivisibility', $html);
        $this->assertStringNotContainsString('recommendation/refresh', $html);
    }

    public function testFailingRepositoryRendersEmptyAndLogs(): void
    {
        $this->repositoryFails = true;
        $html = $this->render();

        $this->assertStringContainsString('No open recommendations right now.', $html);
        $this->assertStringContainsString('No fixes applied yet.', $html);
        $this->assertNotEmpty($this->loggedWarnings);
    }

    public function testHostileTitleEvidenceAndNotesAreEscaped(): void
    {
        $this->openRows = [self::hostileRecommendation()];
        $this->countRows['open'] = 1;
        $this->actionRows = [self::action(3, 'pending', null, [
            'title' => '<b onmouseover=alert(3)>fix</b>',
            'note' => '"><script>alert(4)</script>',
            'applied_by' => '<i>eve</i>',
        ])];

        $html = $this->render();

        foreach (['<script', '<img', '<svg onload', '<b onmouseover', '<i>eve', 'onerror=alert(1)>'] as $raw) {
            $this->assertStringNotContainsString($raw, $html, $raw . ' must be escaped');
        }
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;t&quot;)&lt;/script&gt; broken pages', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;i&gt;eve&lt;/i&gt;', $html);
        $this->assertStringContainsString('Second &lt;b&gt;View&lt;/b&gt;', $html);
        // The form key is printed in an attribute, escaped.
        $this->assertStringNotContainsString('value="fk"<x>"', $html);
        $this->assertStringContainsString('fk&quot;&lt;x&gt;', $html);
    }

    public function testOpenRecommendationShowsEvidenceExamplesFixLinkAndForms(): void
    {
        $this->enabledModules[] = 'Magento_UrlRewrite';
        $this->openRows = [self::hostileRecommendation(7, ['title' => 'Fix 404 pages', 'feedback' => 1])];
        $this->countRows = ['open' => 1, 'applied' => 2, 'dismissed' => 3, 'resolved' => 4];

        $html = $this->render();

        $this->assertStringContainsString('1 open, 2 applied, 3 dismissed, 4 resolved', $html);
        $this->assertStringContainsString('id="panth-aiv-rec-7"', $html);
        $this->assertStringContainsString('Fix 404 pages', $html);
        $this->assertStringContainsString('Pages answer client errors (4xx) to AI bots', $html);
        $this->assertStringContainsString('1,234', $html);
        $this->assertStringContainsString('Client-error hits', $html);
        $this->assertStringContainsString('>123<', $html);
        $this->assertStringContainsString('<meter min="0" max="100" value="87"', $html);
        $this->assertStringContainsString('Top examples', $html);
        $this->assertStringContainsString('/c - hits 2', $html);
        $this->assertStringContainsString('How to fix', $html);
        $this->assertStringContainsString('adminhtml/url_rewrite/index', $html);
        foreach (['apply', 'dismiss', 'feedback'] as $action) {
            $this->assertStringContainsString('recommendation/' . $action . '"', $html);
        }
        $this->assertStringContainsString('name="rec_id" value="7"', $html);
        $this->assertStringContainsString('maxlength="512"', $html);
        $this->assertStringContainsString('aria-pressed="true">Useful', $html);
        $this->assertStringContainsString('aria-pressed="false">Not useful', $html);
        $this->assertStringContainsString('<details', $html);
        $this->assertStringNotContainsString('confirm(', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertSame(1, substr_count($html, 'method="post" action="https://shop.test/admin/panth_aivisibility/recommendation/apply"'));
    }

    public function testNoFixLinkWhenTargetModuleIsMissing(): void
    {
        $this->enabledModules = ['Panth_AiVisibility'];
        $this->openRows = [self::hostileRecommendation(7, ['rule_code' => 'llms_not_served', 'subject_type' => 'site'])];

        $html = $this->render();

        $this->assertStringNotContainsString('panth_llms_txt', $html);
        $this->assertStringContainsString('How to fix', $html);
    }

    public function testStoreFilterKeepsStoreInFormsAndFiltersRows(): void
    {
        $this->requestParams = ['store' => '2'];
        $this->openRows = [
            self::hostileRecommendation(7, ['title' => 'Store one item', 'store_id' => 1]),
            self::hostileRecommendation(8, ['title' => 'Store two item', 'store_id' => 2]),
        ];

        $html = $this->render();

        $this->assertStringContainsString('Store two item', $html);
        $this->assertStringNotContainsString('Store one item', $html);
        $this->assertStringContainsString('<input type="hidden" name="store" value="2"/>', $html);
        $this->assertStringContainsString('<option value="2" selected>', $html);
        $this->assertStringContainsString('Check this store view now', $html);
    }

    public function testUnknownStoreParamMeansAllStores(): void
    {
        foreach (['99', 'abc', '-1', ['1']] as $value) {
            $this->requestParams = ['store' => $value];
            $this->assertNull($this->block()->getStoreId());
        }
        $this->requestParams = ['store' => '1'];
        $this->assertSame(1, $this->block()->getStoreId());
    }

    public static function verdictProvider(): array
    {
        return [
            'helped' => ['helped', 1.38, 1.12, 1.70, 'Likely helped: +38% (range +12% to +70%)', 'good'],
            'no effect' => ['no_effect', 1.02, 0.90, 1.15, 'No clear effect: +2% (range -10% to +15%)', 'neutral'],
            'worse' => ['worse', 0.70, 0.55, 0.88, 'Likely made it worse: -30% (range -45% to -12%)', 'bad'],
            'insufficient' => ['insufficient', null, null, null, 'Not enough data yet', 'unknown'],
        ];
    }

    /**
     * @dataProvider verdictProvider
     */
    #[DataProvider('verdictProvider')]
    public function testVerdictWordingAndBars(
        string $verdict,
        ?float $effect,
        ?float $low,
        ?float $high,
        string $expected,
        string $tone
    ): void {
        $status = $verdict === 'insufficient' ? 'insufficient' : 'verified';
        $this->actionRows = [self::action(5, $status, self::impact($verdict, $effect, $low, $high))];

        $html = $this->render();

        $this->assertStringContainsString($expected, $html);
        $this->assertStringContainsString('panth-aiv-rec__verdict--' . $tone, $html);
        $this->assertStringContainsString('<svg class="panth-aiv-bars"', $html);
        $this->assertStringContainsString('compared with 12 similar items', $html);
        $this->assertStringNotContainsString('recommendation/undo', $html);
        $this->assertStringContainsString('3 items', $html);
    }

    public function testPendingActionShowsVerifyDayAndUndo(): void
    {
        $this->actionRows = [self::action(5, 'pending', null, ['applied_by' => null])];

        $html = $this->render();

        $this->assertStringContainsString('Waiting for data, checked on or after day 2026-10-16', $html);
        $this->assertStringContainsString('recommendation/undo"', $html);
        $this->assertStringContainsString('name="action_id" value="5"', $html);
        $this->assertStringContainsString('by command line', $html);
        $this->assertStringContainsString('at 2026-10-01 09:30', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }

    public function testUndoneActionHasNoUndoAndNoBars(): void
    {
        $this->actionRows = [self::action(5, 'undone', self::impact('helped', 1.4, 1.1, 1.8), [
            'undone_at' => '2026-10-03 08:00:00',
        ])];

        $html = $this->render();

        $this->assertStringContainsString('Undone on at 2026-10-03 08:00, not verified', $html);
        $this->assertStringNotContainsString('recommendation/undo', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }

    public function testActionTitleComesFromTheJoinedRecommendationThenRuleLabel(): void
    {
        // A per-row repository lookup would find this title; the list must not do one (no N+1).
        $this->recById[9] = ['rec_id' => 9, 'title' => 'Looked up separately'];
        $this->actionRows = [
            self::action(1, 'pending', null, ['rec_id' => 7, 'title' => 'Joined title']),
            self::action(2, 'pending', null, ['rec_id' => 9, 'title' => null]),
            self::action(3, 'pending', null, ['rec_id' => null, 'title' => null]),
        ];

        $titles = array_column($this->block()->getActions(), 'title');

        $this->assertSame(
            ['Joined title', 'Products AI crawlers never visited', 'Products AI crawlers never visited'],
            $titles
        );
    }

    public function testFormatUtcDayHandlesEmptyAndBadValues(): void
    {
        $block = $this->block();
        $this->assertSame('', $block->formatUtcDay(null));
        $this->assertSame('day 2026-10-16', $block->formatUtcDay('2026-10-16'));
        $this->assertSame('', $block->formatUtcDate(''));
        $this->assertSame('not-a-date UTC', $block->formatUtcDate('not-a-date'));
    }

    /**
     * The Action column (dark header) is only there when a row can be undone, and then it has a visible header
     */
    public function testActionColumnOnlyWithAnUndoableRow(): void
    {
        $this->actionRows = [self::action(5, 'verified', self::impact('helped', 1.4, 1.1, 1.8))];
        $html = $this->render();
        $this->assertStringNotContainsString('panth-aiv-rec__action-col', $html);

        $this->actionRows[] = self::action(6, 'pending', null);
        $html = $this->render();
        $this->assertSame(1, substr_count($html, '<th class="data-grid-th panth-aiv-rec__action-col" scope="col">Action</th>'));
        $this->assertSame(2, substr_count($html, '<td class="panth-aiv-rec__action-col">'));
    }

    /**
     * Simulated actions never show on the admin page (real-only), so they have no Undo either
     */
    public function testSimulatedActionsAreNotListed(): void
    {
        $this->actionRows = [
            self::action(5, 'pending', null, ['is_simulated' => 1, 'note' => 'Planted by simulate']),
            self::action(6, 'pending', null, ['note' => 'Real change']),
        ];
        $html = $this->render();
        $this->assertStringNotContainsString('Planted by simulate', $html);
        $this->assertStringContainsString('Real change', $html);
        $this->assertStringNotContainsString('name="action_id" value="5"', $html);
    }

    /**
     * Evidence: the rule's how_to_fix text is not repeated as a fact, the bots are listed, dates are UTC
     */
    public function testEvidenceIsShownReadably(): void
    {
        $this->openRows = [self::hostileRecommendation(7, ['evidence' => json_encode([
            'window' => ['from' => '2026-10-23', 'to' => '2026-10-29'],
            'hits_404' => 36,
            'share_pct' => 27.7,
            'top_paths' => [['path' => '/gone', 'hits' => 9, 'last_seen' => '2026-10-29 11:00:00']],
            'top_bots' => [['code' => 'gptbot', 'name' => 'GPTBot <b>x</b>', 'hits' => 36]],
            'how_to_fix' => 'Stored fix text',
            'subjects' => ['/gone'],
        ])])];
        $html = $this->render();
        $this->assertStringNotContainsString('Stored fix text', $html);
        $this->assertSame(1, substr_count($html, 'How to fix'));
        $this->assertStringContainsString('<dt>Dates (UTC)</dt>', $html);
        $this->assertStringContainsString('<dd>2026-10-23 to 2026-10-29</dd>', $html);
        $this->assertStringContainsString('<dt>Client-error hits</dt>', $html);
        $this->assertStringContainsString('last seen 2026-10-29 11:00:00 UTC', $html);
        $this->assertStringContainsString('GPTBot &lt;b&gt;x&lt;/b&gt; (36 hits)', $html);
    }
}
