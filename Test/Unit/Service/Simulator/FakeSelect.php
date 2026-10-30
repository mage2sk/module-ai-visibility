<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Simulator;

/**
 * Minimal stand-in for Magento\Framework\DB\Select: records the fluent calls.
 */
class FakeSelect
{
    /**
     * @var array<int, array{0: string, 1: array}>
     */
    public array $calls = [];

    /**
     * @param string $name
     * @param array $arguments
     * @return $this
     */
    public function __call(string $name, array $arguments): self
    {
        $this->calls[] = [$name, $arguments];
        return $this;
    }

    /**
     * Table name of the first from()
     *
     * @return string
     */
    public function table(): string
    {
        foreach ($this->calls as [$name, $arguments]) {
            if ($name === 'from') {
                $table = $arguments[0];
                return is_array($table) ? (string) current($table) : (string) $table;
            }
        }
        return '';
    }

    /**
     * Bound value of a where() condition
     *
     * @param string $condition
     * @return mixed
     */
    public function whereValue(string $condition)
    {
        foreach ($this->calls as [$name, $arguments]) {
            if ($name === 'where' && $arguments[0] === $condition) {
                return $arguments[1] ?? null;
            }
        }
        return null;
    }
}
