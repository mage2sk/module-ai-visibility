<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Loads every class of the module, so an incompatible override of a parent method (a fatal error
 * at class load time) is caught by the unit suite and not on the first admin page view.
 */
class ClassLoadTest extends TestCase
{
    public function testEveryClassAndInterfaceLoads(): void
    {
        $base = dirname(__DIR__, 2);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        $loaded = 0;
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($base) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if (!str_ends_with($relative, '.php')
                || str_starts_with($relative, 'Test/')
                || !preg_match('#^[A-Z][A-Za-z0-9]*/#', $relative)
            ) {
                continue;
            }
            $class = 'Panth\\AiVisibility\\' . str_replace('/', '\\', substr($relative, 0, -4));
            $this->assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                $class . ' cannot be loaded'
            );
            $loaded++;
        }
        $this->assertGreaterThan(90, $loaded);
    }
}
