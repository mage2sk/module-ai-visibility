<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Model;

use Panth\AiVisibility\Model\Finding;
use PHPUnit\Framework\TestCase;

class FindingTest extends TestCase
{
    public function testKeepsValidValues(): void
    {
        $finding = new Finding(
            'bot_404',
            1,
            Finding::SUBJECT_PATH,
            '/old-bag.html',
            '12 pages answer 404 to AI bots',
            ['top' => ['/old-bag.html' => 30]],
            42,
            77,
            'bot_404',
            ['/old-bag.html', '/old-shoe.html']
        );

        $this->assertSame('bot_404', $finding->ruleCode);
        $this->assertSame(1, $finding->storeId);
        $this->assertSame('path', $finding->subjectType);
        $this->assertSame('/old-bag.html', $finding->subjectRef);
        $this->assertSame('12 pages answer 404 to AI bots', $finding->title);
        $this->assertSame(['top' => ['/old-bag.html' => 30]], $finding->evidence);
        $this->assertSame(42, $finding->affectedCount);
        $this->assertSame(77, $finding->impactScore);
        $this->assertSame('bot_404', $finding->metric);
        $this->assertSame(['/old-bag.html', '/old-shoe.html'], $finding->subjects);
    }

    public function testFingerprintIsSha1OfRuleStoreAndSubject(): void
    {
        $finding = new Finding('bot_404', 2, 'path', '/a.html', 'x');

        $this->assertSame(sha1('bot_404|2|/a.html'), $finding->fingerprint());
        $this->assertSame(40, strlen($finding->fingerprint()));
        $this->assertNotSame(
            $finding->fingerprint(),
            (new Finding('bot_404', 1, 'path', '/a.html', 'x'))->fingerprint(),
            'store view is part of the identity'
        );
        $this->assertSame(
            $finding->fingerprint(),
            (new Finding(' bot_404 ', 2, 'path', ' /a.html ', 'other title', [], 9, 9))->fingerprint(),
            'title, counts and padding do not change the identity'
        );
    }

    public function testNormalisesOutOfRangeValues(): void
    {
        $finding = new Finding(
            str_repeat('r', 40),
            -1,
            'PRODUCT',
            str_repeat('p', 600),
            str_repeat('t', 300),
            [],
            -5,
            250,
            str_repeat('m', 40)
        );

        $this->assertSame(32, strlen($finding->ruleCode));
        $this->assertSame(0, $finding->storeId);
        $this->assertSame('product', $finding->subjectType);
        $this->assertSame(512, strlen($finding->subjectRef));
        $this->assertSame(255, strlen($finding->title));
        $this->assertSame(0, $finding->affectedCount);
        $this->assertSame(100, $finding->impactScore);
        $this->assertSame(32, strlen($finding->metric));
        $this->assertSame([$finding->subjectRef], $finding->subjects);
        $this->assertSame(0, (new Finding('r', 0, 'site', 'site', 't', [], 0, -4))->impactScore);
    }

    public function testUnknownSubjectTypeBecomesSite(): void
    {
        $this->assertSame('site', (new Finding('r', 0, 'planet', 'site', 't'))->subjectType);
    }

    public function testMultibyteValuesAreCutByCharacters(): void
    {
        $finding = new Finding('r', 0, 'path', '/' . str_repeat('ü', 600), str_repeat('é', 300));

        $this->assertSame(512, mb_strlen($finding->subjectRef));
        $this->assertSame(255, mb_strlen($finding->title));
    }

    public function testSubjectsAreDeduplicatedStringsAndDefaultToTheSubjectRef(): void
    {
        $finding = new Finding('r', 0, 'product', '12', 't', [], 0, 0, 'bot_hits', [12, '12', ' 13 ', '', null, [1], 14]);

        $this->assertSame(['12', '13', '14'], $finding->subjects);
        $this->assertSame(['site'], (new Finding('r', 0, 'site', 'site', 't'))->subjects);
        $this->assertSame([], (new Finding('r', 0, 'site', '', 't'))->subjects);
    }

    public function testWithImpactScoreReturnsAClampedCopy(): void
    {
        $finding = new Finding('r', 3, 'bot', 'gptbot', 't', ['a' => 1], 5, 10, 'bot_hits', ['gptbot']);

        $copy = $finding->withImpactScore(140);

        $this->assertNotSame($finding, $copy);
        $this->assertSame(10, $finding->impactScore);
        $this->assertSame(100, $copy->impactScore);
        $this->assertSame($finding->fingerprint(), $copy->fingerprint());
        $this->assertSame(['a' => 1], $copy->evidence);
        $this->assertSame(['gptbot'], $copy->subjects);
    }

    public function testToArray(): void
    {
        $finding = new Finding('llms_not_served', 1, 'site', 'site', 'llms.txt is not served', ['status' => 404], 31, 60, 'llms_fetches');

        $this->assertSame([
            'fingerprint' => sha1('llms_not_served|1|site'),
            'rule_code' => 'llms_not_served',
            'store_id' => 1,
            'subject_type' => 'site',
            'subject_ref' => 'site',
            'title' => 'llms.txt is not served',
            'evidence' => ['status' => 404],
            'affected_count' => 31,
            'impact_score' => 60,
            'metric' => 'llms_fetches',
            'subjects' => ['site'],
        ], $finding->toArray());
    }

    public function testPropertiesAreReadOnly(): void
    {
        $finding = new Finding('r', 0, 'site', 'site', 't');

        $this->expectException(\Error::class);
        $finding->impactScore = 5;
    }
}
