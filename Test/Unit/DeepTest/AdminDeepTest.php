<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\ErrorHandler;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\HealthCheckInterface;
use Panth\AiVisibility\Api\HealthRepositoryInterface;
use Panth\AiVisibility\Block\Adminhtml\Dashboard;
use Panth\AiVisibility\Block\Adminhtml\Discovery;
use Panth\AiVisibility\Controller\Adminhtml\Dashboard\Index as DashboardIndex;
use Panth\AiVisibility\Controller\Adminhtml\Discovery\Classify;
use Panth\AiVisibility\Controller\Adminhtml\Discovery\DeleteRule;
use Panth\AiVisibility\Controller\Adminhtml\Discovery\Index as DiscoveryIndex;
use Panth\AiVisibility\Controller\Adminhtml\Health\Run;
use Panth\AiVisibility\Controller\Adminhtml\Log\Index as LogIndex;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Model\HealthResult;
use Panth\AiVisibility\Model\RuleProvider;
use Panth\AiVisibility\Model\RuleWriter;
use Panth\AiVisibility\Service\BotClassifier;
use Panth\AiVisibility\Service\BotNames;
use Panth\AiVisibility\Service\Discovery\CandidateClassifier;
use Panth\AiVisibility\Service\Discovery\CandidateRepository;
use Panth\AiVisibility\Service\Health\HealthRunner;
use Panth\AiVisibility\Test\Unit\Controller\Adminhtml\BackendContextTrait;
use Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Discovery\ClassifierFixtureTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep test D6: admin security and logic (controllers, ACL, discovery classification, health, config clamping).
 *
 * Tests named "...Fixed..." prove the fix of a defect found in the deep test (they failed on the first build).
 */
class AdminDeepTest extends TestCase
{
    use BackendContextTrait;
    use ClassifierFixtureTrait;

    /**
     * A desktop Chrome on Windows that opened /llms.txt (becomes a discovery candidate)
     */
    private const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/124.0.0.0 Safari/537.36';

