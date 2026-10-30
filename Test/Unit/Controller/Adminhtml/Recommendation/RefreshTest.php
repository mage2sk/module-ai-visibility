<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Recommendation;

use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Api\RecommendationEngineInterface;
use Panth\AiVisibility\Controller\Adminhtml\Recommendation\Refresh;
use Panth\AiVisibility\ViewModel\Recommendation\Presenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RefreshTest extends TestCase
{
    use RecommendationContextTrait;
    use RepositoryFixtureTrait;

    private array $runs = [];

    private ?\Throwable $engineFailure = null;

    private array $engineErrors = [];

    private function controller(bool $enabled = true): Refresh
    {
        $engine = $this->createStub(RecommendationEngineInterface::class);
        $engine->method('run')->willReturnCallback(function (?int $storeId = null, bool $dryRun = false): array {
            if ($this->engineFailure !== null) {
                throw $this->engineFailure;
            }
            $this->runs[] = [$storeId, $dryRun];
            return ['created' => 2, 'updated' => 5, 'resolved' => 1, 'findings' => [], 'errors' => $this->engineErrors];
        });
        return new Refresh($this->context(), $engine, $this->config($enabled), $this->logger(), new Presenter());
    }

    private function post(array $params, bool $enabled = true): void
    {
        $this->params = $params;
        $this->controller($enabled)->execute();
    }

    public function testAclAndPostOnly(): void
    {
        $controller = $this->controller();
        $this->assertAclResource($controller);
        $this->assertPostOnly($controller);
    }

    public static function storeProvider(): array
    {
        return [
            'current store' => [['store' => '2'], 2, ['store' => 2]],
            'all stores' => [[], null, []],
            'explicit all' => [['store' => '0'], null, []],
            'empty' => [['store' => ''], null, []],
        ];
    }

    /**
     * @dataProvider storeProvider
     */
    #[DataProvider('storeProvider')]
    public function testRunsEngineForTheStoreOnThePage(array $params, ?int $storeId, array $redirect): void
    {
        $this->post($params);

        $this->assertSame([[$storeId, false]], $this->runs);
        $this->assertSame(
            ['Recommendations refreshed: 2 new, 5 updated, 1 resolved.'],
            $this->messagesOfType('success')
        );
        $this->assertSame($redirect, $this->redirectParams);
    }

    /**
     * Failed rules keep their open rows in the engine; the admin is told which checks did not run
     */
    public function testFailedChecksAreReportedAsAWarning(): void
    {
        $this->engineErrors = [
            'store 1, rule bot_404: SQLSTATE[HY000] gone away',
            'store 2, rule bot_404: SQLSTATE[HY000] gone away',
            'store 3: <b>no connection</b>',
        ];
        $this->post([]);

        $this->assertCount(1, $this->messagesOfType('success'));
        $warnings = $this->messagesOfType('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString(
            'Some checks could not run: Pages answer client errors (4xx) to AI bots, all checks of a store view.',
            $warnings[0]
        );
        $this->assertStringContainsString('left unchanged', $warnings[0]);
        $this->assertStringNotContainsString('SQLSTATE', $warnings[0]);
        $this->assertCount(3, $this->warnings);
    }

    public function testNoWarningWithoutErrors(): void
    {
        $this->post([]);
        $this->assertSame([], $this->messagesOfType('warning'));
    }

    public static function invalidStoreProvider(): array
    {
        return [
            'letters' => ['abc'],
            'negative' => ['-1'],
            'array' => [['2']],
            'markup' => ['<b>1</b>'],
        ];
    }

    /**
     * @dataProvider invalidStoreProvider
     */
    #[DataProvider('invalidStoreProvider')]
    public function testInvalidStoreIsRejected($store): void
    {
        $this->post(['store' => $store]);

        $this->assertSingleError('store view could not be read');
        $this->assertSame([], $this->runs);
    }

    public function testInvalidFormKeyDoesNotRun(): void
    {
        $this->formKeyValid = false;
        $this->post(['store' => '1']);
        $this->assertSingleError('session has expired');
        $this->assertSame([], $this->runs);
    }

    public function testDisabledDoesNotRun(): void
    {
        $this->post([], false);
        $this->assertSingleError('switched off');
        $this->assertSame([], $this->runs);
    }

    public function testEngineErrors(): void
    {
        $this->engineFailure = new LocalizedException(__('Another refresh is running.'));
        $this->post([]);
        $this->assertSingleError('Another refresh is running.');

        $this->messages = [];
        $this->engineFailure = new \RuntimeException('boom');
        $this->post([]);
        $this->assertSingleError('could not be refreshed');
        $this->assertCount(1, $this->warnings);
    }
}
