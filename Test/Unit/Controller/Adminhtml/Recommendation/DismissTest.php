<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Recommendation;

use Panth\AiVisibility\Controller\Adminhtml\Recommendation\Dismiss;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DismissTest extends TestCase
{
    use RecommendationContextTrait;
    use RepositoryFixtureTrait;

    private function controller(): Dismiss
    {
        return new Dismiss($this->context(), $this->repository(), $this->logger());
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

    public function testDismissesOpenRecommendation(): void
    {
        $this->openRec(7);

        $this->post(['rec_id' => '7', 'store' => '1']);

        $this->assertSame([[7, 'dismissed']], $this->statusChanges);
        $this->assertStringContainsString('Dismissed "Fix <b>404</b> pages"', $this->messagesOfType('success')[0]);
        $this->assertSame(['store' => 1], $this->redirectParams);
    }

    public function testInvalidFormKeyWritesNothing(): void
    {
        $this->openRec(7);
        $this->formKeyValid = false;

        $this->post(['rec_id' => '7']);

        $this->assertSingleError('session has expired');
        $this->assertNothingWritten();
    }

    /**
     * @dataProvider invalidIdProvider
     */
    #[DataProvider('invalidIdProvider')]
    public function testInvalidIdIsRejected($id): void
    {
        $this->post(['rec_id' => $id]);

        $this->assertSingleError('No recommendation was selected');
        $this->assertNothingWritten();
    }

    public function testUnknownAndClosedRecommendations(): void
    {
        $this->post(['rec_id' => '9']);
        $this->assertSingleError('no longer exists');

        $this->messages = [];
        $this->openRec(7, 'applied');
        $this->post(['rec_id' => '7']);
        $this->assertSingleError('no longer open');
        $this->assertNothingWritten();
    }

    public function testInvalidStoreParamIsDroppedFromRedirect(): void
    {
        $this->openRec(7);
        $this->post(['rec_id' => '7', 'store' => '<x>']);
        $this->assertSame([], $this->redirectParams);
    }

    public function testUnexpectedErrorIsLogged(): void
    {
        $this->openRec(7);
        $this->writeFailure = new \RuntimeException('boom');

        $this->post(['rec_id' => '7']);

        $this->assertSingleError('could not be dismissed');
        $this->assertCount(1, $this->warnings);
    }
}
