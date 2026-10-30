<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Health;

use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Service\Health\TableSizeCheck;
use PHPUnit\Framework\TestCase;

class TableSizeCheckTest extends TestCase
{
    use DbStubTrait;

    public function testSmallTablesAreOk(): void
    {
        $check = new TableSizeCheck($this->resource(['fetchPairs' => [
            'pfx_panth_aiv_hit' => '1048576',
            'pfx_panth_aiv_visit' => '524288',
        ]]));

        $result = $check->run();

        $this->assertSame(HealthResult::STATUS_OK, $result->status);
        $this->assertStringContainsString('1.5 MB', $result->message);
        $this->assertSame(1572864, $result->details['total_bytes']);
        $this->assertContains(['t.TABLE_NAME LIKE ?', 'pfx\\_panth\\_aiv\\_%'], $this->wheres);
    }

    public function testOverOneGigabyteWarnsWithFix(): void
    {
        $check = new TableSizeCheck($this->resource(['fetchPairs' => [
            'pfx_panth_aiv_hit' => (string)(1024 * 1048576),
            'pfx_panth_aiv_crawl_daily' => (string)(200 * 1048576),
        ]]));

        $result = $check->run();

        $this->assertSame(HealthResult::STATUS_WARN, $result->status);
        $this->assertStringContainsString('1.2 GB', $result->message);
        $this->assertStringContainsString('pfx_panth_aiv_hit', $result->message);
        $this->assertStringContainsString('Fix:', $result->message);
    }

    public function testNoTablesIsOk(): void
    {
        $result = (new TableSizeCheck($this->resource(['fetchPairs' => []])))->run();
        $this->assertSame(HealthResult::STATUS_OK, $result->status);
        $this->assertSame(0, $result->details['total_bytes']);
    }

    public function testQueryFailureIsUnknown(): void
    {
        $check = new TableSizeCheck($this->resource(['fetchPairs' => new \RuntimeException('access denied')]));
        $this->assertSame(HealthResult::STATUS_UNKNOWN, $check->run()->status);
        $this->assertSame('table_size', $check->code());
    }
}
