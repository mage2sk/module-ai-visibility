<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Health;

use Magento\Framework\Filesystem\Driver\File;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Service\Health\LogImportCheck;
use PHPUnit\Framework\TestCase;

class LogImportCheckTest extends TestCase
{
    use DbStubTrait;

    private const NOW = '2026-05-01 12:00:00';

    /**
     * @param bool $enabled
     * @param array $files
     * @param array|\Throwable $states
     * @param array $readable path => bool
     * @return LogImportCheck
     */
    private function check(bool $enabled, array $files, $states = [], array $readable = []): LogImportCheck
    {
        $config = $this->createStub(Config::class);
        $config->method('logImportEnabled')->willReturn($enabled);
        $config->method('logImportFiles')->willReturn($files);

        $driver = $this->createStub(File::class);
        $driver->method('isExists')->willReturnCallback(fn ($path) => $readable[$path] ?? false);
        $driver->method('isReadable')->willReturnCallback(fn ($path) => $readable[$path] ?? false);
        $driver->method('isFile')->willReturnCallback(fn ($path) => $readable[$path] ?? false);

        return new LogImportCheck($config, $this->resource(['fetchPairs' => $states]), $driver, $this->clock(self::NOW));
    }

    public function testDisabledIsOk(): void
    {
        $result = $this->check(false, [])->run();
        $this->assertSame(HealthResult::STATUS_OK, $result->status);
        $this->assertStringContainsString('off', $result->message);
    }

    public function testEnabledWithoutFilesIsError(): void
    {
        $result = $this->check(true, [])->run();
        $this->assertSame(HealthResult::STATUS_ERROR, $result->status);
        $this->assertStringContainsString('Fix:', $result->message);
    }

    public function testReadableAndRecentlyImportedIsOk(): void
    {
        $path = '/var/log/web/access.log';
        $result = $this->check(
            true,
            [['path' => $path, 'store_code' => 'default']],
            [sha1($path) => '2026-05-01 11:55:00'],
            [$path => true]
        )->run();

        $this->assertSame(HealthResult::STATUS_OK, $result->status);
        $this->assertContains(['file_key IN (?)', [sha1($path)]], $this->wheres);
        $this->assertTrue($result->details['files'][0]['readable']);
    }

    public function testUnreadableFileIsError(): void
    {
        $result = $this->check(
            true,
            [['path' => '/missing/access.log', 'store_code' => 'default']],
            [],
            []
        )->run();

        $this->assertSame(HealthResult::STATUS_ERROR, $result->status);
        $this->assertStringContainsString('/missing/access.log', $result->message);
        $this->assertStringContainsString('Fix:', $result->message);
    }

    public function testStaleOrNeverImportedIsError(): void
    {
        $a = '/logs/a.log';
        $b = '/logs/b.log';
        $result = $this->check(
            true,
            [['path' => $a, 'store_code' => 'default'], ['path' => $b, 'store_code' => 'second']],
            [sha1($a) => '2026-05-01 10:00:00'],
            [$a => true, $b => true]
        )->run();

        $this->assertSame(HealthResult::STATUS_ERROR, $result->status);
        $this->assertStringContainsString('/logs/a.log (last read 2 h 0 min ago)', $result->message);
        $this->assertStringContainsString('/logs/b.log (never read)', $result->message);
        $this->assertStringContainsString('panth:aivisibility:import-logs', $result->message);
    }

    public function testDriverExceptionCountsAsUnreadable(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('logImportEnabled')->willReturn(true);
        $config->method('logImportFiles')->willReturn([['path' => '/x.log', 'store_code' => 'default']]);
        $driver = $this->createStub(File::class);
        $driver->method('isExists')->willThrowException(new \RuntimeException('open_basedir'));

        $check = new LogImportCheck($config, $this->resource([]), $driver, $this->clock(self::NOW));

        $this->assertSame(HealthResult::STATUS_ERROR, $check->run()->status);
    }

    public function testDatabaseFailureIsUnknown(): void
    {
        $result = $this->check(
            true,
            [['path' => '/x.log', 'store_code' => 'default']],
            new \RuntimeException('no state table'),
            ['/x.log' => true]
        )->run();

        $this->assertSame(HealthResult::STATUS_UNKNOWN, $result->status);
    }
}
