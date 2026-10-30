<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Health;

use Panth\AiVisibility\Controller\Adminhtml\Health\Run;
use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Service\Health\HealthRunner;
use Panth\AiVisibility\Test\Unit\Controller\Adminhtml\BackendContextTrait;
use PHPUnit\Framework\TestCase;

class RunTest extends TestCase
{
    use BackendContextTrait;

    private int $runs = 0;

    /**
     * @param array|\Throwable $outcome
     * @return Run
     */
    private function controller($outcome): Run
    {
        $runner = $this->createStub(HealthRunner::class);
        $runner->method('runAll')->willReturnCallback(function () use ($outcome) {
            $this->runs++;
            if ($outcome instanceof \Throwable) {
                throw $outcome;
            }
            return $outcome;
        });
        return new Run($this->backendContext(), $runner);
    }

    public function testAclResourceIsDashboard(): void
    {
        $this->assertAclResource($this->controller([]), 'Panth_AiVisibility::dashboard');
    }

    public function testAllOkShowsSuccess(): void
    {
        $this->controller([
            'a' => new HealthResult(HealthResult::STATUS_OK, 'x'),
            'b' => new HealthResult(HealthResult::STATUS_UNKNOWN, 'y'),
        ])->execute();

        $this->assertSame(1, $this->runs);
        $this->assertSame(
            ['Health checks finished: 1 OK, 0 warning(s), 0 error(s), 1 unknown.'],
            $this->messagesOfType('success')
        );
        $this->assertSame('*/dashboard/index', $this->redirectPath);
    }

    public function testProblemsShowWarning(): void
    {
        $this->controller([
            'a' => new HealthResult(HealthResult::STATUS_WARN, 'x'),
            'b' => new HealthResult(HealthResult::STATUS_ERROR, 'y'),
        ])->execute();

        $this->assertSame(
            ['Health checks finished: 0 OK, 1 warning(s), 1 error(s), 0 unknown.'],
            $this->messagesOfType('warning')
        );
    }

    public function testMissingFormKeyDoesNotRun(): void
    {
        $this->formKeyValid = false;

        $this->controller([])->execute();

        $this->assertSame(0, $this->runs);
        $this->assertStringContainsString('expired', $this->messagesOfType('error')[0]);
        $this->assertSame('*/dashboard/index', $this->redirectPath);
    }

    public function testRunnerFailureIsShown(): void
    {
        $this->controller(new \RuntimeException('container broken'))->execute();

        $this->assertStringContainsString('container broken', $this->messagesOfType('error')[0]);
    }
}
