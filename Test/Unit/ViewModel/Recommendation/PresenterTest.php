<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\ViewModel\Recommendation;

use Panth\AiVisibility\ViewModel\Recommendation\Presenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PresenterTest extends TestCase
{
    public static function percentProvider(): array
    {
        return [
            'gain' => [1.38, '+38%'],
            'loss' => [0.62, '-38%'],
            'none' => [1.0, '0%'],
            'tiny loss rounds to zero' => [0.999, '0%'],
            'large' => [12.5, '+1,150%'],
        ];
    }

    /**
     * @dataProvider percentProvider
     */
    #[DataProvider('percentProvider')]
    public function testPercentChange(float $ratio, string $expected): void
    {
        $this->assertSame($expected, (new Presenter())->percentChange($ratio));
    }

    public static function sentenceProvider(): array
    {
        return [
            'helped' => [['verdict' => 'helped', 'effect' => '1.3800', 'ci_low' => '1.1200', 'ci_high' => '1.7000'],
                'Likely helped: +38% (range +12% to +70%)'],
            'helped less-is-better' => [['verdict' => 'helped', 'effect' => '0.6', 'ci_low' => '0.4', 'ci_high' => '0.85'],
                'Likely helped: -40% (range -60% to -15%)'],
            'no effect' => [['verdict' => 'no_effect', 'effect' => 1.0, 'ci_low' => 0.9, 'ci_high' => 1.1],
                'No clear effect: 0% (range -10% to +10%)'],
            'worse' => [['verdict' => 'worse', 'effect' => 0.8, 'ci_low' => 0.7, 'ci_high' => 0.9],
                'Likely made it worse: -20% (range -30% to -10%)'],
            'insufficient no numbers' => [['verdict' => 'insufficient', 'effect' => null], 'Not enough data yet'],
            'insufficient with estimate' => [['verdict' => 'insufficient', 'effect' => 1.5, 'ci_low' => 0.5, 'ci_high' => 3],
                'Not enough data yet (rough estimate +50%, range -50% to +200%)'],
            'missing range' => [['verdict' => 'helped', 'effect' => 1.5], 'Likely helped: +50% (no reliable range)'],
            'swapped bounds' => [['verdict' => 'no_effect', 'effect' => 1.0, 'ci_low' => 1.2, 'ci_high' => 0.8],
                'No clear effect: 0% (range -20% to +20%)'],
            'unknown verdict' => [['verdict' => '<b>x</b>', 'effect' => null], 'Not enough data yet'],
        ];
    }

    /**
     * @dataProvider sentenceProvider
     */
    #[DataProvider('sentenceProvider')]
    public function testEffectSentence(array $impact, string $expected): void
    {
        $this->assertSame($expected, (new Presenter())->effectSentence($impact));
    }

    public function testEffectSentenceOfNullIsEmpty(): void
    {
        $this->assertSame('', (new Presenter())->effectSentence(null));
    }

    public function testVerdictToneAndLabels(): void
    {
        $p = new Presenter();
        $this->assertSame('good', $p->verdictTone('helped'));
        $this->assertSame('bad', $p->verdictTone('worse'));
        $this->assertSame('neutral', $p->verdictTone('no_effect'));
        $this->assertSame('unknown', $p->verdictTone('insufficient'));
        $this->assertSame('Pages answer client errors (4xx) to AI bots', $p->ruleLabel('bot_404'));
        $this->assertSame('Custom rule', $p->ruleLabel('custom_rule'));
        $this->assertSame('AI crawler hits', $p->metricLabel('bot_hits'));
        $this->assertNotSame('', $p->fixHint('llms_not_served'));
        $this->assertSame('', $p->fixHint('unknown'));
        $this->assertTrue($p->isLessBetter('bot_404'));
        $this->assertFalse($p->isLessBetter('bot_hits'));
    }

    public function testMethodSentence(): void
    {
        $p = new Presenter();
        $this->assertSame(
            '14 days before vs 14 days after, compared with 9 similar items you did not change.',
            $p->methodSentence(['method' => 'did', 'window_days' => 14, 'subjects_control' => 9])
        );
        $this->assertSame(
            '7 days before vs 7 days after the change.',
            $p->methodSentence(['method' => 'before_after', 'window_days' => 7])
        );
        $this->assertSame(
            'Daily average over 14 days before vs 14 days after the change, allowing for normal day-to-day swings.',
            $p->methodSentence(['method' => 'welch_t', 'window_days' => 14])
        );
        $this->assertSame(
            '12 of 40 fixed items were fetched by AI bots in the 14 days after, compared with 30 of 600 similar '
            . 'never-fetched items in the 14 days before.',
            $p->methodSentence(['method' => 'coverage', 'window_days' => 14, 'details' => ['coverage' => [
                'treated_fetched' => 12, 'treated' => 40, 'baseline_fetched' => 30, 'baseline' => 600,
            ]]])
        );
    }

    public function testFormatMetricValue(): void
    {
        $p = new Presenter();
        $this->assertSame('1,200', $p->formatMetricValue('bot_hits', 1200.0));
        $this->assertSame('12.5', $p->formatMetricValue('bot_hits', 12.5));
        $this->assertSame('2.5%', $p->formatMetricValue('ai_conversion', 0.025));
        $this->assertSame('2,400 ms', $p->formatMetricValue('bot_avg_ms', 2400.0));
    }

    public function testEvidenceFactsAndExamples(): void
    {
        $p = new Presenter();
        $evidence = [
            'share' => 0.4567,
            'blocked' => true,
            'bot_count' => 3,
            'nested' => ['a' => 1],
            'top_paths' => [['path' => '/x', 'hits' => 1500, 'target' => '/y'], ['', ['deep']], '/plain'],
            'subjects' => ['/x', '/z'],
        ];

        $this->assertSame([
            ['label' => 'Share', 'value' => '0.46'],
            ['label' => 'Blocked', 'value' => 'yes'],
            ['label' => 'Bot count', 'value' => '3'],
        ], $p->evidenceFacts($evidence));
        $this->assertSame(['/x - hits 1,500, target /y', '/plain'], $p->evidenceExamples($evidence));
        $this->assertSame(['/x', '/z'], $p->subjects($evidence));
    }

    public function testExamplesFallBackToSubjectsAndAreCapped(): void
    {
        $p = new Presenter();
        $subjects = array_map('strval', range(1, 12));
        $this->assertSame(
            array_map('strval', range(1, Presenter::MAX_EXAMPLES)),
            $p->evidenceExamples(['subjects' => $subjects, 'count' => 12])
        );
        $this->assertSame([], $p->evidenceExamples([]));
        $long = str_repeat('a', 400);
        $this->assertSame(200, mb_strlen($p->evidenceExamples(['subjects' => [$long]])[0]));
    }

    /**
     * R2 evidence as stored by Rule\Bot404: readable labels, one date range, no duplicate "how to fix",
     * every stored path, the bots and UTC times
     */
    public function testBot404EvidenceIsReadable(): void
    {
        $p = new Presenter();
        $paths = [];
        foreach (range(1, 6) as $i) {
            $paths[] = ['path' => '/gone-' . $i, 'hits' => 7 - $i, 'bots' => 1, 'last_seen' => '2026-10-29 11:00:00',
                'redirect_to' => $i === 1 ? '/new' : null];
        }
        $evidence = [
            'window' => ['from' => '2026-10-23', 'to' => '2026-10-29'],
            'hits_4xx' => 36,
            'paths_4xx' => 6,
            'bots' => 1,
            'all_bot_hits' => 130,
            'share_pct' => 27.7,
            'top_paths' => $paths,
            'redirect_guesses' => 1,
            'top_bots' => [['code' => 'gptbot', 'name' => 'GPTBot', 'hits' => 36]],
            'how_to_fix' => 'Add a 301 redirect from each dead URL ...',
            'subjects' => ['/gone-1'],
        ];

        $this->assertSame([
            ['label' => 'Dates (UTC)', 'value' => '2026-10-23 to 2026-10-29'],
            ['label' => 'Client-error hits', 'value' => '36'],
            ['label' => 'Pages with client errors', 'value' => '6'],
            ['label' => 'AI bots', 'value' => '1'],
            ['label' => 'All AI bot hits', 'value' => '130'],
            ['label' => 'Share of all AI bot hits', 'value' => '27.7%'],
            ['label' => 'Redirect suggestions', 'value' => '1'],
        ], $p->evidenceFacts($evidence, 'bot_404'));
        $examples = $p->evidenceExamples($evidence);
        $this->assertCount(6, $examples);
        $this->assertSame(
            '/gone-1 - hits 6, 1 bot, last seen 2026-10-29 11:00:00 UTC, suggested redirect /new',
            $examples[0]
        );
        $this->assertSame(['GPTBot (36 hits)'], $p->evidenceBots($evidence));
    }

    /**
     * R7 evidence: a top-level from/to range, booleans, llms labels, reason wording, bots from the
     * "examples" list (rows with a bot key) and no "site" example when the list is empty
     */
    public function testLlmsEvidenceIsReadableAndShowsNoSiteExample(): void
    {
        $p = new Presenter();
        $evidence = [
            'window_days' => 30, 'from' => '2026-10-01', 'to' => '2026-10-30', 'reason' => 'never_fetched',
            'module_enabled' => true, 'setting_enabled' => false, 'ai_crawler_hits' => 130, 'llms_requests' => 0,
            'llms_2xx' => 0, 'llms_4xx' => 0, 'llms_5xx' => 0, 'examples' => [], 'subjects' => ['site'],
        ];
        $facts = array_column($p->evidenceFacts($evidence, 'llms_not_served'), 'value', 'label');
        $this->assertSame('2026-10-01 to 2026-10-30', $facts['Dates (UTC)']);
        $this->assertSame('30', $facts['Days checked']);
        $this->assertSame('never fetched', $facts['Reason']);
        $this->assertSame('yes', $facts['Panth_LlmsTxt enabled']);
        $this->assertSame('no', $facts['llms.txt switched on']);
        $this->assertSame('0', $facts['llms.txt answered OK']);
        $this->assertArrayNotHasKey('From', $facts);
        $this->assertSame([], $p->evidenceExamples($evidence));
        $this->assertSame([], $p->evidenceExamples(['subjects' => ['site', 'paths:404', 'products']]));

        $evidence['examples'] = [['bot' => 'ClaudeBot', 'requests' => 3]];
        $this->assertSame(['ClaudeBot (3 requests)'], $p->evidenceBots($evidence));
        $this->assertSame([], $p->evidenceExamples($evidence));
    }

    public function testProductExamplesLeadWithTheName(): void
    {
        $p = new Presenter();
        $this->assertSame(
            ['Blue Shirt - ID 12'],
            $p->evidenceExamples(['examples' => [['product_id' => 12, 'name' => 'Blue Shirt']]])
        );
        $this->assertSame(
            ['Share of products', 'Share of AI crawl', 'Recommended when above'],
            array_column($p->evidenceFacts(
                ['share_pct' => 4, 'share_percent' => 37.3, 'threshold_percent' => 30],
                'never_crawled_products'
            ), 'label')
        );
    }

    public static function coverageProvider(): array
    {
        $details = ['coverage' => ['treated_fetched' => 12, 'treated' => 20, 'baseline_fetched' => 30,
            'baseline' => 200, 'diff' => 0.45, 'diff_low' => 0.22, 'diff_high' => 0.66]];
        return [
            'helped' => [['method' => 'coverage', 'verdict' => 'helped', 'effect' => 4.0, 'ci_low' => 2.5,
                'ci_high' => 6.3, 'details' => $details], '',
                'Likely helped: 60% of fixed products got crawled vs 15% normally (difference +45 points, '
                . 'range +22 to +66 points)'],
            'categories' => [['method' => 'coverage', 'verdict' => 'no_effect', 'details' => ['coverage' => [
                'treated_fetched' => 3, 'treated' => 20, 'baseline_fetched' => 30, 'baseline' => 200,
                'diff' => 0.0, 'diff_low' => -0.15, 'diff_high' => 0.17]]], 'category',
                'No clear effect: 15% of fixed categories got crawled vs 15% normally (difference 0 points, '
                . 'range -15 to +17 points)'],
            'insufficient' => [['method' => 'coverage', 'verdict' => 'insufficient', 'details' => ['coverage' => [
                'treated_fetched' => 1, 'treated' => 2, 'baseline_fetched' => 1, 'baseline' => 10]]], 'product',
                'Not enough data yet (so far 50% of fixed products got crawled vs 10% normally)'],
            'columns only' => [['method' => 'coverage', 'verdict' => 'helped', 'treat_after' => '0.3000',
                'control_after' => '0.0500'], '',
                'Likely helped: 30% of fixed products got crawled vs 5% normally (difference +25 points, '
                . 'no reliable range)'],
            'no numbers' => [['method' => 'coverage', 'verdict' => 'insufficient'], '', 'Not enough data yet'],
        ];
    }

    /**
     * Coverage results compare two shares: no "+300%" ratio of shares, but percentage points
     *
     * @dataProvider coverageProvider
     */
    #[DataProvider('coverageProvider')]
    public function testCoverageSentenceComparesShares(array $impact, string $type, string $expected): void
    {
        $this->assertSame($expected, (new Presenter())->effectSentence($impact, $type));
    }

    /**
     * Every phrase is a literal __() argument, so the i18n collector sees it (no constants passed to __())
     */
    public function testNoConstantsArePassedToTranslation(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 4) . '/ViewModel/Recommendation/Presenter.php');
        $this->assertSame(0, preg_match('/__\(\s*(self::|static::|\$)/', $source));
    }
}
