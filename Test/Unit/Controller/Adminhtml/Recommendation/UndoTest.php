<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Recommendation;

use Panth\AiVisibility\Controller\Adminhtml\Recommendation\Undo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UndoTest extends TestCase
{
    use RecommendationContextTrait;
    use RepositoryFixtureTrait;

    private function controller(): Undo
    {
        return new Undo($this->context(), $this->actionLog(), $this->logger());
    }

    private function post(array $params): void
    {
        $this->params = $params;
        $this->controller()->execute();
    }

    public function testAclAndPostOnly(): void
    {
        $controller = $this->controller();
        $this->assertAclResource($controller);
        $this->assertPostOnly($controller);
    }

    public function testUndoesAction(): void
    {
        $this->post(['action_id' => '12', 'store' => '3']);

        $this->assertSame([12], $this->undone);
        $this->assertStringContainsString('marked as undone', $this->messagesOfType('success')[0]);
        $this->assertSame(['store' => 3], $this->redirectParams);
    }

    public function testInvalidFormKeyWritesNothing(): void
    {
        $this->formKeyValid = false;
        $this->post(['action_id' => '12']);
        $this->assertSingleError('session has expired');
        $this->assertNothingWritten();
    }

    /**
     * @dataProvider invalidIdProvider
     */
    #[DataProvider('invalidIdProvider')]
    public function testInvalidIdIsRejected($id): void
    {
        $this->post(['action_id' => $id]);
        $this->assertSingleError('No applied action was selected');
        $this->assertNothingWritten();
    }

    public function testRecIdIsNotAcceptedInsteadOfActionId(): void
    {
        $this->post(['rec_id' => '12']);
        $this->assertSingleError('No applied action was selected');
    }

    public function testUnknownActionShowsTheServiceMessage(): void
    {
        $this->post(['action_id' => '404']);
        $this->assertSingleError('This action no longer exists.');
        $this->assertSame([], $this->warnings);
    }

    public function testUnexpectedErrorIsLogged(): void
    {
        $this->writeFailure = new \RuntimeException('boom');
        $this->post(['action_id' => '12']);
        $this->assertSingleError('could not be undone');
        $this->assertCount(1, $this->warnings);
    }
}
