<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Recommendation;

use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Controller\Adminhtml\Recommendation\Feedback;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FeedbackTest extends TestCase
{
    use RecommendationContextTrait;
    use RepositoryFixtureTrait;

    private function controller(): Feedback
    {
        return new Feedback($this->context(), $this->repository(), $this->logger());
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

    public static function valueProvider(): array
    {
        return [
            'useful' => ['1', 1, 'marked as useful'],
            'not useful' => ['-1', -1, 'ranked lower for 30 days'],
            'padded' => [' 1 ', 1, 'marked as useful'],
        ];
    }

    /**
     * @dataProvider valueProvider
     */
    #[DataProvider('valueProvider')]
    public function testStoresFeedback(string $value, int $stored, string $needle): void
    {
        $this->openRec(7);

        $this->post(['rec_id' => '7', 'value' => $value]);

        $this->assertSame([[7, $stored]], $this->feedbacks);
        $this->assertStringContainsString($needle, $this->messagesOfType('success')[0]);
    }

    public function testFeedbackIsAllowedOnClosedRecommendations(): void
    {
        $this->openRec(7, 'applied');
        $this->post(['rec_id' => '7', 'value' => '-1']);
        $this->assertSame([[7, -1]], $this->feedbacks);
    }

    public function testInvalidFormKeyWritesNothing(): void
    {
        $this->openRec(7);
        $this->formKeyValid = false;

        $this->post(['rec_id' => '7', 'value' => '1']);

        $this->assertSingleError('session has expired');
        $this->assertNothingWritten();
    }

    /**
     * @dataProvider invalidIdProvider
     */
    #[DataProvider('invalidIdProvider')]
    public function testInvalidIdIsRejected($id): void
    {
        $this->post(['rec_id' => $id, 'value' => '1']);

        $this->assertSingleError('No recommendation was selected');
        $this->assertNothingWritten();
    }

    public static function invalidValueProvider(): array
    {
        return [
            'missing' => [null],
            'zero' => ['0'],
            'two' => ['2'],
            'word' => ['useful'],
            'array' => [['1']],
            'boolean' => [true],
        ];
    }

    /**
     * @dataProvider invalidValueProvider
     */
    #[DataProvider('invalidValueProvider')]
    public function testInvalidValueIsRejected($value): void
    {
        $this->openRec(7);

        $this->post(['rec_id' => '7', 'value' => $value]);

        $this->assertSingleError('Choose "Useful" or "Not useful"');
        $this->assertNothingWritten();
    }

    public function testUnknownRecommendation(): void
    {
        $this->post(['rec_id' => '5', 'value' => '1']);
        $this->assertSingleError('no longer exists');
        $this->assertNothingWritten();
    }

    public function testRepositoryErrors(): void
    {
        $this->openRec(7);
        $this->writeFailure = new LocalizedException(__('Invalid feedback value.'));
        $this->post(['rec_id' => '7', 'value' => '1']);
        $this->assertSingleError('Invalid feedback value.');

        $this->messages = [];
        $this->writeFailure = new \RuntimeException('boom');
        $this->post(['rec_id' => '7', 'value' => '1']);
        $this->assertSingleError('could not be saved');
        $this->assertCount(1, $this->warnings);
    }
}
