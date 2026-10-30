<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Recommendation\Rule;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\DataScope;
use Panth\AiVisibility\Service\Recommendation\Rule\Support\RuleSupport;
use Psr\Log\LoggerInterface;

/**
 * Fake database for the rule tests: queries are answered by routes (fetch method + SQL fragment)
 * and recorded with their bind values, so tests can check filters and placeholders.
 */
trait RuleDbTrait
{
    /**
     * @var array<int, array{method: string, sql: string, bind: array}>
     */
    private array $queries = [];

    /**
     * @var array<int, array{0: string, 1: string, 2: mixed}>
     */
    private array $routes = [];

    /**
     * @var string[]
     */
    private array $existingTables = [];

    /**
     * @var bool
     */
    private bool $rowIdLink = false;

    /**
     * @var string[]
     */
    private array $warnings = [];

    /**
     * Data scope shared by the rules of a test
     *
     * @var DataScope|null
     */
    private ?DataScope $scope = null;

    /**
     * Answer the first query whose SQL contains $fragment with $result
     *
     * @param string $method fetchAll | fetchPairs | fetchRow | fetchOne
     * @param string $fragment
     * @param mixed $result
     * @return void
     */
    private function route(string $method, string $fragment, $result): void
    {
        $this->routes[] = [$method, $fragment, $result];
    }

    /**
     * RuleSupport on the fake connection
     *
     * @param int $minVolume
     * @param string $urlStoreCode
     * @return RuleSupport
     */
    private function support(int $minVolume = 20, string $urlStoreCode = ''): RuleSupport
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($name): string => '`' . $name . '`');
        $connection->method('isTableExists')->willReturnCallback(
            fn ($name): bool => in_array($name, $this->existingTables, true)
        );
        $connection->method('tableColumnExists')->willReturnCallback(
            fn ($table, $column): bool => $column === 'row_id' && $this->rowIdLink
        );
        foreach (['fetchAll' => [], 'fetchPairs' => [], 'fetchRow' => false, 'fetchOne' => false] as $method => $empty) {
            $connection->method($method)->willReturnCallback(
                function ($sql, $bind = []) use ($method, $empty) {
                    $sql = (string)$sql;
                    $this->queries[] = ['method' => $method, 'sql' => $sql, 'bind' => (array)$bind];
                    foreach ($this->routes as [$routeMethod, $fragment, $result]) {
                        if ($routeMethod === $method && str_contains($sql, $fragment)) {
                            return $result;
                        }
                    }
                    return $empty;
                }
            );
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $config = $this->createStub(Config::class);
        $config->method('recommendationsMinVolume')->willReturn($minVolume);
        $config->method('urlStoreCode')->willReturn($urlStoreCode);
        $this->scope ??= new DataScope();
        return new RuleSupport($resource, $config, $this->scope);
    }

    /**
     * Logger that keeps warnings
     *
     * @return LoggerInterface
     */
    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });
        return $logger;
    }

    /**
     * Evaluation day used by the tests
     *
     * @return \DateTimeImmutable
     */
    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-30 04:20:00', new \DateTimeZone('UTC'));
    }

    /**
     * Every recorded query reads real rows only and has as many bind values as placeholders
     *
     * @param string[] $tablesWithSimFlag Fragments of queries that must filter is_simulated = 0
     * @return void
     */
    private function assertRealRowsOnly(array $tablesWithSimFlag): void
    {
        $this->assertNotEmpty($this->queries);
        foreach ($this->queries as $query) {
            $this->assertSame(substr_count($query['sql'], '?'), count($query['bind']), $query['sql']);
            foreach ($tablesWithSimFlag as $fragment) {
                if (str_contains($query['sql'], 'FROM `' . $fragment . '`')) {
                    $this->assertStringContainsString('is_simulated = 0', $query['sql']);
                }
            }
        }
    }

    /**
     * Recorded queries whose SQL contains a fragment
     *
     * @param string $fragment
     * @return array<int, array{method: string, sql: string, bind: array}>
     */
    private function queriesWith(string $fragment): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (array $query): bool => str_contains($query['sql'], $fragment)
        ));
    }
}
