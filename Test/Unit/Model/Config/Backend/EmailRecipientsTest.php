<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Model\Config\Backend\EmailRecipients;
use PHPUnit\Framework\TestCase;

class EmailRecipientsTest extends TestCase
{
    public function testNormalisesToACommaSeparatedList(): void
    {
        $this->assertSame(
            'owner@shop.test, ops@example.invalid',
            EmailRecipients::normalize(" owner@shop.test\nops@example.invalid; OWNER@shop.test ", true)
        );
    }

    public function testEmptyIsAllowedWhileTheEmailIsOff(): void
    {
        $this->assertSame('', EmailRecipients::normalize("  \n ", false));
    }

    public function testEmptyIsRejectedWhileTheEmailIsOn(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('at least one address');
        EmailRecipients::normalize('', true);
    }

    public function testInvalidAddressesAreRejectedAndNamed(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('owner@, nobody');
        EmailRecipients::normalize('ok@shop.test, owner@, nobody', false);
    }

    public function testTooManyAddressesAreRejected(): void
    {
        $list = [];
        for ($i = 1; $i <= 21; $i++) {
            $list[] = 'user' . $i . '@shop.test';
        }
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('at most 20');
        EmailRecipients::normalize(implode(',', $list), true);
    }
}
