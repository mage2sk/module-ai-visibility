<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\ConsentChecker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConsentCheckerTest extends TestCase
{
    private function checker(bool $respect, ?bool $notAllowed, int $warnings = 0): ConsentChecker
    {
        $config = $this->createStub(Config::class);
        $config->method('respectCookieConsent')->willReturn($respect);
        $helper = $this->createMock(CookieHelper::class);
        $invocation = $helper->expects($respect ? $this->once() : $this->never())->method('isUserNotAllowSaveCookie');
        if ($notAllowed === null) {
            $invocation->willThrowException(new \RuntimeException('no store'));
        } else {
            $invocation->willReturn($notAllowed);
        }
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly($warnings))->method('warning');
        return new ConsentChecker($config, $helper, $logger);
    }

    public function testConsentIgnoredWhenSettingOff(): void
    {
        $this->assertTrue($this->checker(false, true)->canSetCookie());
    }

    public function testRestrictionModeWithoutConsentBlocks(): void
    {
        $this->assertFalse($this->checker(true, true)->canSetCookie());
    }

    public function testAcceptedOrNoRestrictionAllows(): void
    {
        $this->assertTrue($this->checker(true, false)->canSetCookie());
    }

    public function testHelperFailureBlocksAndLogs(): void
    {
        $this->assertFalse($this->checker(true, null, 1)->canSetCookie());
    }
}
