<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Model\Source;

use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Model\Source\AiSourceOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AiSourceOptionsTest extends TestCase
{
    /**
     * @var int
     */
    private int $calls = 0;

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

    private function options($sources): AiSourceOptions
    {
        $classifier = $this->createStub(SourceClassifierInterface::class);
        $classifier->method('allSources')->willReturnCallback(function () use ($sources): array {
            $this->calls++;
            if ($sources instanceof \Throwable) {
                throw $sources;
            }
            return $sources;
        });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });
        return new AiSourceOptions($classifier, $logger);
    }

    public function testOptionsAreSortedByLabelAndCached(): void
    {
        $options = $this->options([
            'perplexity' => 'Perplexity',
            'chatgpt' => 'ChatGPT',
            'disc_assistant' => '',
            '' => 'Ignored',
            'claude' => 'Claude',
        ]);

        $expected = [
            ['value' => 'chatgpt', 'label' => 'ChatGPT'],
            ['value' => 'claude', 'label' => 'Claude'],
            ['value' => 'disc_assistant', 'label' => 'disc_assistant'],
            ['value' => 'perplexity', 'label' => 'Perplexity'],
        ];
        $this->assertSame($expected, $options->toOptionArray());
        $this->assertSame($expected, $options->toOptionArray());
        $this->assertSame(1, $this->calls);
    }

    public function testClassifierErrorGivesNoOptions(): void
    {
        $this->assertSame([], $this->options(new \RuntimeException('down'))->toOptionArray());
        $this->assertCount(1, $this->warnings);
    }
}
