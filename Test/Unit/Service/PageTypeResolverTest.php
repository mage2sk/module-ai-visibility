<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service;

use Panth\AiVisibility\Api\PageTypeResolverInterface as Type;
use Panth\AiVisibility\Service\PageTypeResolver;
use PHPUnit\Framework\TestCase;

class PageTypeResolverTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pathProvider(): array
    {
        return [
            'root' => ['/', Type::TYPE_HOME],
            'empty' => ['', Type::TYPE_HOME],
            'index.php' => ['/index.php', Type::TYPE_HOME],
            'index.php slash' => ['/index.php/', Type::TYPE_HOME],
            'double slash' => ['//', Type::TYPE_HOME],
            'llms' => ['/llms.txt', Type::TYPE_LLMS],
            'llms full' => ['/llms-full.txt', Type::TYPE_LLMS],
            'llms upper' => ['/LLMS.TXT', Type::TYPE_LLMS],
            'llms store code' => ['/en/llms.txt', Type::TYPE_LLMS],
            'robots' => ['/robots.txt', Type::TYPE_ROBOTS],
            'sitemap' => ['/sitemap.xml', Type::TYPE_SITEMAP],
            'media sitemap' => ['/media/sitemap/sitemap-1-1.xml', Type::TYPE_SITEMAP],
            'sitemap gz' => ['/sitemap_products.xml.gz', Type::TYPE_SITEMAP],
            'search' => ['/catalogsearch/result/', Type::TYPE_SEARCH],
            'search index' => ['/catalogsearch/result/index/', Type::TYPE_SEARCH],
            'search suggest' => ['/search/ajax/suggest/', Type::TYPE_SEARCH],
            'product html' => ['/joust-duffle-bag.html', Type::TYPE_PAGE],
            'category nested' => ['/gear/bags.html', Type::TYPE_PAGE],
            'cms no suffix' => ['/about-us', Type::TYPE_PAGE],
            'trailing slash' => ['/women/tops/', Type::TYPE_PAGE],
            'index.php product' => ['/index.php/joust-duffle-bag.html', Type::TYPE_PAGE],
            'checkout' => ['/checkout/cart/', Type::TYPE_OTHER],
            'customer' => ['/customer/account/login/', Type::TYPE_OTHER],
            'rest' => ['/rest/V1/products', Type::TYPE_OTHER],
            'graphql' => ['/graphql', Type::TYPE_OTHER],
            'catalog raw' => ['/catalog/product/view/id/5', Type::TYPE_OTHER],
            'image' => ['/some/file.jpg', Type::TYPE_OTHER],
            'pdf' => ['/manual.pdf', Type::TYPE_OTHER],
            'other txt' => ['/humans.txt', Type::TYPE_OTHER],
            'query ignored' => ['/llms.txt?x=1', Type::TYPE_LLMS],
            'not llms' => ['/llms.txt.bak', Type::TYPE_OTHER],
            'llms dir' => ['/llms/', Type::TYPE_PAGE],
        ];
    }

    /**
     * @dataProvider pathProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pathProvider')]
    public function testResolve(string $path, string $expected): void
    {
        $this->assertSame($expected, (new PageTypeResolver())->resolve($path));
    }
}
