<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Insights;

use Panth\AiVisibility\Service\BotClassifier;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Service\Insights\ErrorRateInsight;
use Panth\AiVisibility\Service\Insights\InsightProvider;
use Panth\AiVisibility\Service\Report\BotDirectory;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Service\Report\ReportRepository;
use PHPUnit\Framework\TestCase;

class ErrorRateInsightTest extends TestCase
{
    /**
     * @dataProvider rowProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rowProvider')]
    public function testThreshold(int $hits, int $errors, bool $warns): void
    {
        $result = $this->insight([$this->row('gptbot', $hits, $errors)])
            ->collect(new DateRange('2026-10-01', '2026-10-30', '30'), 0, false);
        $this->assertCount($warns ? 1 : 0, $result);
        if ($warns) {
            $this->assertSame(InsightProvider::LEVEL_WARNING, $result[0]['level']);
            $this->assertStringContainsString('GPTBot', $result[0]['title']);
        }
    }

    public static function rowProvider(): array
    {
        return [
            'no errors' => [1000, 0, false],
            'exactly 1%' => [1000, 10, false],
            'just above 1%' => [1000, 11, true],
            'tiny sample' => [10, 5, false],
            'minimum sample' => [20, 1, true],
            'all failing' => [500, 500, true],
        ];
    }

    public function testOneWarningPerBotAndUnknownCodesUseTheCode(): void
    {
        $result = $this->insight([
            $this->row('gptbot', 100, 5),
            $this->row('disc_newbot', 100, 3),
            $this->row('claudebot', 100, 0),
        ])->collect(new DateRange('2026-10-01', '2026-10-30', '30'), 0, false);
        $this->assertCount(2, $result);
        $this->assertStringContainsString('disc_newbot', $result[1]['text']);
        $this->assertStringContainsString('3.0%', $result[1]['text']);
    }

    /**
     * Bot stats row
     *
     * @param string $code
     * @param int $hits
     * @param int $errors
     * @return array
     */
    private function row(string $code, int $hits, int $errors): array
    {
        return [
            'bot_code' => $code, 'category' => 'training', 'hits' => $hits,
            's4xx' => 0, 's5xx' => $errors, 'total_ms' => 0,
        ];
    }

    /**
     * Insight with stubbed bot stats
     *
     * @param array $rows
     * @return ErrorRateInsight
     */
    private function insight(array $rows): ErrorRateInsight
    {
        $repository = $this->createStub(ReportRepository::class);
        $repository->method('botStats')->willReturn($rows);
        $rules = $this->createStub(RuleProvider::class);
        $rules->method('botRules')->willReturn([]);
        $config = $this->createStub(Config::class);
        $config->method('customBotPatterns')->willReturn([]);
        return new ErrorRateInsight($repository, new BotDirectory(new BotClassifier($config, $rules)));
    }
}
