<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\LogImport;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Panth\AiVisibility\Service\LogImport\FileTailer;
use PHPUnit\Framework\TestCase;

class FileTailerTest extends TestCase
{
    /**
     * @var FileTailer
     */
    private FileTailer $tailer;

    /**
     * @var string
     */
    private string $path;

    protected function setUp(): void
    {
        $this->tailer = new FileTailer(new File());
        $this->path = (string)tempnam(sys_get_temp_dir(), 'aivtail');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * Collect all yielded items
     *
     * @param int $offset
     * @param int $max
     * @return array<int, array{line: string|null, end: int}>
     */
    private function read(int $offset = 0, int $max = FileTailer::MAX_LINE_BYTES): array
    {
        return iterator_to_array($this->tailer->lines($this->path, $offset, $max), false);
    }

    public function testEmptyFileYieldsNothing(): void
    {
        $this->assertSame([], $this->read());
        $this->assertSame(0, $this->tailer->stat($this->path)['size']);
    }

    public function testCompleteLinesWithEndOffsets(): void
    {
        file_put_contents($this->path, "one\ntwo\r\n\nthree\n");
        $this->assertSame(
            [
                ['line' => 'one', 'end' => 4],
                ['line' => 'two', 'end' => 9],
                ['line' => '', 'end' => 10],
                ['line' => 'three', 'end' => 16],
            ],
            $this->read()
        );
    }

    public function testPartialLastLineIsNotYielded(): void
    {
        file_put_contents($this->path, "one\ntwo\nthr");
        $items = $this->read();
        $this->assertCount(2, $items);
        $this->assertSame(8, $items[1]['end']);

        // The writer finishes the line; the next run resumes at the saved offset.
        file_put_contents($this->path, "ee\nfour", FILE_APPEND);
        $this->assertSame([['line' => 'three', 'end' => 14]], $this->read(8));
    }

    public function testResumeFromOffset(): void
    {
        file_put_contents($this->path, "aaa\nbbb\nccc\n");
        $this->assertSame([['line' => 'ccc', 'end' => 12]], $this->read(8));
        $this->assertSame([], $this->read(12));
    }

    public function testOversizedLineIsYieldedAsNullAndReadingContinues(): void
    {
        file_put_contents($this->path, "short\n" . str_repeat('x', 300) . "\nnext\n");
        $items = $this->read(0, 100);
        $this->assertSame(['line' => 'short', 'end' => 6], $items[0]);
        $this->assertSame(['line' => null, 'end' => 307], $items[1]);
        $this->assertSame(['line' => 'next', 'end' => 312], $items[2]);
    }

    public function testOversizedLineAcrossChunksUsesBoundedMemory(): void
    {
        $long = str_repeat('y', FileTailer::CHUNK_SIZE * 3 + 17);
        file_put_contents($this->path, $long . "\nafter\n");
        $items = $this->read();
        $this->assertCount(2, $items);
        $this->assertNull($items[0]['line']);
        $this->assertSame(strlen($long) + 1, $items[0]['end']);
        $this->assertSame('after', $items[1]['line']);
    }

    public function testOversizedPartialLineAtEndIsNotYielded(): void
    {
        file_put_contents($this->path, "ok\n" . str_repeat('z', 500));
        $this->assertSame([['line' => 'ok', 'end' => 3]], $this->read(0, 100));
    }

    public function testLineSpanningChunkBoundary(): void
    {
        $first = str_repeat('a', FileTailer::CHUNK_SIZE - 3);
        file_put_contents($this->path, $first . "\nbcdefgh\n");
        $items = $this->read(0, FileTailer::CHUNK_SIZE);
        $this->assertSame('bcdefgh', $items[1]['line']);
        $this->assertSame(FileTailer::CHUNK_SIZE + 6, $items[1]['end']);
    }

    public function testStatReturnsInodeAndSize(): void
    {
        file_put_contents($this->path, "12345\n");
        $stat = $this->tailer->stat($this->path);
        $this->assertSame(6, $stat['size']);
        $this->assertSame((int)fileinode($this->path), $stat['inode']);
    }

    public function testStatMissingFileThrowsClearError(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessage('does not exist');
        $this->tailer->stat($this->path . '.missing');
    }

    public function testStatDirectoryThrows(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessage('not a regular file');
        $this->tailer->stat(sys_get_temp_dir());
    }

    public function testStoppingEarlyClosesTheFile(): void
    {
        file_put_contents($this->path, "a\nb\nc\n");
        foreach ($this->tailer->lines($this->path, 0) as $item) {
            $this->assertSame('a', $item['line']);
            break;
        }
        // The generator is released; the file can be removed and read again without errors.
        $this->assertCount(3, $this->read());
    }
}
