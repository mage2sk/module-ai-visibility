<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\LogImport;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Api\HitWriterInterface;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Api\RollupInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\TextFilter;
use Panth\AiVisibility\Observer\TrackRequest;
use Psr\Log\LoggerInterface;

/**
 * Imports AI crawler hits from web server access logs (stores behind Varnish or a CDN, where
 * cached pages never reach PHP).
 *
 * Each configured file is read from the byte offset saved in panth_aiv_import_state. A changed
 * inode (rotation), a file smaller than the offset (truncation) or a file whose first bytes changed
 * (truncated and written again past the offset) restarts at byte 0. Only complete lines are consumed,
 * at most log_import/max_lines_per_run lines per run over all files. A lock keeps two runs from
 * overlapping. A dry run reads and counts but writes nothing, not even the offsets.
 *
 * Writes are grouped in transactions of BATCH_LINES lines. A line whose write fails stops the run at
 * that line (the lines before it are kept); after MAX_LINE_ATTEMPTS failed runs on the same line it is
 * skipped, so one bad line can never block the import for good. The last error is saved per file for
 * the health check. Every day that received hits is rolled up at the end of the run.
 */
class LogImporter
{
    public const LOCK_NAME = 'panth_aiv_import_logs';
    public const STATE_TABLE = 'panth_aiv_import_state';

    public const STATUS_OK = 'ok';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_LOCKED = 'locked';
    public const STATUS_NO_FILES = 'no_files';
    public const STATUS_UNKNOWN_FILE = 'unknown_file';

    public const RESET_ROTATED = 'rotated';
    public const RESET_TRUNCATED = 'truncated';
    public const RESET_FROM_START = 'from_start';

    /**
     * Save the offset every this many lines, so a crash re-imports little
     */
    public const CHECKPOINT_LINES = 5000;

    /**
     * Lines written in one transaction
     */
    public const BATCH_LINES = 500;

    /**
     * Failed runs on the same line before that line is skipped
     */
    public const MAX_LINE_ATTEMPTS = 3;

    /**
     * Longest stored error text
     */
    private const MAX_ERROR = 512;

    /**
     * Lines dated further than this into the future are treated as malformed
     */
    private const MAX_CLOCK_SKEW = 86400;

    /**
     * gzip magic bytes
     */
    private const GZIP_MAGIC = "\x1f\x8b";

    /**
     * UTC days that received hits: store_id => [day => day]
     *
     * @var array<int, array<string, string>>
     */
    private array $touchedDays = [];

    /**
     * @param Config $config
     * @param BotClassifierInterface $botClassifier
     * @param HitWriterInterface $hitWriter
     * @param PageTypeResolverInterface $pageTypeResolver
     * @param CombinedLogParser $parser
     * @param FileTailer $tailer
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     * @param RollupInterface|null $rollup Rolls up the days that received hits (set in di.xml)
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        private readonly Config $config,
        private readonly BotClassifierInterface $botClassifier,
        private readonly HitWriterInterface $hitWriter,
        private readonly PageTypeResolverInterface $pageTypeResolver,
        private readonly CombinedLogParser $parser,
        private readonly FileTailer $tailer,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger,
        private readonly ?RollupInterface $rollup = null
    ) {
    }

    /**
     * Import new lines from every configured file (or only $onlyFile)
     *
     * Returns ['status' => ok|disabled|locked|no_files|unknown_file, 'dry_run' => bool,
     * 'max_lines' => int, 'files' => list of per-file results, 'rolled_up' => days rolled up]. A per-file
     * result holds path, store_code, store_id, lines, hits, candidates, skipped, malformed, failed (lines
     * skipped after repeated write errors), start, end, reset ('' | rotated | truncated | from_start),
     * more (lines left for the next run) and error ('' when fine).
     *
     * @param string|null $onlyFile Absolute path of one configured file
     * @param bool $dryRun Parse and count only
     * @param bool $fromStart Ignore the saved offsets and read from byte 0
     * @param bool $force Run even when log import is disabled in the configuration
     * @return array{status: string, dry_run: bool, max_lines: int, files: array<int, array<string, mixed>>}
     */
    public function run(
        ?string $onlyFile = null,
        bool $dryRun = false,
        bool $fromStart = false,
        bool $force = false
    ): array {
        $maxLines = $this->config->logImportMaxLines();
        $result = [
            'status' => self::STATUS_OK, 'dry_run' => $dryRun, 'max_lines' => $maxLines, 'files' => [],
            'rolled_up' => 0,
        ];
        if (!$force && !$this->config->logImportEnabled()) {
            $result['status'] = self::STATUS_DISABLED;
            return $result;
        }
        $files = $this->uniqueFiles($this->config->logImportFiles());
        if ($onlyFile !== null && $onlyFile !== '') {
            $files = array_values(array_filter($files, static fn (array $f): bool => $f['path'] === $onlyFile));
            if ($files === []) {
                $result['status'] = self::STATUS_UNKNOWN_FILE;
                return $result;
            }
        }
        if ($files === []) {
            $result['status'] = self::STATUS_NO_FILES;
            return $result;
        }
        // A dry run writes nothing, so it may run next to a real import.
        if (!$dryRun && !$this->lockManager->lock(self::LOCK_NAME, 0)) {
            $result['status'] = self::STATUS_LOCKED;
            return $result;
        }
        $this->touchedDays = [];
        try {
            $budget = $maxLines;
            foreach ($files as $file) {
                $fileResult = $this->importFile($file['path'], $file['store_code'], $budget, $dryRun, $fromStart);
                $budget -= $fileResult['lines'];
                $result['files'][] = $fileResult;
            }
            if (!$dryRun) {
                $result['rolled_up'] = $this->rollupTouchedDays();
            }
        } finally {
            if (!$dryRun) {
                $this->lockManager->unlock(self::LOCK_NAME);
            }
        }
        return $result;
    }

