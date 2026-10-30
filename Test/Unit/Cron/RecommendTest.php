<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Cron;

use Panth\AiVisibility\Api\RecommendationEngineInterface;
use Panth\AiVisibility\Cron\Recommend;
use Panth\AiVisibility\Model\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecommendTest extends TestCase
{
    public function testRunsTheEngineForEveryStoreWhenEnabled(): void
    {
        $engine = $this->createMock(RecommendationEngineInterface::class);
        $engine->expects($this->once())->method('run')->with()
            ->willReturn(['created' => 0, 'updated' => 0, 'resolved' => 0]);

        (new Recommend($engine, $this->config(true), $this->createStub(LoggerInterface::class)))->execute();
    }

    public function testPartialRunIsSummarisedInTheLog(): void
    {
        $engine = $this->createStub(RecommendationEngineInterface::class);
        $engine->method('run')->willReturn([
            'created' => 0, 'updated' => 0, 'resolved' => 0,
            'errors' => ['store 1, rule live_answers_no_sales: server has gone away'],
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->logicalAnd(
            $this->stringContains('1 failed rule'),
            $this->stringContains('live_answers_no_sales')
        ));

        (new Recommend($engine, $this->config(true), $logger))->execute();
    }

    public function testCleanRunLogsNothing(): void
    {
        $engine = $this->createStub(RecommendationEngineInterface::class);
        $engine->method('run')->willReturn(['created' => 1, 'updated' => 0, 'resolved' => 0, 'errors' => []]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        (new Recommend($engine, $this->config(true), $logger))->execute();
    }

    public function testDoesNothingWhenDisabled(): void
    {
        $engine = $this->createMock(RecommendationEngineInterface::class);
        $engine->expects($this->never())->method('run');

        (new Recommend($engine, $this->config(false), $this->createStub(LoggerInterface::class)))->execute();
    }

    public function testNeverThrows(): void
    {
        $engine = $this->createStub(RecommendationEngineInterface::class);
        $engine->method('run')->willThrowException(new \RuntimeException('db gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('db gone'));

        (new Recommend($engine, $this->config(true), $logger))->execute();
    }

    /**
     * Config stub
     *
     * @param bool $enabled
     * @return Config
     */
    private function config(bool $enabled): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('recommendationsEnabled')->willReturn($enabled);
        return $config;
    }
}
