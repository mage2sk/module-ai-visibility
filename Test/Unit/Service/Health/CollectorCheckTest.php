<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Health;

use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Service\Health\CollectorCheck;
use PHPUnit\Framework\TestCase;

class CollectorCheckTest extends TestCase
{
    use DbStubTrait;

    private function config(bool $trackVisits): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('trackVisits')->willReturn($trackVisits);
        return $config;
    }

    public function testRecentVisitIsOk(): void
    {
        $check = new CollectorCheck(
            $this->resource(['fetchOne' => '1']),
            $this->clock('2026-05-08 00:00:00'),
            $this->config(true)
        );

        $result = $check->run();

        $this->assertSame(HealthResult::STATUS_OK, $result->status);
        $this->assertContains(['created_at >= ?', '2026-05-01 00:00:00'], $this->wheres);
        $this->assertContains(['is_simulated = ?', 0], $this->wheres);
        $this->assertSame(['pfx_panth_aiv_visit'], $this->froms);
    }

    public function testLandingOnlyIsOk(): void
    {
        $calls = 0;
        $check = new CollectorCheck(
            $this->resource(['fetchOne' => function () use (&$calls) {
                return ++$calls === 1 ? false : '1';
            }]),
            $this->clock('2026-05-08 00:00:00'),
            $this->config(true)
        );

        $result = $check->run();

        $this->assertSame(HealthResult::STATUS_OK, $result->status);
        $this->assertSame(['pfx_panth_aiv_visit', 'pfx_panth_aiv_landing'], $this->froms);
        $this->assertTrue($result->details['landings']);
    }

    public function testNothingRecordedIsUnknownWithHint(): void
    {
        $check = new CollectorCheck(
            $this->resource(['fetchOne' => false]),
            $this->clock('2026-05-08 00:00:00'),
            $this->config(true)
        );

        $result = $check->run();

        $this->assertSame(HealthResult::STATUS_UNKNOWN, $result->status);
        $this->assertStringContainsString('utm_source=chatgpt.com', $result->message);
        $this->assertLessThanOrEqual(512, mb_strlen($result->message));
    }

    public function testVisitTrackingOffIsUnknownWithoutQuery(): void
    {
        $check = new CollectorCheck(
            $this->resource(['fetchOne' => new \RuntimeException('must not query')]),
            $this->clock('2026-05-08 00:00:00'),
            $this->config(false)
        );

        $result = $check->run();

        $this->assertSame(HealthResult::STATUS_UNKNOWN, $result->status);
        $this->assertStringContainsString('switched off', $result->message);
    }

    public function testDatabaseFailureIsUnknown(): void
    {
        $check = new CollectorCheck(
            $this->resource(['fetchOne' => new \RuntimeException('lost connection')]),
            $this->clock('2026-05-08 00:00:00'),
            $this->config(true)
        );

        $result = $check->run();

        $this->assertSame(HealthResult::STATUS_UNKNOWN, $result->status);
        $this->assertStringContainsString('lost connection', $result->message);
    }
}
