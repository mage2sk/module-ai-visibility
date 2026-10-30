<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Health;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Database and clock stubs for the health check tests.
 */
trait DbStubTrait
{
    /**
     * Conditions passed to Select::where(), in call order
     *
     * @var array
     */
    private array $wheres = [];

    /**
     * Tables passed to Select::from()
     *
     * @var array
     */
    private array $froms = [];

    /**
     * Resource whose adapter answers fetch* calls through the given callbacks (keyed by method)
     *
     * @param array<string, callable|mixed> $answers
     * @return ResourceConnection
     */
    private function resource(array $answers): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        foreach (['where', 'order', 'limit', 'group'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($select, $method) {
                if ($method === 'where') {
                    $this->wheres[] = [$args[0], $args[1] ?? null];
                }
                return $select;
            });
        }
        $select->method('from')->willReturnCallback(function ($table) use ($select) {
            $this->froms[] = $table;
            return $select;
        });

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        foreach (['fetchOne', 'fetchRow', 'fetchPairs', 'fetchAll'] as $method) {
            if (!array_key_exists($method, $answers)) {
                $connection->method($method)->willReturn($method === 'fetchOne' ? false : []);
                continue;
            }
            $answer = $answers[$method];
            if ($answer instanceof \Throwable) {
                $connection->method($method)->willThrowException($answer);
            } elseif ($answer instanceof \Closure) {
                $connection->method($method)->willReturnCallback($answer);
            } else {
                $connection->method($method)->willReturn($answer);
            }
        }

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(fn ($name) => 'pfx_' . $name);
        return $resource;
    }

    /**
     * Clock stub
     *
     * @param string $utc
     * @return DateTime
     */
    private function clock(string $utc): DateTime
    {
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(
            (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp()
        );
        return $clock;
    }
}
