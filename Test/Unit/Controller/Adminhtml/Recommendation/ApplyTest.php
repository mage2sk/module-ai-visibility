<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Recommendation;

use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Controller\Adminhtml\Recommendation\Apply;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApplyTest extends TestCase
{
    use RecommendationContextTrait;
    use RepositoryFixtureTrait;

    private function controller(): Apply
    {
        return new Apply($this->context(), $this->actionLog(), $this->repository(), $this->config(), $this->logger());
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

    public function testAppliesWithTrimmedNoteAndAdminUser(): void
    {
        $this->openRec(7);

        $this->post(['rec_id' => '7', 'note' => "  Added redirects\n ", 'store' => '2']);

        $this->assertSame([[7, 'Added redirects', 'store_admin']], $this->applied);
        $success = $this->messagesOfType('success');
        $this->assertCount(1, $success);
        $this->assertStringContainsString('Marked "Fix <b>404</b> pages" as applied', $success[0]);
        $this->assertStringContainsString('14 days', $success[0]);
        $this->assertSame('*/*/index', $this->redirectPath);
        $this->assertSame(['store' => 2], $this->redirectParams);
    }

    public function testEmptyNoteAndNoAdminBecomeNull(): void
    {
        $this->openRec(7);
        $this->adminName = null;

        $this->post(['rec_id' => '7', 'note' => '   ']);

        $this->assertSame([[7, null, null]], $this->applied);
        $this->assertSame([], $this->redirectParams);
    }

    public function testNoteOfExactly512CharactersIsAccepted(): void
    {
        $this->openRec(7);
        $note = str_repeat('é', 512);

        $this->post(['rec_id' => '7', 'note' => $note]);

        $this->assertSame($note, $this->applied[0][1]);
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
        $this->openRec(7);

        $this->post(['rec_id' => $id]);

        $this->assertSingleError('No recommendation was selected');
        $this->assertNothingWritten();
    }

    public static function invalidNoteProvider(): array
    {
        return [
            'array note' => [['x'], 'could not be read'],
            'too long' => [str_repeat('a', 513), 'at most 512 characters'],
        ];
    }

    /**
     * @dataProvider invalidNoteProvider
     */
    #[DataProvider('invalidNoteProvider')]
    public function testInvalidNoteIsRejected($note, string $needle): void
    {
        $this->openRec(7);

        $this->post(['rec_id' => '7', 'note' => $note]);

        $this->assertSingleError($needle);
        $this->assertNothingWritten();
    }

    public function testUnknownRecommendation(): void
    {
        $this->post(['rec_id' => '8']);

        $this->assertSingleError('no longer exists');
        $this->assertNothingWritten();
    }

    public function testRecommendationThatIsNotOpen(): void
    {
        $this->openRec(7, 'dismissed');

        $this->post(['rec_id' => '7']);

        $this->assertSingleError('no longer open (status: dismissed)');
        $this->assertNothingWritten();
    }

    public function testLocalizedExceptionMessageIsShown(): void
    {
        $this->openRec(7);
        $this->writeFailure = new LocalizedException(__('Already applied elsewhere.'));

        $this->post(['rec_id' => '7']);

        $this->assertSingleError('Already applied elsewhere.');
        $this->assertSame([], $this->warnings);
    }

    public function testUnexpectedErrorIsLoggedWithGenericMessage(): void
    {
        $this->openRec(7);
        $this->writeFailure = new \RuntimeException('SQLSTATE secret detail');

        $this->post(['rec_id' => '7']);

        $this->assertSingleError('could not be saved');
        $this->assertStringNotContainsString('SQLSTATE', implode(' ', $this->messagesOfType('error')));
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('SQLSTATE', $this->warnings[0]);
    }
}
