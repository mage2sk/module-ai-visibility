<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Health;

use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Service\Health\CronCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CronCheckTest extends TestCase
{
    use DbStubTrait;

    public static function ageProvider(): array
    {
        return [
            'ten minutes ago' => ['2026-05-01 11:50:00', HealthResult::STATUS_OK],
            'exactly two hours' => ['2026-05-01 10:00:00', HealthResult::STATUS_OK],
            'three hours ago' => ['2026-05-01 09:00:00', HealthResult::STATUS_ERROR],
            'never' => [null, HealthResult::STATUS_ERROR],
            'zero date' => ['0000-00-00 00:00:00', HealthResult::STATUS_ERROR],
        ];
    }

    /**
     * @dataProvider ageProvider
     */
    #[DataProvider('ageProvider')]
    public function testStatusDependsOnLastSuccess(?string $lastSuccess, string $expected): void
    {
        $check = new CronCheck(
            $this->resource(['fetchOne' => $lastSuccess ?? false, 'fetchRow' => false]),
            $this->clock('2026-05-01 12:00:00')
        );

        $result = $check->run();

        $this->assertSame($expected, $result->status);
        if ($expected === HealthResult::STATUS_ERROR) {
            $this->assertStringContainsString('Fix:', $result->message);
            $this->assertStringContainsString('cron', $result->message);
        }
        $this->assertContains(['job_code = ?', 'panth_aiv_rollup'], $this->wheres);
        $this->assertContains('pfx_cron_schedule', $this->froms);
    }

    public function testLastErrorIsAddedToDetails(): void
    {
        $check = new CronCheck(
            $this->resource(['fetchOne' => false, 'fetchRow' => ['messages' => 'SQLSTATE fail', 'executed_at' => 'x']]),
            $this->clock('2026-05-01 12:00:00')
        );

        $result = $check->run();

        $this->assertSame(HealthResult::STATUS_ERROR, $result->status);
        $this->assertSame('SQLSTATE fail', $result->details['last_error']);
    }

    public function testDatabaseFailureIsUnknown(): void
    {
        $check = new CronCheck(
            $this->resource(['fetchOne' => new \RuntimeException('no table')]),
            $this->clock('2026-05-01 12:00:00')
        );

        $result = $check->run();

        $this->assertSame(HealthResult::STATUS_UNKNOWN, $result->status);
        $this->assertSame('cron', $check->code());
    }
}
