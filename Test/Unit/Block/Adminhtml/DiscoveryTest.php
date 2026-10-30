<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Block\Adminhtml;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\AiVisibility\Block\Adminhtml\Discovery;
use PHPUnit\Framework\TestCase;

class DiscoveryTest extends TestCase
{
    /**
     * Loading the class fails with a fatal error when a method clashes with a parent block method
     */
    public function testBlockDoesNotOverrideParentMethodsIncompatibly(): void
    {
        $ref = new \ReflectionClass(Discovery::class);
        $this->assertFalse(
            $ref->getMethod('formatDate')->getDeclaringClass()->getName() === Discovery::class,
            'formatDate() belongs to AbstractBlock'
        );
        $this->assertTrue($ref->hasMethod('formatUtcDate'));
    }

    public function testFormatUtcDate(): void
    {
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('formatDateTime')->willReturnCallback(
            static fn (\DateTimeInterface $date) => 'local ' . $date->format('Y-m-d H:i') . ' ' . $date->getTimezone()->getName()
        );
        $block = $this->block($timezone);

        $this->assertSame('', $block->formatUtcDate(null));
        $this->assertSame('', $block->formatUtcDate(''));
        $this->assertSame('local 2026-10-29 10:15 UTC', $block->formatUtcDate('2026-10-29 10:15:30'));
    }

    public function testFormatUtcDateFallsBackToTheRawValue(): void
    {
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('formatDateTime')->willThrowException(new \RuntimeException('intl missing'));
        $this->assertSame('2026-10-29 10:15:30 UTC', $this->block($timezone)->formatUtcDate('2026-10-29 10:15:30'));
    }

    private function block(TimezoneInterface $timezone): Discovery
    {
        $ref = new \ReflectionClass(Discovery::class);
        /** @var Discovery $block */
        $block = $ref->newInstanceWithoutConstructor();
        // Readonly promoted property: initialise it from the class scope.
        \Closure::bind(function (TimezoneInterface $timezone): void {
            $this->timezone = $timezone;
        }, $block, Discovery::class)($timezone);
        return $block;
    }
}
