<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Ui\Component\Listing\Column\AiSource;
use PHPUnit\Framework\TestCase;

class AiSourceTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $lookups = [];

    private function column(bool $failing = false): AiSource
    {
        $classifier = $this->createStub(SourceClassifierInterface::class);
        $classifier->method('nameFor')->willReturnCallback(function (string $code) use ($failing): string {
            $this->lookups[] = $code;
            if ($failing) {
                throw new \RuntimeException('catalogue unavailable');
            }
            return ['chatgpt' => 'ChatGPT', 'mistral' => 'Le Chat (Mistral)', 'blank' => ''][$code] ?? $code;
        });
        return new AiSource(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $classifier,
            [],
            ['name' => 'ai_source']
        );
    }

    public function testDataSourceWithoutItemsIsUnchanged(): void
    {
        $source = ['data' => ['totalRecords' => 0]];
        $this->assertSame($source, $this->column()->prepareDataSource($source));
    }

    public function testCodesAreReplacedByDisplayNames(): void
    {
        $result = $this->column()->prepareDataSource(['data' => ['items' => [
            ['entity_id' => '1', 'ai_source' => 'chatgpt'],
            ['entity_id' => '2', 'ai_source' => null],
            ['entity_id' => '3'],
            ['entity_id' => '4', 'ai_source' => 'mistral'],
            ['entity_id' => '5', 'ai_source' => 'chatgpt'],
            ['entity_id' => '6', 'ai_source' => 'removed_source'],
            ['entity_id' => '7', 'ai_source' => 'blank'],
        ]]]);

        $items = $result['data']['items'];
        $this->assertSame('ChatGPT', $items[0]['ai_source']);
        $this->assertSame('', $items[1]['ai_source']);
        $this->assertSame('', $items[2]['ai_source']);
        $this->assertSame('Le Chat (Mistral)', $items[3]['ai_source']);
        $this->assertSame('ChatGPT', $items[4]['ai_source']);
        $this->assertSame('removed_source', $items[5]['ai_source']);
        $this->assertSame('blank', $items[6]['ai_source']);
        $this->assertSame('1', $items[0]['entity_id']);
        // One lookup per distinct code.
        $this->assertSame(['chatgpt', 'mistral', 'removed_source', 'blank'], $this->lookups);
    }

    public function testClassifierErrorFallsBackToTheCode(): void
    {
        $result = $this->column(true)->prepareDataSource(['data' => ['items' => [['ai_source' => 'chatgpt']]]]);
        $this->assertSame('chatgpt', $result['data']['items'][0]['ai_source']);
    }
}
