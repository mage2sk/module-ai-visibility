<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Panth\AiVisibility\Controller\Adminhtml\Dashboard\Index;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    public function testAclAndGetOnly(): void
    {
        $this->assertSame('Panth_AiVisibility::dashboard', Index::ADMIN_RESOURCE);
        $this->assertTrue(is_subclass_of(Index::class, HttpGetActionInterface::class));
    }

    public function testExecuteSetsMenuAndTitle(): void
    {
        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('prepend');
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->expects($this->once())->method('setActiveMenu')->with('Panth_AiVisibility::menu_dashboard');
        $page->method('getConfig')->willReturn($pageConfig);
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);

        $controller = new Index($this->createStub(Context::class), $factory);
        $this->assertSame($page, $controller->execute());
    }
}
