<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Block;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\SourceClassifierInterface;
use Panth\AiVisibility\Block\Collector;
use Panth\AiVisibility\Model\Config;
use PHPUnit\Framework\TestCase;

class CollectorTest extends TestCase
{
    /**
     * @var array<int, array>
     */
    private array $rendered = [];

    private function block(bool $enabled = true, bool $trackVisits = true, bool $discovery = true): Collector
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            fn (string $route, array $params) => ($params['_secure'] ? 'https' : 'http') . '://shop.test/' . $route . '/'
        );
        $request = $this->createStub(RequestInterface::class);
        $request->method('isSecure')->willReturn(true);
        $context = $this->createStub(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);
        $context->method('getUrlBuilder')->willReturn($url);
        $context->method('getRequest')->willReturn($request);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn ($storeId = null) => $storeId === 2 && $enabled);
        $config->method('trackVisits')->willReturn($trackVisits);
        $config->method('discoveryEnabled')->willReturn($discovery);
        $config->method('landingSampleRate')->willReturn(25);

        $classifier = $this->createStub(SourceClassifierInterface::class);
        $classifier->method('clientConfig')->willReturn([
            'hosts' => ['chatgpt.com' => 'chatgpt', 'evil</script>.example.invalid' => 'x'],
            'utm' => ['chatgpt' => 'chatgpt'],
            'candidateRegex' => '(^|[.-])(ai|gpt)([.-]|$)',
            'nonAiHosts' => ['google.*'],
        ]);

        $renderer = $this->createStub(SecureHtmlRenderer::class);
        $renderer->method('renderTag')->willReturnCallback(
            function (string $tag, array $attributes, ?string $content, bool $textContent) {
                $this->rendered[] = [$tag, $attributes, $content, $textContent];
                return '<script data-test="1">' . $content . '</script>';
            }
        );
        return new Collector($context, $config, $classifier, $renderer);
    }

    public function testEnabledFlags(): void
    {
        $this->assertTrue($this->block()->isEnabled());
        $this->assertFalse($this->block(false)->isEnabled());
        $this->assertFalse($this->block(true, false)->isEnabled());
    }

    public function testConfigJson(): void
    {
        $json = $this->block()->getConfigJson();
        $this->assertStringNotContainsString('</script>', $json);
        $config = json_decode($json, true);
        $this->assertSame('https://shop.test/panth_aiv/collect/index/', $config['u']);
        $this->assertSame('chatgpt', $config['h']['chatgpt.com']);
        $this->assertSame(['chatgpt' => 'chatgpt'], $config['s']);
        $this->assertSame('(^|[.-])(ai|gpt)([.-]|$)', $config['c']);
        $this->assertSame(['google.*'], $config['n']);
        $this->assertSame(25, $config['r']);
    }

    public function testDiscoveryOffClearsCandidateRegex(): void
    {
        $config = json_decode($this->block(true, true, false)->getConfigJson(), true);
        $this->assertSame('', $config['c']);
    }

    public function testEmptyMapsEncodeAsObjects(): void
    {
        $block = $this->block();
        $this->assertStringContainsString('"s":{"chatgpt":"chatgpt"}', $block->getConfigJson());
    }

    public function testScriptIsRenderedThroughSecureRendererAsInlineCode(): void
    {
        $html = $this->block()->renderScript();
        $this->assertSame([['script', [], Collector::SCRIPT, false]], $this->rendered);
        $this->assertStringContainsString(Collector::SCRIPT, $html);
    }

    public function testSnippetIsSmallVanillaAndPrivacySafe(): void
    {
        $script = Collector::SCRIPT;
        $this->assertLessThan(2048, strlen($script));
        $this->assertStringContainsString(Collector::CONFIG_ELEMENT_ID, $script);
        $this->assertStringContainsString('sessionStorage', $script);
        $this->assertStringContainsString("keepalive:true", $script);
        $this->assertStringContainsString("credentials:'same-origin'", $script);
        $this->assertStringContainsString("'Content-Type':'text/plain'", $script);
        $this->assertStringContainsString('requestIdleCallback', $script);
        // Only host name, utm value and path are sent: never the referrer URL or the query string.
        $this->assertStringContainsString('{t:t,h:h,u:u,p:p}', $script);
        $this->assertStringContainsString('{t:t,p:p}', $script);
        $this->assertStringContainsString('x.hostname', $script);
        $this->assertStringNotContainsString('l.href', $script);
        $this->assertStringNotContainsString('document.cookie', $script);
        foreach (['require(', 'define(', 'jQuery', '$(', 'Alpine', 'ko.'] as $dependency) {
            $this->assertStringNotContainsString($dependency, $script);
        }
    }
}
