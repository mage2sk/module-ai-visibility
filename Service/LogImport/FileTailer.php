<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\LogImport;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Phrase;

/**
 * Streams complete lines from a growing log file, starting at a byte offset.
 *
 * The file is read in fixed-size chunks, so memory stays bounded by the chunk size plus the
 * longest accepted line. Only lines that end with a line break are returned: a partial last line
 * (the web server is still writing it) is left for the next run. Lines longer than the limit are
 * returned as null (counted as malformed by the caller) without being held in memory.
 */
class FileTailer
{
    public const CHUNK_SIZE = 65536;

    /**
     * Longest line that is parsed; longer lines are skipped
     */
    public const MAX_LINE_BYTES = 16384;

    /**
     * @param File $driver
     */
    public function __construct(
        private readonly File $driver
    ) {
    }

    /**
     * Inode and size of a readable regular file
     *
     * @param string $path
     * @return array{inode: int, size: int}
     * @throws FileSystemException When the file is missing, not a regular file or not readable
     */
    public function stat(string $path): array
    {
        if (!$this->driver->isExists($path)) {
            throw new FileSystemException(new Phrase('Log file "%1" does not exist.', [$path]));
        }
        if (!$this->driver->isFile($path)) {
            throw new FileSystemException(new Phrase('Log path "%1" is not a regular file.', [$path]));
        }
        if (!$this->driver->isReadable($path)) {
            throw new FileSystemException(
                new Phrase('Log file "%1" is not readable by the PHP user.', [$path])
            );
        }
        $stat = $this->driver->stat($path);
        return ['inode' => (int)($stat['ino'] ?? 0), 'size' => (int)($stat['size'] ?? 0)];
    }

    /**
     * Bytes compared to recognise a file that was truncated and written again past the saved offset
     */
    public const HEAD_BYTES = 256;

    /**
     * First bytes of the file ('' when it cannot be read)
     *
     * @param string $path
     * @param int $length
     * @return string
     */
    public function head(string $path, int $length = self::HEAD_BYTES): string
    {
        try {
            $handle = $this->driver->fileOpen($path, 'rb');
            try {
                return (string)$this->driver->fileRead($handle, max(1, $length));
            } finally {
                $this->driver->fileClose($handle);
            }
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Canonical path (symlinks resolved), or the path itself when it cannot be resolved
     *
     * @param string $path
     * @return string
     */
    public function realPath(string $path): string
    {
        try {
            $real = $this->driver->getRealPath($path);
        } catch (\Throwable $e) {
            return $path;
        }
        return is_string($real) && $real !== '' ? $real : $path;
    }

    /**
     * Yield complete lines from $offset on
     *
     * Each item is ['line' => string|null, 'end' => int]: the line without its line break (null when
     * it was longer than $maxLineBytes) and the byte offset just after its line break, which is the
     * safe resume position once the line has been handled. Stop iterating at any time.
     *
     * @param string $path
     * @param int $offset
     * @param int $maxLineBytes
     * @return \Generator<int, array{line: string|null, end: int}>
     * @throws FileSystemException
     */
    public function lines(string $path, int $offset, int $maxLineBytes = self::MAX_LINE_BYTES): \Generator
    {
        $handle = $this->driver->fileOpen($path, 'rb');
        try {
            if ($offset > 0) {
                $this->driver->fileSeek($handle, $offset);
            }
            $buffer = '';
            $bufferStart = $offset;
            $oversized = false;
            while (true) {
                $chunk = $this->driver->fileRead($handle, self::CHUNK_SIZE);
                if ($chunk === '') {
                    break;
                }
                $buffer .= $chunk;
                $start = 0;
                while (($newline = strpos($buffer, "\n", $start)) !== false) {
                    $length = $newline - $start;
                    $end = $bufferStart + $newline + 1;
                    if ($oversized || $length > $maxLineBytes) {
                        $oversized = false;
                        yield ['line' => null, 'end' => $end];
                    } else {
                        yield ['line' => rtrim(substr($buffer, $start, $length), "\r"), 'end' => $end];
                    }
                    $start = $newline + 1;
                }
                $buffer = (string)substr($buffer, $start);
                $bufferStart += $start;
                if (strlen($buffer) > $maxLineBytes) {
                    // Drop the head of an oversized line; its end is reported when the line break arrives.
                    $oversized = true;
                    $bufferStart += strlen($buffer);
                    $buffer = '';
                }
            }
        } finally {
            $this->driver->fileClose($handle);
        }
    }
}
