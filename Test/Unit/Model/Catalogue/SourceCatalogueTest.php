<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Model\Catalogue;

use Panth\AiVisibility\Model\Catalogue\SourceCatalogue;
use PHPUnit\Framework\TestCase;

class SourceCatalogueTest extends TestCase
{
    public function testSourcesAreWellFormed(): void
    {
        $sources = (new SourceCatalogue())->sources();
        $this->assertSame(
            ['chatgpt', 'perplexity', 'gemini', 'copilot', 'claude', 'meta_ai', 'mistral', 'deepseek', 'grok', 'you'],
            array_keys($sources)
        );
        $seenHosts = [];
        $seenUtm = [];
        foreach ($sources as $code => $source) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,64}$/', $code);
            $this->assertNotSame('', $source['name']);
            $this->assertNotEmpty($source['hosts']);
            foreach ($source['hosts'] as $host) {
                $this->assertMatchesRegularExpression('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host);
                $this->assertArrayNotHasKey($host, $seenHosts, 'duplicate host ' . $host);
                $seenHosts[$host] = true;
            }
            foreach ($source['utm'] as $utm) {
                $this->assertMatchesRegularExpression('/^[a-z0-9._-]{1,64}$/', $utm);
                $this->assertArrayNotHasKey($utm, $seenUtm, 'duplicate utm ' . $utm);
                $seenUtm[$utm] = true;
            }
        }
    }

    public function testCandidatePatternCompilesAndHasNoDelimiters(): void
    {
        $pattern = (new SourceCatalogue())->candidatePattern();
        $this->assertStringNotContainsString('/', $pattern);
        $this->assertSame(1, preg_match('/' . $pattern . '/i', 'assistant.example.invalid'));
        $this->assertSame(0, preg_match('/' . $pattern . '/i', 'shop.test'));
    }

    public function testNonAiHosts(): void
    {
        $hosts = (new SourceCatalogue())->nonAiHosts();
        $this->assertContains('google.*', $hosts);
        $this->assertContains('t.co', $hosts);
        $this->assertContains('android-app', $hosts);
    }
}
