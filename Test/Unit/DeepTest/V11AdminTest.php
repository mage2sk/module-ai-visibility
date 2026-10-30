<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AiVisibility\Api\RecommendationEngineInterface;
use Panth\AiVisibility\Api\RecommendationRepositoryInterface;
use Panth\AiVisibility\Block\Adminhtml\Recommendations;
use Panth\AiVisibility\Controller\Adminhtml\Dashboard\Export;
use Panth\AiVisibility\Controller\Adminhtml\Recommendation\Apply;
use Panth\AiVisibility\Controller\Adminhtml\Recommendation\Dismiss;
use Panth\AiVisibility\Controller\Adminhtml\Recommendation\Feedback;
use Panth\AiVisibility\Controller\Adminhtml\Recommendation\Refresh;
use Panth\AiVisibility\Controller\Adminhtml\Recommendation\Undo;
use Panth\AiVisibility\Model\Config;
use Panth\AiVisibility\Service\Export\CsvWriter;
use Panth\AiVisibility\Service\Export\ReportExporter;
use Panth\AiVisibility\Service\Recommendation\ActionLog;
use Panth\AiVisibility\Service\Report\DateRange;
use Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Recommendation\RecommendationContextTrait;
use Panth\AiVisibility\Test\Unit\Controller\Adminhtml\Recommendation\RepositoryFixtureTrait;
use Panth\AiVisibility\ViewModel\Recommendation\FixLinkResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep test E3 (1.1 admin security and UI logic).
 *
 * Expected to FAIL until fixed (each proves a defect):
 * - testUndoRefusesAFinishedAction: ActionLog::undo() (and so the Undo controller) undoes verified /
 *   insufficient actions; only the page hides the button.
 * - testSecondApplyAfterAStaleReadIsRefused: ActionLog::apply() checks the status with a plain read and
 *   then updates it unconditionally, so a double submit can log two actions (reproduced live: 2 rows).
 * - testRecommendationExportsNeedTheRecommendationsAcl: Dashboard/Export serves the recommendations and
 *   verdicts reports (titles, admin usernames, notes) with only Panth_AiVisibility::dashboard, while the
 *   dashboard cards hide the same data from such a role.
 */
class V11AdminTest extends TestCase
{
    use RecommendationContextTrait;
    use RepositoryFixtureTrait;

    private const MODULE_DIR = __DIR__ . '/../../../';

    private const HOSTILE = '<script>alert(1)</script>"\'><img src=x onerror=alert(1)>';

    // ---------------------------------------------------------------- action log fixture

    private array $logInserts = [];

    private array $logUpdates = [];

    private array $logStatusCalls = [];

    private string $recStatus = 'open';

    private int $getCalls = 0;