    /**
     * Configured files without duplicates of the same real file (a symlink and its target count once)
     *
     * @param array<int, array{path: string, store_code: string}> $files
     * @return array<int, array{path: string, store_code: string}>
     */
    private function uniqueFiles(array $files): array
    {
        $seen = [];
        $result = [];
        foreach ($files as $file) {
            $real = $this->tailer->realPath($file['path']);
            if (isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;
            $result[] = $file;
        }
        return $result;
    }

    /**
     * Import one file within the remaining line budget
     *
     * @param string $path
     * @param string $storeCode
     * @param int $budget
     * @param bool $dryRun
     * @param bool $fromStart
     * @return array<string, mixed>
     */
    private function importFile(string $path, string $storeCode, int $budget, bool $dryRun, bool $fromStart): array
    {
        $stats = [
            'path' => $path, 'store_code' => $storeCode, 'store_id' => null,
            'lines' => 0, 'hits' => 0, 'candidates' => 0, 'skipped' => 0, 'malformed' => 0, 'failed' => 0,
            'start' => 0, 'end' => 0, 'reset' => '', 'more' => false, 'error' => '',
        ];
        try {
            $store = $this->storeManager->getStore($storeCode);
            $storeId = (int)$store->getId();
            $stats['store_id'] = $storeId;
        } catch (\Throwable $e) {
            $stats['error'] = sprintf(
                'Unknown store code "%s" for this log file. Fix the store code in the Access Log Import setting.',
                $storeCode
            );
            $this->saveError($path, $stats['error'], $dryRun);
            return $stats;
        }
        try {
            $file = $this->tailer->stat($path);
        } catch (\Throwable $e) {
            $stats['error'] = $e->getMessage();
            $this->saveError($path, $stats['error'], $dryRun);
            return $stats;
        }
        $head = $file['size'] > 0 ? $this->tailer->head($path) : '';
        if (str_starts_with($head, self::GZIP_MAGIC)) {
            $stats['error'] = sprintf(
                'Log file "%s" is gzip-compressed. Compressed files are not supported: configure the current, '
                . 'uncompressed log file.',
                $path
            );
            $this->saveError($path, $stats['error'], $dryRun);
            return $stats;
        }
        $headHash = strlen($head) >= FileTailer::HEAD_BYTES ? sha1($head) : null;

        $state = $this->loadState($path);
        $offset = 0;
        if ($fromStart) {
            $stats['reset'] = $state !== null ? self::RESET_FROM_START : '';
        } elseif ($state !== null) {
            if ($state['inode'] !== $file['inode']) {
                $stats['reset'] = self::RESET_ROTATED;
            } elseif ($state['offset'] > $file['size']) {
                $stats['reset'] = self::RESET_TRUNCATED;
            } elseif ($state['head_hash'] !== null && $headHash !== null && $state['head_hash'] !== $headHash) {
                $stats['reset'] = self::RESET_TRUNCATED;
            } else {
                $offset = $state['offset'];
            }
        }
        $stats['start'] = $offset;
        $stats['end'] = $offset;
        // Failed attempts on the line at the saved offset (only when the run resumes there).
        $failCount = $state !== null && $offset === $state['offset'] ? $state['fail_count'] : 0;
        $failOffset = $offset;

        $context = [
            'store' => $storeId,
            'active' => $this->config->isEnabled($storeId) && $this->config->trackCrawlers(),
            'discovery' => $this->config->discoveryEnabled(),
            'dry' => $dryRun,
            'now' => time(),
            'path' => $path,
            'inode' => $file['inode'],
            'head' => $headHash,
        ];
        try {
            if ($budget <= 0) {
                $stats['more'] = $offset < $file['size'];
            } else {
                $batch = [];
                $lineStart = $offset;
                $stopped = false;
                foreach ($this->tailer->lines($path, $offset) as $item) {
                    if ($stats['lines'] >= $budget) {
                        $stats['more'] = true;
                        break;
                    }
                    $stats['lines']++;
                    $batch[] = ['line' => $item['line'], 'start' => $lineStart, 'end' => $item['end']];
                    $lineStart = $item['end'];
                    if (count($batch) >= self::BATCH_LINES) {
                        $stopped = !$this->processBatch($batch, $context, $stats, $failCount, $failOffset);
                        $batch = [];
                        if ($stopped) {
                            break;
                        }
                    }
                }
                if ($batch !== [] && !$stopped) {
                    $this->processBatch($batch, $context, $stats, $failCount, $failOffset);
                }
            }
        } catch (\Throwable $e) {
            // Reading the file failed; keep the position of the last line that was fully handled.
            $stats['error'] = trim($stats['error'] . ' Import stopped: ' . $e->getMessage());
            $this->logger->warning('[Panth_AiVisibility] log import of ' . $path . ' failed: ' . $e->getMessage());
        }
        if (!$dryRun) {
            try {
                $stopped = $stats['error'] !== '' && $failOffset === $stats['end'];
                $this->saveState($path, $file['inode'], $stats['end'], $headHash, $stopped ? $failCount : 0, [
                    'error' => $stats['error'],
                    'previous' => $state,
                ]);
            } catch (\Throwable $e) {
                $stats['error'] = trim($stats['error'] . ' Could not save the import position: ' . $e->getMessage());
            }
        }
        return $stats;
    }

    /**
     * Handle a batch of lines in one transaction; false when the run has to stop
     *
     * Each line that writes gets a savepoint, so a failing line is undone alone and the lines before it
     * are committed. A line that failed MAX_LINE_ATTEMPTS times (over several runs) is skipped.
     *
     * @param array<int, array{line: ?string, start: int, end: int}> $batch
     * @param array<string, mixed> $context
     * @param array<string, mixed> $stats
     * @param int $failCount Failed attempts on the line at $failOffset, updated in place
     * @param int $failOffset Start of the line $failCount belongs to, updated in place
     * @return bool
     */
    private function processBatch(array $batch, array $context, array &$stats, int &$failCount, int &$failOffset): bool
    {
        $dryRun = (bool)$context['dry'];
        $connection = $dryRun ? null : $this->resource->getConnection();
        // Counters as they were before the batch, restored when the whole transaction is lost.
        $before = array_intersect_key($stats, array_flip(['hits', 'candidates', 'skipped', 'malformed', 'failed']));
        $touchedBefore = $this->touchedDays;
        $connection?->beginTransaction();
        $continue = true;
        try {
            foreach ($batch as $item) {
                $connection?->query('SAVEPOINT panth_aiv_line');
                try {
                    $this->handleLine($item['line'], $context, $stats);
                    // A handled line ends any run of failures; the next failing line starts at attempt 1.
                    $failCount = 0;
                } catch (\Throwable $e) {
                    $connection?->query('ROLLBACK TO SAVEPOINT panth_aiv_line');
                    $attempts = ($failOffset === $item['start'] ? $failCount : 0) + 1;
                    $message = $this->cleanError($e->getMessage());
                    if ($attempts < self::MAX_LINE_ATTEMPTS) {
                        $failCount = $attempts;
                        $failOffset = $item['start'];
                        $stats['error'] = 'Import stopped at byte ' . $item['start'] . ': ' . $message
                            . ' (attempt ' . $attempts . ' of ' . self::MAX_LINE_ATTEMPTS . ' for this line)';
                        $this->logger->warning('[Panth_AiVisibility] log import of ' . $context['path']
                            . ' stopped: ' . $message);
                        $continue = false;
                        break;
                    }
                    $stats['failed']++;
                    $stats['error'] = 'Skipped the line at byte ' . $item['start'] . ' after '
                        . self::MAX_LINE_ATTEMPTS . ' failed attempts: ' . $message;
                    $this->logger->warning('[Panth_AiVisibility] log import of ' . $context['path']
                        . ' skipped a line: ' . $message);
                    $failCount = 0;
                }
                $stats['end'] = $item['end'];
                $failOffset = $item['end'];
            }
            $connection?->commit();
        } catch (\Throwable $e) {
            // The whole transaction is gone (for example a deadlock); nothing of this batch was saved.
            $connection?->rollBack();
            $stats = array_replace($stats, $before);
            $this->touchedDays = $touchedBefore;
            $stats['end'] = $batch[0]['start'];
            $stats['error'] = 'Import stopped: ' . $this->cleanError($e->getMessage());
            $this->logger->warning('[Panth_AiVisibility] log import of ' . $context['path'] . ' failed: '
                . $e->getMessage());
            return false;
        }
        if (!$dryRun && $continue && intdiv($stats['lines'], self::CHECKPOINT_LINES)
            !== intdiv($stats['lines'] - count($batch), self::CHECKPOINT_LINES)
        ) {
            $this->saveState($context['path'], (int)$context['inode'], $stats['end'], $context['head'], 0, []);
        }
        return $continue;
    }

    /**
     * Classify one line and update the counters
     *
     * @param string|null $line Null for a line that was too long
     * @param array<string, mixed> $context
     * @param array $stats Counters of the current file, updated in place
     * @return void
     */
    private function handleLine(?string $line, array $context, array &$stats): void
    {
        $entry = $line === null ? null : $this->parser->parse($line);
        if ($entry === null || $entry['timestamp'] > $context['now'] + self::MAX_CLOCK_SKEW) {
            $stats['malformed']++;
            return;
        }
        if (!$context['active'] || $this->isSkippedPath($entry['path'])) {
            $stats['skipped']++;
            return;
        }
        $userAgent = TextFilter::userAgent($entry['user_agent']);
        if ($userAgent === '') {
            return;
        }
        $storeId = (int)$context['store'];
        $bot = $this->botClassifier->classify($userAgent);
        if ($bot !== null) {
            if (!$context['dry']) {
                $this->hitWriter->recordHit(
                    $storeId,
                    $bot,
                    $entry['path'],
                    $entry['has_query'],
                    $entry['status'],
                    0,
                    HitWriterInterface::ORIGIN_LOG,
                    $entry['timestamp']
                );
                $day = gmdate('Y-m-d', $entry['timestamp']);
                $this->touchedDays[$storeId][$day] = $day;
            }
            $stats['hits']++;
            return;
        }
        if (!$context['discovery']) {
            return;
        }
        if ($this->botClassifier->isDiscoveryCandidate($userAgent)
            || TrackRequest::isLlmsCandidate($userAgent, $this->pageTypeResolver->resolve($entry['path']))
        ) {
            if (!$context['dry']) {
                $this->hitWriter->recordCandidateHit(
                    $storeId,
                    $userAgent,
                    $entry['path'],
                    $entry['has_query'],
                    $entry['status'],
                    0,
                    HitWriterInterface::ORIGIN_LOG,
                    $entry['timestamp']
                );
            }
            $stats['candidates']++;
        }
    }

    /**
     * Static files, media, web API requests and the visit collector are not pages; sitemaps are kept
     * (same rule as live requests)
     *
     * @param string $path
     * @return bool
     */
    public function isSkippedPath(string $path): bool
    {
        return TrackRequest::isSkippedPath($path);
    }

    /**
     * Roll up every day that received hits in this run; returns the number of days rolled up
     *
     * @return int
     */
    private function rollupTouchedDays(): int
    {
        if ($this->rollup === null) {
            return 0;
        }
        $rolled = 0;
        foreach ($this->touchedDays as $storeId => $days) {
            ksort($days);
            foreach ($days as $day) {
                try {
                    $this->rollup->rollupDay($day, (int)$storeId);
                    $rolled++;
                } catch (\Throwable $e) {
                    $this->logger->warning(sprintf(
                        '[Panth_AiVisibility] rollup of %s after the log import failed: %s',
                        $day,
                        $e->getMessage()
                    ));
                }
            }
        }
        $this->touchedDays = [];
        return $rolled;
    }

    /**
     * Saved position of a file, or null when it was never imported
     *
     * @param string $path
     * @return array{inode: int, offset: int, head_hash: ?string, fail_count: int, updated_at: string}|null
     */
    private function loadState(string $path): ?array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName(self::STATE_TABLE),
                ['inode', 'byte_offset', 'head_hash', 'fail_count', 'updated_at']
            )
            ->where('file_key = ?', sha1($path));
        $row = $connection->fetchRow($select);
        if (!is_array($row) || $row === []) {
            return null;
        }
        $head = $row['head_hash'] ?? null;
        return [
            'inode' => (int)($row['inode'] ?? 0),
            'offset' => (int)($row['byte_offset'] ?? 0),
            'head_hash' => is_string($head) && $head !== '' ? $head : null,
            'fail_count' => (int)($row['fail_count'] ?? 0),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    /**
     * Upsert the position of a file, with the error of the run ('' clears it)
     *
     * @param string $path
     * @param int $inode
     * @param int $offset
     * @param string|null $headHash
     * @param int $failCount
     * @param array{error?: string} $run
     * @return void
     */
    private function saveState(
        string $path,
        int $inode,
        int $offset,
        ?string $headHash,
        int $failCount,
        array $run
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $error = $this->cleanError((string)($run['error'] ?? ''));
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::STATE_TABLE),
            [
                'file_key' => sha1($path),
                'path' => $path,
                'inode' => max(0, $inode),
                'byte_offset' => max(0, $offset),
                'updated_at' => $now,
                'head_hash' => $headHash,
                'fail_count' => max(0, $failCount),
                'last_error' => $error !== '' ? $error : null,
                'last_error_at' => $error !== '' ? $now : null,
            ],
            ['path', 'inode', 'byte_offset', 'updated_at', 'head_hash', 'fail_count', 'last_error', 'last_error_at']
        );
    }

    /**
     * Record an error for a file that could not be read at all (its saved position is kept)
     *
     * @param string $path
     * @param string $error
     * @param bool $dryRun
     * @return void
     */
    private function saveError(string $path, string $error, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        try {
            $state = $this->loadState($path);
            $now = gmdate('Y-m-d H:i:s');
            $this->resource->getConnection()->insertOnDuplicate(
                $this->resource->getTableName(self::STATE_TABLE),
                [
                    'file_key' => sha1($path),
                    'path' => $path,
                    'inode' => $state['inode'] ?? 0,
                    'byte_offset' => $state['offset'] ?? 0,
                    'updated_at' => ($state['updated_at'] ?? '') !== '' ? $state['updated_at'] : $now,
                    'head_hash' => $state['head_hash'] ?? null,
                    'fail_count' => $state['fail_count'] ?? 0,
                    'last_error' => $this->cleanError($error),
                    'last_error_at' => $now,
                ],
                ['path', 'last_error', 'last_error_at']
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AiVisibility] log import error could not be saved: ' . $e->getMessage());
        }
    }

    /**
     * Error text as stored: one line, valid UTF-8, at most 512 characters
     *
     * @param string $error
     * @return string
     */
    private function cleanError(string $error): string
    {
        return mb_substr(TextFilter::userAgent($error), 0, self::MAX_ERROR, 'UTF-8');
    }
}
