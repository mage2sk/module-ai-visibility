<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\BotClassifierInterface;
use Panth\AiVisibility\Api\HitWriterInterface;
use Panth\AiVisibility\Api\PageTypeResolverInterface;
use Panth\AiVisibility\Model\BotMatch;
use Panth\AiVisibility\Model\Catalogue\BrowserUserAgents;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\LogImport\CombinedLogParser;
use Panth\AiVisibility\Service\LogImport\FileTailer;
use Panth\AiVisibility\Service\LogImport\LogImporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Independent re-verification of the fixes (log import retry / transaction logic, generic tokens).
 */
class ReverifyTest extends TestCase
{
    private const GPT_UA = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://example.invalid/bot)';

    /** @var array<string, array<string, mixed>> */
    private array $state = [];

    /** @var string[] */
    private array $written = [];

    /** @var array<int, array{path: string, store_code: string}> */
    private array $files = [];

    private ?\Closure $hook = null;

    private bool $failSavepointRollback = false;

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file['path'])) {
                unlink($file['path']);
            }
        }
    }

    private function importer(): LogImporter
    {
        $config = $this->createStub(Config::class);
        $config->method('logImportEnabled')->willReturn(true);
        $config->method('logImportMaxLines')->willReturn(1000);
        $config->method('isEnabled')->willReturn(true);
        $config->method('trackCrawlers')->willReturn(true);
        $config->method('discoveryEnabled')->willReturn(false);
        $config->method('logImportFiles')->willReturnCallback(fn (): array => $this->files);

        $writer = $this->createStub(HitWriterInterface::class);
        $writer->method('recordHit')->willReturnCallback(function (...$args): void {
            if ($this->hook !== null) {
                ($this->hook)($args[2]);
            }
            $this->written[] = $args[2];
        });
        $classifier = $this->createStub(BotClassifierInterface::class);
        $classifier->method('classify')->willReturn(new BotMatch('gptbot', 'GPTBot', 'OpenAI', 'training'));
        $resolver = $this->createStub(PageTypeResolverInterface::class);
        $resolver->method('resolve')->willReturn(PageTypeResolverInterface::TYPE_PAGE);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $lock = $this->createStub(LockManagerInterface::class);
        $lock->method('lock')->willReturn(true);

        return new LogImporter(
            $config,
            $classifier,
            $writer,
            $resolver,
            new CombinedLogParser(),
            new FileTailer(new File()),
            $this->resource(),
            $storeManager,
            $lock,
            $this->createStub(LoggerInterface::class)
        );
    }

    private function resource(): ResourceConnection
    {
        $key = '';
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select, &$key) {
            $key = is_array($value) ? '' : (string)$value;
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturnCallback(function () use (&$key) {
            return $this->state[$key] ?? false;
        });
        $connection->method('insertOnDuplicate')->willReturnCallback(function ($table, array $row): int {
            $this->state[$row['file_key']] = $row;
            return 1;
        });
        $connection->method('query')->willReturnCallback(function ($sql) {
            if ($this->failSavepointRollback && str_starts_with((string)$sql, 'ROLLBACK TO SAVEPOINT')) {
                throw new \RuntimeException('SAVEPOINT panth_aiv_line does not exist');
            }
            return null;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function logFile(string $content): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'aivrv');
        file_put_contents($path, $content);
        $this->files[] = ['path' => $path, 'store_code' => 'default'];
    }

    private static function line(string $uri): string
    {
        return '203.0.113.9 - - [29/Oct/2026:10:00:00 +0000] "GET ' . $uri . ' HTTP/1.1" 200 512 "-" "'
            . self::GPT_UA . "\"\n";
    }

    /**
     * A transient failure on one line must not count as a failed attempt of the next line.
     */
    public function testFailedAttemptsAreNotCarriedOverToTheNextLine(): void
    {
        $flaky = 1;
        $this->hook = static function (string $path) use (&$flaky): void {
            if ($path === '/flaky' && $flaky > 0) {
                $flaky--;
                throw new \RuntimeException('Lock wait timeout exceeded');
            }
            if ($path === '/bad') {
                throw new \RuntimeException('Incorrect string value');
            }
        };
        $this->logFile(self::line('/a') . self::line('/flaky') . self::line('/bad') . self::line('/after'));

        $run1 = $this->importer()->run()['files'][0]['error'];
        $run2 = $this->importer()->run()['files'][0]['error'];
        $run3 = $this->importer()->run()['files'][0]['error'];

        $this->assertStringContainsString('attempt 1 of 3', $run1);
        $this->assertStringContainsString('attempt 1 of 3', $run2, 'First failure of /bad was counted as: ' . $run2);
        $this->assertNotContains('/after', $this->written, '/bad was skipped after only 2 failed runs: ' . $run3);
    }

    /**
     * When the whole transaction is lost (deadlock) the saved offset stays at the start of the batch.
     */
    public function testLostTransactionKeepsTheOffsetAtTheStartOfTheBatch(): void
    {
        $this->failSavepointRollback = true;
        $this->hook = static function (string $path): void {
            if ($path === '/deadlock') {
                throw new \RuntimeException('Deadlock found when trying to get lock');
            }
        };
        $this->logFile(self::line('/a') . self::line('/b') . self::line('/deadlock') . self::line('/c'));

        $result = $this->importer()->run()['files'][0];
        $saved = reset($this->state);

        $this->assertSame(0, $saved['byte_offset']);
        $this->assertSame(0, $result['end']);
        $this->assertStringContainsString('Import stopped', (string)$saved['last_error']);
    }

    /**
     * The per-file result must not report hits of a batch that was rolled back.
     */
    public function testRolledBackBatchIsNotReportedAsImportedHits(): void
    {
        $this->failSavepointRollback = true;
        $this->hook = static function (string $path): void {
            if ($path === '/deadlock') {
                throw new \RuntimeException('Deadlock found when trying to get lock');
            }
        };
        $this->logFile(self::line('/a') . self::line('/b') . self::line('/deadlock'));

        $result = $this->importer()->run()['files'][0];

        $this->assertSame(0, $result['hits'], 'Reported ' . $result['hits'] . ' hits although nothing was committed');
    }

    /**
     * Tokens that ordinary shoppers send must never be accepted as a bot token.
     *
     * @dataProvider genericTokens
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('genericTokens')]
    public function testBrowserTextIsGeneric(string $token): void
    {
        $this->assertTrue(BrowserUserAgents::sharesBrowserText($token), $token);
    }

    public static function genericTokens(): array
    {
        $tokens = ['Mozilla/5.0', '5.0', 'Win', 'like', 'Intel', 'Intel Mac', 'Chrome/130.0.0.0', 'Safari/537.36',
            'Mobile Safari', 'Gecko/20100101', 'Version/17.5', 'SamsungBrowser', 'Edg/130.0.0.0', 'CriOS', 'Linux'];
        return array_combine($tokens, array_map(static fn (string $t): array => [$t], $tokens));
    }
}
