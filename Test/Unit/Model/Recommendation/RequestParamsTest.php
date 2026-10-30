<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Model\Recommendation;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Panth\AiVisibility\Model\Recommendation\RequestParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestParamsTest extends TestCase
{
    public static function intProvider(): array
    {
        return [
            'digits' => ['42', 42],
            'int' => [42, 42],
            'padded' => [' 7 ', 7],
            'max length' => ['9999999999', 9999999999],
            'zero' => ['0', null],
            'negative' => ['-1', null],
            'float' => ['1.5', null],
            'exponent' => ['1e3', null],
            'too long' => ['12345678901', null],
            'null' => [null, null],
            'array' => [[1], null],
            'bool' => [true, null],
        ];
    }

    /**
     * @dataProvider intProvider
     */
    #[DataProvider('intProvider')]
    public function testPositiveInt($value, ?int $expected): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturn($value);
        $this->assertSame($expected, RequestParams::positiveInt($request, 'x'));
        $this->assertSame($expected === null ? [] : ['store' => $expected], RequestParams::storeRouteParams($request));
    }

    public function testOpenRecommendation(): void
    {
        $repository = $this->createStub(RecommendationRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(static fn (int $id): ?array => $id === 1
            ? ['rec_id' => 1, 'status' => 'open', 'title' => ' T ']
            : ($id === 2 ? ['rec_id' => 2, 'status' => 'resolved'] : null));

        $this->assertSame('T', RequestParams::openRecommendation($repository, 1)['title']);

        foreach ([2 => 'no longer open', 3 => 'no longer exists'] as $id => $needle) {
            try {
                RequestParams::openRecommendation($repository, $id);
                $this->fail('expected an exception');
            } catch (LocalizedException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
    }
}
