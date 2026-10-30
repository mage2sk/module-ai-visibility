<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Recommendation;

use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Api\ActionLogInterface;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Panth\AiVisibility\Model\Config;

/**
 * In-memory recommendation repository and action log stubs that record every write.
 */
trait RepositoryFixtureTrait
{
    /** @var array<int, array> */
    private array $recs = [];

    private array $applied = [];

    private array $undone = [];

    private array $statusChanges = [];

    private array $feedbacks = [];

    private ?\Throwable $writeFailure = null;

    private function repository(): RecommendationRepositoryInterface
    {
        $repository = $this->createStub(RecommendationRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(fn (int $id): ?array => $this->recs[$id] ?? null);
        $repository->method('setStatus')->willReturnCallback(function (int $id, string $status): void {
            $this->failIfAsked();
            $this->statusChanges[] = [$id, $status];
        });
        $repository->method('setFeedback')->willReturnCallback(function (int $id, int $value): void {
            $this->failIfAsked();
            $this->feedbacks[] = [$id, $value];
        });
        return $repository;
    }

    private function actionLog(): ActionLogInterface
    {
        $log = $this->createStub(ActionLogInterface::class);
        $log->method('apply')->willReturnCallback(function (int $id, ?string $note, ?string $user): int {
            $this->failIfAsked();
            $this->applied[] = [$id, $note, $user];
            return 100 + count($this->applied);
        });
        $log->method('undo')->willReturnCallback(function (int $id): void {
            $this->failIfAsked();
            if ($id === 404) {
                throw new LocalizedException(__('This action no longer exists.'));
            }
            $this->undone[] = $id;
        });
        return $log;
    }

    private function config(bool $enabled = true): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('verifyWindowDays')->willReturn(14);
        $config->method('recommendationsEnabled')->willReturn($enabled);
        return $config;
    }

    private function openRec(int $id, string $status = 'open', string $title = 'Fix <b>404</b> pages'): void
    {
        $this->recs[$id] = ['rec_id' => $id, 'status' => $status, 'title' => $title, 'rule_code' => 'bot_404'];
    }

    private function failIfAsked(): void
    {
        if ($this->writeFailure !== null) {
            throw $this->writeFailure;
        }
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame([], $this->applied);
        $this->assertSame([], $this->undone);
        $this->assertSame([], $this->statusChanges);
        $this->assertSame([], $this->feedbacks);
    }
}