    /**
     * Safari on an Intel Mac that opened /llms.txt (seen live as a candidate on the dev store)
     */
    private const SAFARI_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) '
        . 'Version/17.0 Safari/605.1.15';

    private const BOT_UA = 'ExampleCrawler/2.1 (+https://example.invalid/bot)';

    private bool $errorHandlerSet = false;

    protected function tearDown(): void
    {
        if ($this->errorHandlerSet) {
            restore_error_handler();
            $this->errorHandlerSet = false;
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- TC6.01 controllers

    /**
     * @dataProvider controllerProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
    public function testControllerAclResourceAndHttpMethods(string $class, string $resource, bool $postOnly): void
    {
        $this->assertSame($resource, constant($class . '::ADMIN_RESOURCE'));
        $interfaces = class_implements($class);
        $this->assertSame($postOnly, isset($interfaces[HttpPostActionInterface::class]), 'POST interface');
        $this->assertSame(!$postOnly, isset($interfaces[HttpGetActionInterface::class]), 'GET interface');
        $this->assertArrayNotHasKey(CsrfAwareActionInterface::class, $interfaces, 'no CSRF opt-out in admin');

        $this->assertAclResource($this->makeController($class), $resource);
    }

    public static function controllerProvider(): array
    {
        return [
            'dashboard' => [DashboardIndex::class, 'Panth_AiVisibility::dashboard', false],
            'log' => [LogIndex::class, 'Panth_AiVisibility::log', false],
            'discovery' => [DiscoveryIndex::class, 'Panth_AiVisibility::discovery', false],
            'classify' => [Classify::class, 'Panth_AiVisibility::discovery', true],
            'deleteRule' => [DeleteRule::class, 'Panth_AiVisibility::discovery', true],
            'healthRun' => [Run::class, 'Panth_AiVisibility::dashboard', true],
        ];
    }

    public function testInvalidFormKeyStopsEveryPostActionBeforeAnySideEffect(): void
    {
        $this->formKeyValid = false;
        $this->params = ['candidate_id' => '5', 'decision' => 'not_ai', 'rule_id' => '3'];

        $classifier = $this->createMock(CandidateClassifier::class);
        $classifier->expects($this->never())->method('classify');
        $classifier->expects($this->never())->method('deleteRule');
        $runner = $this->createMock(HealthRunner::class);
        $runner->expects($this->never())->method('runAll');
        $logger = $this->createStub(LoggerInterface::class);

        (new Classify($this->backendContext(), $classifier, $logger))->execute();
        $this->assertSame('*/*/index', $this->redirectPath);
        (new DeleteRule($this->backendContext(), $classifier, $logger))->execute();
        $this->assertSame('*/*/index', $this->redirectPath);
        (new Run($this->backendContext(), $runner))->execute();
        $this->assertSame('*/dashboard/index', $this->redirectPath);
        $this->assertCount(3, $this->messagesOfType('error'));
    }

    /**
     * @dataProvider returnStatusProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('returnStatusProvider')]
    public function testClassifyRedirectStaysOnDiscoveryPage(string $returnStatus, array $expectedParams): void
    {
        $classifier = $this->createStub(CandidateClassifier::class);
        $classifier->method('classify')->willReturn(__('ok'));
        $this->params = ['candidate_id' => '5', 'decision' => 'not_ai', 'return_status' => $returnStatus];

        (new Classify($this->backendContext(), $classifier, $this->createStub(LoggerInterface::class)))->execute();

        $this->assertSame('*/*/index', $this->redirectPath);
        $this->assertSame($expectedParams, $this->redirectParams);
    }

    public static function returnStatusProvider(): array
    {
        return [
            'not_ai tab' => ['not_ai', ['status' => 'not_ai']],
            'new tab' => ['new', []],
            'absolute url' => ['https://evil.example.invalid/', []],
            'path traversal' => ['../../admin/user', []],
            'header injection' => ["ignored\r\nLocation: https://evil.example.invalid", []],
        ];
    }

    /**
     * @dataProvider badIdProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badIdProvider')]
    public function testHostileIdsNeverReachTheClassifier($value): void
    {
        $classifier = $this->createMock(CandidateClassifier::class);
        $classifier->expects($this->never())->method('classify');
        $classifier->expects($this->never())->method('deleteRule');
        $logger = $this->createStub(LoggerInterface::class);

        $this->params = ['candidate_id' => $value, 'rule_id' => $value, 'decision' => 'not_ai'];
        (new Classify($this->backendContext(), $classifier, $logger))->execute();
        (new DeleteRule($this->backendContext(), $classifier, $logger))->execute();

        $this->assertCount(2, $this->messagesOfType('error'));
    }

    public static function badIdProvider(): array
    {
        return [
            'array' => [['5']], 'negative' => ['-5'], 'zero' => ['0'], 'float' => ['5.0'], 'exponent' => ['5e1'],
            'hex' => ['0' . 'x5'], 'sql' => ['5 OR 1=1'], 'padded' => [' 5'], 'empty' => [''], 'null' => [null],
        ];
    }

    public function testClassifyRejectsUnknownDecisionBeforeTheClassifier(): void
    {
        $classifier = $this->createMock(CandidateClassifier::class);
        $classifier->expects($this->never())->method('classify');
        foreach (['unknown', 'confirmed', 'CONFIRM', 'training ', 'new'] as $decision) {
            $this->params = ['candidate_id' => '5', 'decision' => $decision];
            (new Classify($this->backendContext(), $classifier, $this->createStub(LoggerInterface::class)))->execute();
        }
        $this->assertCount(5, $this->messagesOfType('error'));
    }

    // ---------------------------------------------------------------- TC6.04 discovery classification

    /**
     * @dataProvider genericBrowserTokenProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('genericBrowserTokenProvider')]
    public function testFixedConfirmRejectsTokensThatMatchEveryBrowser(string $userAgent, string $token): void
    {
        // A human browser that opened /llms.txt is listed as a bot candidate (TrackRequest::isCandidate).
        $this->botCandidate(11, $userAgent);
        try {
            $this->classifier()->classify(11, 'training', ['name' => 'Some Bot', 'token' => $token]);
        } catch (LocalizedException $e) {
            // expected: the token must be rejected
        }
        $this->assertSame(
            [],
            $this->savedRules,
            sprintf('Token "%s" was accepted: every browser User-Agent containing it is now counted as an AI bot.', $token)
        );
    }

    public static function genericBrowserTokenProvider(): array
    {
        return [
            'like (suggested for Chrome)' => [self::CHROME_UA, 'like'],
            'Intel (suggested for Safari)' => [self::SAFARI_UA, 'Intel'],
            'Mozilla/5.0' => [self::CHROME_UA, 'Mozilla/5.0'],
            '5.0' => [self::CHROME_UA, '5.0'],
            'AppleWebKit/537.36' => [self::CHROME_UA, 'AppleWebKit/537.36'],
            'Safari/537.36' => [self::CHROME_UA, 'Safari/537.36'],
            'Windows NT 10.0' => [self::CHROME_UA, 'Windows NT 10.0'],
            'Win' => [self::CHROME_UA, 'Win'],
        ];
    }

    /**
     * @dataProvider browserUaProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('browserUaProvider')]
    public function testFixedSuggestedTokenForAPlainBrowserIsEmpty(string $userAgent): void
    {
        // The Discovery page pre-fills Name and Token with this value, so one click on "Confirm" creates the rule.
        $this->assertSame('', $this->classifier()->suggestToken($userAgent));
    }

    public static function browserUaProvider(): array
    {
        return ['chrome windows' => [self::CHROME_UA], 'safari mac' => [self::SAFARI_UA]];
    }

    public function testSuggestedTokenForARealBotIsItsProductName(): void
    {
        $this->assertSame('ExampleCrawler', $this->classifier()->suggestToken(self::BOT_UA));
    }

    public function testFixedRetryAfterRelabelFailureIsPossible(): void
    {
        // State after "The rule was saved ... but earlier rows could not be moved. Confirm the candidate again to retry."
        $this->botCandidate(12, self::BOT_UA, 'new');
        $this->botRules = [[
            'rule_id' => 9, 'type' => 'bot', 'code' => 'disc_examplecrawler', 'name' => 'ExampleCrawler',
            'match_on' => 'ua', 'pattern' => 'ExampleCrawler', 'category' => 'search', 'company' => 'ExampleCrawler',
            'candidate_id' => 12, 'created_at' => '2026-10-30 00:00:00',
        ]];

        $message = '';
        try {
            $this->classifierWithRealBotClassifier()->classify(
                12,
                'search',
                ['name' => 'ExampleCrawler', 'token' => 'ExampleCrawler']
            );
        } catch (LocalizedException $e) {
            $message = $e->getMessage();
        }
        $this->assertSame('', $message, 'the advertised retry must work');
        $this->assertSame([[12, 'bot', 'disc_examplecrawler', 'search']], $this->relabels);
    }

    public function testRelabelFailureKeepsTheRuleAndExplainsIt(): void
    {
        $this->botCandidate(13, self::BOT_UA);
        $this->relabelFailure = new \RuntimeException('SQLSTATE[40001]: Deadlock found when trying to get lock');
        try {
            $this->classifier()->classify(13, 'live', ['name' => 'ExampleCrawler', 'token' => '']);
            $this->fail('expected LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('The rule was saved', $e->getMessage());
            // Raw database error text is shown to the admin (information only, admin area).
            $this->assertStringContainsString('SQLSTATE[40001]', $e->getMessage());
        }
        $this->assertCount(1, $this->savedRules);
        $this->assertSame([], $this->statusChanges, 'candidate stays new');
    }

    public function testConfirmUsesPrefixedUniqueCodesEvenForBuiltInNames(): void
    {
        $this->botCandidate(14, self::BOT_UA);
        $this->botRules = [$this->rule(1, 'bot', 'disc_gptbot', 'OtherToken', 99)];
        $this->classifier()->classify(14, 'training', ['name' => 'GPTBot', 'token' => 'ExampleCrawler']);
        $this->assertSame('disc_gptbot_2', $this->savedRules[0]['code']);
        $this->assertSame([[14, 'confirmed']], $this->statusChanges);
    }

    // ---------------------------------------------------------------- robustness of request parameters

    public function testFixedClassifyWithArrayReturnStatusDoesNotThrow(): void
    {
        $this->useMagentoErrorHandler();
        $classifier = $this->createStub(CandidateClassifier::class);
        $classifier->method('classify')->willReturn(__('ok'));
        $this->params = ['candidate_id' => '5', 'decision' => 'not_ai', 'return_status' => ['not_ai']];

        (new Classify($this->backendContext(), $classifier, $this->createStub(LoggerInterface::class)))->execute();

        $this->assertSame('*/*/index', $this->redirectPath);
    }

    public function testFixedDiscoveryPageWithArrayStatusParamDoesNotThrow(): void
    {
        $this->useMagentoErrorHandler();
        $block = $this->blockWithRequest(Discovery::class, ['status' => ['not_ai']]);
        $this->assertSame('new', $block->getCurrentStatus());
    }

    public function testFixedDashboardWithArraySimParamDoesNotThrow(): void
    {
        $this->useMagentoErrorHandler();
        $block = $this->blockWithRequest(Dashboard::class, ['sim' => ['1']]);
        $this->assertFalse($block->includeSimulated());
    }

    public function testDiscoveryStatusTabIgnoresHostileValues(): void
    {
        foreach (['confirmed', '<script>', "new' OR 1=1", 'NOT_AI'] as $value) {
            $this->assertSame('new', $this->blockWithRequest(Discovery::class, ['status' => $value])->getCurrentStatus());
        }
        $this->assertSame('ignored', $this->blockWithRequest(Discovery::class, ['status' => 'ignored'])->getCurrentStatus());
    }

    // ---------------------------------------------------------------- TC6.05 health

    public function testHealthRunnerContinuesWhenChecksThrowOrMisbehave(): void
    {
        $saved = [];
        $repository = $this->createStub(HealthRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function (string $code, HealthResult $r) use (&$saved): void {
            if ($code === 'second') {
                throw new \RuntimeException('table locked');
            }
            $saved[$code] = $r->status;
        });
        $warnings = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($m) use (&$warnings): void {
            $warnings[] = (string)$m;
        });
        $runner = new HealthRunner($repository, $logger, [
            'first' => $this->check('first', fn () => new HealthResult('ok', 'fine')),
            'second' => $this->check('second', fn () => throw new \TypeError('boom')),
            'third' => $this->check('third', fn () => new HealthResult('green', 'odd status')),
            'fourth' => $this->check('', fn () => new HealthResult('warn', 'named by key')),
            'notACheck' => new \stdClass(),
        ]);

        $results = $runner->runAll();

        $this->assertSame(['first', 'second', 'third', 'fourth'], array_keys($results));
        $this->assertSame('ok', $results['first']->status);
        $this->assertSame('unknown', $results['second']->status);
        $this->assertStringContainsString('boom', $results['second']->message);
        $this->assertSame('unknown', $results['third']->status);
        $this->assertSame('warn', $results['fourth']->status);
        $this->assertSame(['first' => 'ok', 'third' => 'unknown', 'fourth' => 'warn'], $saved);
        $this->assertCount(2, $warnings, 'one failed check and one failed save are logged');
    }

    public function testHealthRunControllerSummarisesAndReportsFailures(): void
    {
        $runner = $this->createStub(HealthRunner::class);
        $runner->method('runAll')->willReturn([
            'a' => new HealthResult('ok', ''), 'b' => new HealthResult('error', ''), 'c' => new HealthResult('unknown', ''),
        ]);
        (new Run($this->backendContext(), $runner))->execute();
        $this->assertSame(
            ['Health checks finished: 1 OK, 0 warning(s), 1 error(s), 1 unknown.'],
            $this->messagesOfType('warning')
        );

        $this->messages = [];
        $failing = $this->createStub(HealthRunner::class);
        $failing->method('runAll')->willThrowException(new \RuntimeException('SQLSTATE[HY000] gone away'));
        (new Run($this->backendContext(), $failing))->execute();
        $this->assertSame(['The health checks could not run: SQLSTATE[HY000] gone away'], $this->messagesOfType('error'));
        $this->assertSame('*/dashboard/index', $this->redirectPath);
    }

    // ---------------------------------------------------------------- TC6.06 config clamping

    /**
     * @dataProvider clampProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('clampProvider')]
    public function testConfigClampsEveryNumericField(string $method, string $path, $raw, int $expected): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($p) => $p === $path ? $raw : null);
        $config = new Config($scope, $this->createStub(StoreManagerInterface::class));
        $this->assertSame($expected, $config->$method());
    }

    public static function clampProvider(): array
    {
        $cases = [];
        $fields = [
            'attributionWindowDays' => [Config::XML_PATH_ATTRIBUTION_WINDOW_DAYS, 30, 1, 365],
            'rawDailyCap' => [Config::XML_PATH_RAW_DAILY_CAP, 200000, 1000, 10000000],
            'rateLimitPerMinute' => [Config::XML_PATH_RATE_LIMIT_PER_MINUTE, 20, 1, 600],
            'landingSampleRate' => [Config::XML_PATH_LANDING_SAMPLE_RATE, 25, 0, 100],
            'hitRetentionDays' => [Config::XML_PATH_HIT_RETENTION_DAYS, 14, 1, 365],
            'rawVisitRetentionDays' => [Config::XML_PATH_RAW_VISIT_RETENTION_DAYS, 30, 1, 730],
            'landingRetentionDays' => [Config::XML_PATH_LANDING_RETENTION_DAYS, 30, 1, 365],
            'dailyRetentionMonths' => [Config::XML_PATH_DAILY_RETENTION_MONTHS, 13, 1, 60],
            'logImportMaxLines' => [Config::XML_PATH_LOG_IMPORT_MAX_LINES, 200000, 1000, 5000000],
        ];
        foreach ($fields as $method => [$path, $default, $min, $max]) {
            $cases["$method unset"] = [$method, $path, null, $default];
            $cases["$method text"] = [$method, $path, 'abc', $default];
            $cases["$method exponent"] = [$method, $path, '1e3', $default];
            $cases["$method decimal"] = [$method, $path, '12.5', $default];
            $cases["$method negative"] = [$method, $path, '-7', $min];
            $cases["$method below"] = [$method, $path, (string)($min - 1), $min];
            $cases["$method above"] = [$method, $path, (string)($max + 1), $max];
            $cases["$method huge"] = [$method, $path, '99999999999999999999', $default];
            $cases["$method padded"] = [$method, $path, ' ' . $max . ' ', $max];
        }
        return $cases;
    }

    // ---------------------------------------------------------------- helpers

    private function makeController(string $class): object
    {
        $context = $this->backendContext();
        switch ($class) {
            case Classify::class:
            case DeleteRule::class:
                return new $class(
                    $context,
                    $this->createStub(CandidateClassifier::class),
                    $this->createStub(LoggerInterface::class)
                );
            case Run::class:
                return new Run($context, $this->createStub(HealthRunner::class));
            default:
                return new $class($context, $this->createStub(PageFactory::class));
        }
    }

    private function classifierWithRealBotClassifier(): CandidateClassifier
    {
        $candidates = $this->createStub(CandidateRepository::class);
        $candidates->method('get')->willReturnCallback(fn (int $id) => $this->candidateRows[$id] ?? null);
        $candidates->method('setStatus')->willReturnCallback(function (int $id, string $status): void {
            $this->statusChanges[] = [$id, $status];
        });
        $ruleProvider = $this->createStub(RuleProvider::class);
        $ruleProvider->method('botRules')->willReturnCallback(fn () => $this->botRules);
        $ruleProvider->method('sourceRules')->willReturnCallback(fn () => $this->sourceRules);
        $ruleWriter = $this->createStub(RuleWriter::class);
        $ruleWriter->method('save')->willReturnCallback(function (array $rule): int {
            $this->savedRules[] = $rule;
            return 9;
        });
        $relabeler = $this->createStub(\Panth\AiVisibility\Api\RelabelerInterface::class);
        $relabeler->method('relabelCandidate')->willReturnCallback(
            function (int $id, string $type, string $code, ?string $category = null): int {
                $this->relabels[] = [$id, $type, $code, $category];
                return 2;
            }
        );
        $botClassifier = new BotClassifier($this->createStub(Config::class), $ruleProvider);
        return new CandidateClassifier(
            $candidates,
            $ruleProvider,
            $ruleWriter,
            $relabeler,
            $botClassifier,
            $this->createStub(\Panth\AiVisibility\Api\SourceClassifierInterface::class),
            new BotNames($botClassifier)
        );
    }

    private function check(string $code, callable $run): HealthCheckInterface
    {
        $check = $this->createStub(HealthCheckInterface::class);
        $check->method('code')->willReturn($code);
        $check->method('label')->willReturn($code);
        $check->method('run')->willReturnCallback($run);
        return $check;
    }

    private function blockWithRequest(string $class, array $params): object
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($key, $default = null) => $params[$key] ?? $default);
        $block = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        \Closure::bind(function (RequestInterface $request): void {
            $this->_request = $request;
        }, $block, \Magento\Framework\View\Element\AbstractBlock::class)($request);
        return $block;
    }

    private function useMagentoErrorHandler(): void
    {
        // Magento's bootstrap installs this handler: PHP warnings become exceptions (an error page for the admin).
        set_error_handler([new ErrorHandler(), 'handler']);
        $this->errorHandlerSet = true;
    }
}
