<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Recommendation;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\DataObject;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Backend action context stubs for the recommendation actions: records messages, redirects, ACL checks
 * and logged warnings; the signed-in admin is "store_admin".
 */
trait RecommendationContextTrait
{
    private array $params = [];

    private bool $formKeyValid = true;

    private array $messages = [];

    private ?string $redirectPath = null;

    private array $redirectParams = [];

    private array $aclChecks = [];

    private array $warnings = [];

    private ?string $adminName = 'store_admin';

    private function context(): Context
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn ($key, $default = null) => array_key_exists($key, $this->params) ? $this->params[$key] : $default
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

        $auth = $this->createStub(Auth::class);
        $auth->method('getUser')->willReturnCallback(
            fn () => $this->adminName === null ? null : new DataObject(['user_name' => $this->adminName])
        );

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getAuthorization')->willReturn($authorization);
        $context->method('getFormKeyValidator')->willReturn($formKeyValidator);
        $context->method('getAuth')->willReturn($auth);
        return $context;
    }

    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });
        return $logger;
    }

    private function assertAclResource(object $controller): void
    {
        $this->assertSame('Panth_AiVisibility::recommendations', $controller::ADMIN_RESOURCE);
        $method = new \ReflectionMethod($controller, '_isAllowed');
        $method->setAccessible(true);
        $this->assertTrue($method->invoke($controller));
        $this->assertSame(['Panth_AiVisibility::recommendations'], $this->aclChecks);
    }

    private function assertPostOnly(object $controller): void
    {
        $this->assertInstanceOf(HttpPostActionInterface::class, $controller);
        $this->assertNotInstanceOf(HttpGetActionInterface::class, $controller);
    }

    private function messagesOfType(string $type): array
    {
        return array_values(array_map(
            fn ($m) => $m[1],
            array_filter($this->messages, fn ($m) => $m[0] === $type)
        ));
    }

    private function assertSingleError(string $needle): void
    {
        $errors = $this->messagesOfType('error');
        $this->assertCount(1, $errors, 'messages: ' . json_encode($this->messages));
        $this->assertStringContainsString($needle, $errors[0]);
        $this->assertSame([], $this->messagesOfType('success'));
        $this->assertSame('*/*/index', $this->redirectPath);
    }

    /**
     * Invalid id values shared by the actions that take an ID
     */
    public static function invalidIdProvider(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'zero' => ['0'],
            'negative' => ['-3'],
            'letters' => ['7abc'],
            'float' => ['7.5'],
            'array' => [['7']],
            'too long' => ['99999999999'],
            'boolean' => [true],
        ];
    }
}
