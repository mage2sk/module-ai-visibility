<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Ui\Component\Listing\Column;

use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\BotClassifier;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Service\BotNames;
use Panth\AiVisibility\Service\Discovery\CandidateRepository;
use Panth\AiVisibility\Ui\Component\Listing\Column\BotName;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BotNameTest extends TestCase
{
    private function column($keyValues): BotName
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));
        $provider = $this->createStub(RuleProvider::class);
        $provider->method('botRules')->willReturn([]);
        $candidates = $this->createStub(CandidateRepository::class);
        if ($keyValues instanceof \Throwable) {
            $candidates->method('keyValues')->willThrowException($keyValues);
        } else {
            $candidates->method('keyValues')->willReturn($keyValues);
        }
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES));

        return new BotName(
            $context,
            $this->createStub(UiComponentFactory::class),
            new BotNames(new BotClassifier($this->createStub(Config::class), $provider)),
            $candidates,
            $escaper,
            $this->createStub(LoggerInterface::class),
            [],
            ['name' => 'bot_code']
        );
    }

    public function testNamesAndEscapedCandidateUserAgents(): void
    {
        $data = ['data' => ['items' => [
            ['bot_code' => 'gptbot'],
            ['bot_code' => 'cand:4'],
            ['bot_code' => 'cand:5'],
        ]]];

        $result = $this->column([4 => 'Evil<script>Bot/1.0'])->prepareDataSource($data);

        $items = $result['data']['items'];
        $this->assertSame('GPTBot (OpenAI)', $items[0]['bot_code']);
        $this->assertSame('Unknown bot #4<br/><small>Evil&lt;script&gt;Bot/1.0</small>', $items[1]['bot_code']);
        $this->assertSame('Unknown bot #5', $items[2]['bot_code']);
    }

    public function testCandidateLookupFailureStillRendersNames(): void
    {
        $data = ['data' => ['items' => [['bot_code' => 'cand:4']]]];
        $result = $this->column(new \RuntimeException('db'))->prepareDataSource($data);
        $this->assertSame('Unknown bot #4', $result['data']['items'][0]['bot_code']);
    }

    public function testNoItemsIsUnchanged(): void
    {
        $this->assertSame(['data' => []], $this->column([])->prepareDataSource(['data' => []]));
    }
}
