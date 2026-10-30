<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Message\ManagerInterface;

/**
 * Backend action context stubs that record messages, redirects and ACL checks.
 */
trait BackendContextTrait
{
    private array $params = [];

    private bool $formKeyValid = true;

    private array $messages = [];

    private ?string $redirectPath = null;

    private array $redirectParams = [];

    private array $aclChecks = [];

    private function backendContext(): Context
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn ($key, $default = null) => $this->params[$key] ?? $default
        );

        $messages = $this->createStub(ManagerInterface::class);
        foreach (['Error' => 'error', 'Success' => 'success', 'Warning' => 'warning', 'Notice' => 'notice'] as $m => $t) {
            $messages->method('add' . $m . 'Message')->willReturnCallback(
                function ($message) use ($messages, $t) {
                    $this->messages[] = [$t, (string)$message];
                    return $messages;
                }
            );
        }

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path, $params = []) use ($redirect) {
            $this->redirectPath = $path;
            $this->redirectParams = $params;
            return $redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(function ($resource): bool {
            $this->aclChecks[] = $resource;
            return true;
        });

        $formKeyValidator = $this->createStub(Validator::class);
        $formKeyValidator->method('validate')->willReturnCallback(fn () => $this->formKeyValid);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getAuthorization')->willReturn($authorization);
        $context->method('getFormKeyValidator')->willReturn($formKeyValidator);
        return $context;
    }

    private function assertAclResource(object $controller, string $resource): void
    {
        $method = new \ReflectionMethod($controller, '_isAllowed');
        $this->assertTrue($method->invoke($controller));
        $this->assertSame([$resource], $this->aclChecks);
    }

    private function messagesOfType(string $type): array
    {
        return array_values(array_map(
            fn ($m) => $m[1],
            array_filter($this->messages, fn ($m) => $m[0] === $type)
        ));
    }
}