    /**
     * Action log over a tiny stateful fake: the first $staleReads repository reads return the
     * recommendation as "open" (a read taken before another request committed); later reads, every
     * connection read and every conditional update see the real state.
     *
     * @param array $actionRow Row returned for the action lookup in undo()
     * @param int $staleReads
     * @return ActionLog
     */
    private function actionLogService(array $actionRow = [], int $staleReads = 0): ActionLog
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'joinLeft', 'join', 'order', 'limit', 'forUpdate', 'columns'] as $method) {
            $select->method($method)->willReturn($select);
        }
        $connection = $this->createStub(Mysql::class);
        $connection->method('select')->willReturn($select);
        $connection->method('insert')->willReturnCallback(function (string $table, array $row): int {
            $this->logInserts[] = [$table, $row];
            return 1;
        });
        $connection->method('update')->willReturnCallback(function (string $table, array $data, $where = ''): int {
            $this->logUpdates[] = [$table, $data, $where];
            if ($table === RecommendationRepositoryInterface::TABLE && isset($data['status'])) {
                $guarded = is_array($where) ? in_array('open', $where, true) : str_contains((string)$where, "'open'");
                if ($guarded && $this->recStatus !== 'open') {
                    return 0;
                }
                $this->recStatus = (string)$data['status'];
            }
            return 1;
        });
        $connection->method('lastInsertId')->willReturnCallback(fn () => (string)(20 + count($this->logInserts)));
        $connection->method('fetchRow')->willReturnCallback(
            fn () => $actionRow !== [] ? $actionRow : ['rec_id' => 7, 'status' => $this->recStatus]
        );
        $connection->method('fetchOne')->willReturnCallback(fn () => $this->recStatus);
        $connection->method('fetchAll')->willReturn([]);
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $connection->method($method)->willReturn($connection);
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $n): string => $n);

        $repo = $this->createStub(RecommendationRepositoryInterface::class);
        $repo->method('get')->willReturnCallback(function (int $id) use ($staleReads): ?array {
            $this->getCalls++;
            $status = $this->getCalls <= $staleReads ? 'open' : $this->recStatus;
            return [
                'rec_id' => $id, 'rule_code' => 'llms_not_served', 'store_id' => 1, 'subject_type' => 'site',
                'subject_ref' => 'site', 'metric' => 'llms_fetches', 'status' => $status,
                'evidence' => ['subjects' => ['site']], 'is_simulated' => 0, 'title' => 'llms.txt',
            ];
        });
        $repo->method('setStatus')->willReturnCallback(function (int $id, string $status): void {
            $this->logStatusCalls[] = [$id, $status];
            $this->recStatus = $status;
        });
        $config = $this->createStub(Config::class);
        $config->method('verifyWindowDays')->willReturn(14);
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(gmmktime(12, 0, 0, 10, 30, 2026));

        return new ActionLog($resource, $repo, $config, $clock, new Json(), $this->createStub(LoggerInterface::class));
    }

    /**
     * Statuses of a finished (already measured) action
     */
    public static function finishedStatusProvider(): array
    {
        return ['verified' => ['verified'], 'insufficient' => ['insufficient']];
    }

    /**
     * Undo is for "I reverted the change" while the action is still pending. Live (2026-10-30): undo of a
     * verified action with an impact row was ACCEPTED, the verdict vanished from the cards and the
     * recommendation was reopened.
     *
     * @dataProvider finishedStatusProvider
     */
    #[DataProvider('finishedStatusProvider')]
    public function testUndoRefusesAFinishedAction(string $status): void
    {
        $this->recStatus = 'applied';
        $log = $this->actionLogService(['action_id' => 9, 'rec_id' => 7, 'status' => $status, 'undone_at' => null]);
        $refused = false;
        try {
            $log->undo(9);
        } catch (LocalizedException $e) {
            $refused = true;
        }
        $this->assertTrue($refused, "undo() of a $status action must be refused");
        $this->assertSame([], $this->logUpdates, 'nothing may be written');
        $this->assertSame([], $this->logStatusCalls, 'the recommendation must not be reopened');
    }

    public function testUndoOfAPendingActionStillWorks(): void
    {
        $this->recStatus = 'applied';
        $this->actionLogService(['action_id' => 9, 'rec_id' => 7, 'status' => 'pending', 'undone_at' => null])->undo(9);
        $this->assertSame('undone', $this->logUpdates[0][1]['status']);
        $this->assertSame([[7, 'open']], $this->logStatusCalls);
    }

    public function testPageOffersUndoOnlyForPendingActions(): void
    {
        $block = (new \ReflectionClass(Recommendations::class))->newInstanceWithoutConstructor();
        foreach (['pending' => true, 'verified' => false, 'insufficient' => false, 'undone' => false] as $status => $can) {
            $this->assertSame($can, $block->canUndo(['status' => $status]), $status);
        }
    }

    /**
     * Double submit of "Confirm: I applied this fix": the second request read the recommendation as open
     * before the first committed. Live reproduction with two processes 3 ms apart logged 2 actions for
     * one recommendation; 0-5 ms apart gave a deadlock error shown as "The action could not be saved".
     */
    public function testSecondApplyAfterAStaleReadIsRefused(): void
    {
        $log = $this->actionLogService([], 2);
        $log->apply(24, null, 'admin');
        $second = null;
        try {
            $second = $log->apply(24, null, 'admin');
        } catch (LocalizedException $e) {
            $second = 'refused';
        }
        $this->assertSame('refused', $second, 'the second apply must be refused');
        $this->assertCount(1, $this->logInserts, 'exactly one action row');
    }

    // ---------------------------------------------------------------- export ACL

    private array $exportCalls = [];

    private ?string $exportContents = null;

    private function exportController(array $params, array $denied): Export
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($key, $default = null) => $params[$key] ?? $default);
        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturn($messages);
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturn($redirect);
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(fn ($resource): bool => !in_array($resource, $denied, true));
        $validator = $this->createStub(Validator::class);
        $validator->method('validate')->willReturn(true);
        $backendUrl = $this->createStub(BackendUrl::class);
        $backendUrl->method('useSecretKey')->willReturn(true);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getAuthorization')->willReturn($authorization);
        $context->method('getFormKeyValidator')->willReturn($validator);
        $context->method('getBackendUrl')->willReturn($backendUrl);

        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturn($raw);
        $raw->method('setHttpResponseCode')->willReturn($raw);
        $raw->method('setContents')->willReturnCallback(function ($contents) use ($raw) {
            $this->exportContents = $contents;
            return $raw;
        });
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);
        $exporter = $this->createStub(ReportExporter::class);
        $exporter->method('export')->willReturnCallback(function (string $report): array {
            $this->exportCalls[] = $report;
            return ['headers' => ['applied_by', 'note'], 'rows' => [['store_admin', 'private note']]];
        });
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([]);
        return new Export(
            $context,
            $rawFactory,
            $exporter,
            new CsvWriter(),
            $storeManager,
            $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * Reports that carry recommendation data
     */
    public static function recommendationReportProvider(): array
    {
        return ['recommendations' => ['recommendations'], 'verdicts' => ['verdicts']];
    }

    /**
     * A role with Dashboard but without Recommendations must not download recommendation data
     * (the Top recommendations / Latest verdicts cards already hide it from that role).
     *
     * @dataProvider recommendationReportProvider
     */
    #[DataProvider('recommendationReportProvider')]
    public function testRecommendationExportsNeedTheRecommendationsAcl(string $report): void
    {
        $this->exportController(['report' => $report], ['Panth_AiVisibility::recommendations'])->execute();
        $this->assertSame([], $this->exportCalls, "the $report report must not be built");
        $this->assertNull($this->exportContents, 'no CSV may be sent');
    }

    public function testTrafficReportsNeedOnlyTheDashboardAcl(): void
    {
        foreach (['daily', 'pages', 'bots', 'sources'] as $report) {
            $this->exportController(['report' => $report], ['Panth_AiVisibility::recommendations'])->execute();
        }
        $this->assertSame(['daily', 'pages', 'bots', 'sources'], $this->exportCalls);
    }

    // ---------------------------------------------------------------- CSV formula injection

    /**
     * Cell value => expected CSV field
     */
    public static function csvCellProvider(): array
    {
        return [
            'equals' => ['=HYPERLINK("http://example.invalid","x")', '"\'=HYPERLINK(""http://example.invalid"",""x"")"'],
            'plus' => ['+1+1', "'+1+1"],
            'minus' => ['-2+3', "'-2+3"],
            'at' => ['@SUM(1)', "'@SUM(1)"],
            'tab' => ["\t=1+1", "'\t=1+1"],
            'cr' => ["\r=1+1", "\"'\r=1+1\""],
            'dde' => ["=cmd|' /C calc'!A0", "'=cmd|' /C calc'!A0"],
            'text minus number' => ['-12.5', "'-12.5"],
            'int negative' => [-12, '-12'],
            'float negative' => [-12.5, '-12.5'],
            'float zero negative' => [-0.0, '0'],
            'html stays text' => [self::HOSTILE, '"<script>alert(1)</script>""\'><img src=x onerror=alert(1)>"'],
            'path' => ['/a,b', '"/a,b"'],
            'null' => [null, ''],
        ];
    }

    /**
     * Formula cells are neutralised; real numbers (int/float, also negative) are never prefixed. A text cell
     * "-12.5" (e.g. a merchant note) becomes '-12.5: shown as text, which is acceptable (OWASP advice).
     *
     * @dataProvider csvCellProvider
     */
    #[DataProvider('csvCellProvider')]
    public function testCsvCells($value, string $expected): void
    {
        $this->assertSame($expected . "\r\n", (new CsvWriter())->line([$value]));
    }

    public function testExporterCastsNumericColumnsSoNoNumberIsPrefixed(): void
    {
        $source = (string)file_get_contents(self::MODULE_DIR . 'Service/Export/ReportExporter.php');
        foreach (['effect', 'ci_low', 'ci_high'] as $column) {
            $this->assertStringContainsString('$this->decimal($row[\'' . $column . '\'])', $source);
        }
        $this->assertStringContainsString('(int)$row[\'impact_score\']', $source);
    }

    // ---------------------------------------------------------------- "How to fix" links

    private array $urls = [];

    private function fixLinks(array $disabledModules = [], array $denied = []): FixLinkResolver
    {
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturnCallback(fn ($m) => !in_array($m, $disabledModules, true));
        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturnCallback(function ($route = null, $params = []) {
            $this->urls[] = [$route, $params ?? []];
            return 'https://admin.example.invalid/' . $route;
        });
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(fn ($r) => !in_array($r, $denied, true));
        return new FixLinkResolver($modules, $url, $auth);
    }

    /**
     * Rule codes, also unknown and hostile ones
     */
    public static function ruleCodeProvider(): array
    {
        $codes = [
            'robots_blocks_ai_search', 'bot_404', 'bot_errors_or_slow', 'never_crawled_products',
            'live_answers_no_sales', 'thin_content_ai_read', 'llms_not_served', 'parameter_crawl_waste',
            'ai_visitors_out_of_stock', 'discovery_backlog', 'unknown_rule', '<b>x</b>', '../../admin',
        ];
        return array_combine($codes, array_map(static fn ($c) => [$c], $codes));
    }

    /**
     * Links only to the allowed admin routes (all verified to exist live, with matching ACL resources),
     * with no parameter other than an integer product id taken from digit-only subjects.
     *
     * @dataProvider ruleCodeProvider
     */
    #[DataProvider('ruleCodeProvider')]
    public function testFixLinksOnlyUseAllowedRoutes(string $ruleCode): void
    {
        $allowed = [
            'adminhtml/system_config/edit/section/panth_robots_seo', 'panth_redirects/redirect/index',
            'adminhtml/url_rewrite/index', 'panth_aivisibility/log/index',
            'adminhtml/system_config/edit/section/panth_llms_txt', 'panth_aivisibility/discovery/index',
            'catalog/product/edit',
        ];
        $link = $this->fixLinks()->resolve([
            'rule_code' => $ruleCode, 'subject_type' => 'product', 'subject_ref' => 'javascript:alert(1)',
            'evidence' => ['subjects' => ['1 onmouseover=x', '"javascript:x', self::HOSTILE, '-3', '0', '7']],
        ]);
        if ($link === null) {
            $this->assertSame([], $this->urls);
            $this->assertContains($ruleCode, ['live_answers_no_sales', 'unknown_rule', '<b>x</b>', '../../admin']);
            return;
        }
        $this->assertCount(1, $this->urls);
        [$route, $params] = $this->urls[0];
        $this->assertContains($route, $allowed);
        if ($route === 'catalog/product/edit') {
            $this->assertSame(['id' => 7], $params);
            $this->assertSame('Edit product ID 7', $link['label']);
        } else {
            $this->assertSame([], $params);
        }
    }

    public function testFixLinkFallsBackOrDisappearsWithoutModuleOrPermission(): void
    {
        $this->assertSame(
            'https://admin.example.invalid/adminhtml/url_rewrite/index',
            $this->fixLinks(['Panth_Redirects'])->resolve(['rule_code' => 'bot_404'])['url']
        );
        $this->assertNull($this->fixLinks([], ['Panth_Redirects::redirects', 'Magento_UrlRewrite::urlrewrite'])
            ->resolve(['rule_code' => 'bot_404']));
        $this->assertNull($this->fixLinks([], ['Magento_Catalog::products'])->resolve([
            'rule_code' => 'thin_content_ai_read', 'subject_type' => 'product', 'evidence' => ['subjects' => ['7']],
        ]));
        $this->assertNull($this->fixLinks()->resolve([
            'rule_code' => 'thin_content_ai_read', 'subject_type' => 'product',
            'evidence' => ['subjects' => ['x', self::HOSTILE]], 'subject_ref' => 'products',
        ]));
    }

    // ---------------------------------------------------------------- redirects / scalar params

    /**
     * Hostile values for the store / redirect parameters
     */
    public static function hostileStoreProvider(): array
    {
        return [
            'absolute url' => ['https://evil.example.invalid/'],
            'protocol relative' => ['//evil.example.invalid'],
            'traversal' => ['1/../../admin'],
            'array' => [['1']],
            'negative' => ['-1'],
            'html' => [self::HOSTILE],
        ];
    }

    /**
     * Every POST action redirects to its own index page with at most an integer store id, whatever
     * store / referer / back_url / redirect params are posted (no open redirect).
     *
     * @dataProvider hostileStoreProvider
     */
    #[DataProvider('hostileStoreProvider')]
    public function testPostActionsNeverRedirectOffSite($store): void
    {
        $engine = $this->createStub(RecommendationEngineInterface::class);
        $engine->method('run')->willReturn(['created' => 0, 'updated' => 0, 'resolved' => 0]);
        $this->openRec(5);
        $controllers = [
            fn () => new Apply($this->context(), $this->actionLog(), $this->repository(), $this->config(), $this->logger()),
            fn () => new Dismiss($this->context(), $this->repository(), $this->logger()),
            fn () => new Feedback($this->context(), $this->repository(), $this->logger()),
            fn () => new Undo($this->context(), $this->actionLog(), $this->logger()),
            fn () => new Refresh($this->context(), $engine, $this->config(), $this->logger()),
        ];
        foreach ($controllers as $make) {
            $this->params = [
                'rec_id' => '5', 'action_id' => '5', 'value' => '1', 'store' => $store,
                'referer' => base64_encode('https://evil.example.invalid/'), 'back_url' => 'https://evil.example.invalid/',
                'redirect' => '//evil.example.invalid', 'uenc' => base64_encode('https://evil.example.invalid/'),
            ];
            $this->redirectPath = null;
            $this->redirectParams = ['unset'];
            $make()->execute();
            $this->assertSame('*/*/index', $this->redirectPath);
            $this->assertSame([], $this->redirectParams);
        }
    }

    public function testApplyRejectsNonScalarNoteAndKeepsHostileTextForEscapingLater(): void
    {
        $this->openRec(5, 'open', self::HOSTILE);
        $this->params = ['rec_id' => '5', 'note' => ['x']];
        (new Apply($this->context(), $this->actionLog(), $this->repository(), $this->config(), $this->logger()))->execute();
        $this->assertSame([], $this->applied);

        $this->messages = [];
        $this->params = ['rec_id' => '5', 'note' => self::HOSTILE];
        (new Apply($this->context(), $this->actionLog(), $this->repository(), $this->config(), $this->logger()))->execute();
        $this->assertSame([[5, self::HOSTILE, 'store_admin']], $this->applied);
        // The success message carries the raw title; Magento's default message renderer escapes it.
        $this->assertStringContainsString(self::HOSTILE, $this->messagesOfType('success')[0]);
    }

    // ---------------------------------------------------------------- template escaping (static)

    /**
     * Templates of the 1.1 admin UI
     */
    public static function templateProvider(): array
    {
        return [
            'page' => ['view/adminhtml/templates/recommendation/index.phtml'],
            'card' => ['view/adminhtml/templates/recommendation/card.phtml'],
            'verdicts card' => ['view/adminhtml/templates/recommendation/verdicts_card.phtml'],
            'export links' => ['view/adminhtml/templates/dashboard/export_links.phtml'],
        ];
    }

    /**
     * Every echo is escaped, an int cast, or one of the two reviewed @noEscape values (the hidden store
     * field built from an int, and the SVG whose text BeforeAfterBars escapes itself).
     *
     * @dataProvider templateProvider
     */
    #[DataProvider('templateProvider')]
    public function testEveryEchoIsEscaped(string $file): void
    {
        $source = (string)file_get_contents(self::MODULE_DIR . $file);
        preg_match_all('/<\?=\s*(.*?)\?>/s', $source, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $echo) {
            $echo = trim($echo);
            $ok = preg_match('/^\$escaper->escape(Html|HtmlAttr|Url|Js)\(/', $echo)
                || preg_match('/^\(int\)\s*\$[A-Za-z_]+(\s*\+\s*1)?$/', $echo)
                || preg_match('/^\(int\)\s*\\\\?Panth\\\\AiVisibility\\\\Api\\\\ActionLogInterface::MAX_NOTE$/', $echo)
                || preg_match('/^\$[a-zA-Z]+ === .* \? \'[^\']*\' : \'[^\']*\'$/s', $echo)
                || in_array($echo, ['/* @noEscape */ $storeField', '/* @noEscape */ $block->getBarsSvg($action)'], true);
            $this->assertTrue((bool)$ok, "unescaped echo in $file: $echo");
        }
    }
}
