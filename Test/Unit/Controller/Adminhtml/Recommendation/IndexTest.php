<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Recommendation;

use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Panth\AiVisibility\Controller\Adminhtml\Recommendation\Index;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    use RecommendationContextTrait;

    private array $titles = [];

    private array $menus = [];

    private function controller(): Index
    {
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($text): void {
            $this->titles[] = (string)$text;
        });
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $page->method('setActiveMenu')->willReturnCallback(function ($menu) use ($page) {
            $this->menus[] = $menu;
            return $page;
        });
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);
        return new Index($this->context(), $factory);
    }

    public function testAclAndGetOnly(): void
    {
        $controller = $this->controller();
        $this->assertAclResource($controller);
        $this->assertInstanceOf(HttpGetActionInterface::class, $controller);
        $this->assertNotInstanceOf(HttpPostActionInterface::class, $controller);
    }

    public function testRendersPageWithMenuAndTitle(): void
    {
        $this->controller()->execute();

        $this->assertSame(['Panth_AiVisibility::menu_recommendations'], $this->menus);
        $this->assertSame(['AI Recommendations'], $this->titles);
        $this->assertSame([], $this->messages);
    }
}
