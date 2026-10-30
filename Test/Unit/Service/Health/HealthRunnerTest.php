<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Health;

use Panth\AiVisibility\Api\HealthCheckInterface;
use Panth\AiVisibility\Api\HealthRepositoryInterface;
use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Service\Health\HealthRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HealthRunnerTest extends TestCase
{
    /**
     * @var array<string, HealthResult>
     */
    private array $saved = [];

    private array $warnings = [];

    private function repository(?\Throwable $failFor = null, string $failCode = ''): HealthRepositoryInterface
    {
        $repository = $this->createStub(HealthRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(
            function (string $code, HealthResult $result) use ($failFor, $failCode): void {
                if ($failFor !== null && $code === $failCode) {
                    throw $failFor;
                }
                $this->saved[$code] = $result;
            }
        );
        return $repository;
    }

    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });
        return $logger;
    }

    private function check(string $code, $outcome): HealthCheckInterface
    {
        $check = $this->createStub(HealthCheckInterface::class);
        $check->method('code')->willReturn($code);
        $check->method('label')->willReturn(ucfirst($code));
        if ($outcome instanceof \Throwable) {
            $check->method('run')->willThrowException($outcome);
        } else {
            $check->method('run')->willReturn($outcome);
        }
        return $check;
    }

    public function testRunsEveryCheckAndSavesResults(): void
    {
        $runner = new HealthRunner($this->repository(), $this->logger(), [
            'a' => $this->check('alpha', new HealthResult(HealthResult::STATUS_OK, 'fine')),
            'b' => $this->check('beta', new HealthResult(HealthResult::STATUS_WARN, 'hmm')),
        ]);

        $results = $runner->runAll();

        $this->assertSame(['alpha', 'beta'], array_keys($results));
        $this->assertSame(HealthResult::STATUS_OK, $results['alpha']->status);
        $this->assertSame(HealthResult::STATUS_WARN, $this->saved['beta']->status);
        $this->assertSame([], $this->warnings);
    }

    public function testFailingCheckDoesNotStopTheOthers(): void
    {
        $runner = new HealthRunner($this->repository(), $this->logger(), [
            'first' => $this->check('first', new \RuntimeException('boom')),
            'second' => $this->check('second', new \Error('fatal-ish')),
            'third' => $this->check('third', new HealthResult(HealthResult::STATUS_OK, 'fine')),
        ]);

        $results = $runner->runAll();

        $this->assertCount(3, $results);
        $this->assertSame(HealthResult::STATUS_UNKNOWN, $results['first']->status);
        $this->assertStringContainsString('boom', $results['first']->message);
        $this->assertSame(HealthResult::STATUS_UNKNOWN, $results['second']->status);
        $this->assertSame(HealthResult::STATUS_OK, $results['third']->status);
        $this->assertCount(3, $this->saved);
        $this->assertCount(2, $this->warnings);
    }

    public function testRepositoryFailureIsLoggedAndOtherResultsAreStillSaved(): void
    {
        $runner = new HealthRunner(
            $this->repository(new \RuntimeException('db down'), 'first'),
            $this->logger(),
            [
                'first' => $this->check('first', new HealthResult(HealthResult::STATUS_OK, 'fine')),
                'second' => $this->check('second', new HealthResult(HealthResult::STATUS_ERROR, 'bad')),
            ]
        );

        $results = $runner->runAll();

        $this->assertCount(2, $results);
        $this->assertSame(['second'], array_keys($this->saved));
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('db down', $this->warnings[0]);
    }

    public function testInvalidStatusBecomesUnknown(): void
    {
        $runner = new HealthRunner($this->repository(), $this->logger(), [
            'x' => $this->check('odd', new HealthResult('purple', 'what')),
        ]);

        $this->assertSame(HealthResult::STATUS_UNKNOWN, $runner->runAll()['odd']->status);
    }

    public function testNonCheckItemsAreSkippedAndBrokenCodeFallsBackToItemName(): void
    {
        $broken = $this->createStub(HealthCheckInterface::class);
        $broken->method('code')->willThrowException(new \RuntimeException('no code'));
        $broken->method('run')->willReturn(new HealthResult(HealthResult::STATUS_OK, 'fine'));

        $runner = new HealthRunner($this->repository(), $this->logger(), [
            'notACheck' => new \stdClass(),
            'brokenCode' => $broken,
        ]);

        $this->assertSame(['brokenCode'], array_keys($runner->getChecks()));
        $this->assertSame(['brokenCode'], array_keys($runner->runAll()));
    }

    public function testNoChecksReturnsEmpty(): void
    {
        $runner = new HealthRunner($this->repository(), $this->logger(), []);
        $this->assertSame([], $runner->runAll());
    }
}
