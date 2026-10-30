<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Event\ObserverInterface;
use Panth\AiVisibility\Api\HealthCheckInterface;
use PHPUnit\Framework\TestCase;

/**
 * Deep test D8 (compatibility, quality, install hygiene).
 *
 * Static checks over the module's own files: DI wiring, cron/observer/controller/ACL wiring, config paths,
 * declared dependency types and the methods called on them (run on 2.4.7 + PHP 8.1 this doubles as an API
 * check), release metadata and documentation. Tests named in the D8 report prove the fix of a defect (they failed on the first build).
 */
class QualityDeepTest extends TestCase
{
    private const NS = 'Panth\\AiVisibility\\';

    /**
     * Module root
     *
     * @return string
     */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Load a module XML file
     *
     * @param string $relative
     * @return \DOMXPath
     */
    private static function xpath(string $relative): \DOMXPath
    {
        $dom = new \DOMDocument();
        $dom->load(self::root() . '/' . $relative);
        return new \DOMXPath($dom);
    }

    /**
     * Relative paths of the module's production PHP/PHTML files
     *
     * @param string[] $suffixes
     * @return string[]
     */
    private static function sourceFiles(array $suffixes = ['.php']): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(self::root()) + 1));
            if (str_starts_with($relative, 'Test/') || str_starts_with($relative, '.git/')) {
                continue;
            }
            foreach ($suffixes as $suffix) {
                if (str_ends_with($relative, $suffix)) {
                    $files[] = $relative;
                    break;
                }
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Module class name of a relative PHP path, or null for non-class files
     *
     * @param string $relative
     * @return string|null
     */
    private static function classOf(string $relative): ?string
    {
        if (!preg_match('#^[A-Z][A-Za-z0-9]*/.+\.php$#', $relative)) {
            return null;
        }
        return self::NS . str_replace('/', '\\', substr($relative, 0, -4));
    }

    /**
     * Whether a class/interface (or the base of a generated Proxy/Factory) exists
     *
     * @param string $type
     * @return bool
     */
    private static function typeExists(string $type): bool
    {
        $type = ltrim($type, '\\');
        if (class_exists($type) || interface_exists($type)) {
            return true;
        }
        foreach (['\\Proxy', 'Factory'] as $suffix) {
            if (str_ends_with($type, $suffix)) {
                $base = substr($type, 0, -strlen($suffix));
                return class_exists($base) || interface_exists($base);
            }
        }
        return false;
    }

    /**
     * DI files of the module
     *
     * @return array
     */
    public static function diFiles(): array
    {
        return [
            'global' => ['etc/di.xml'],
            'adminhtml' => ['etc/adminhtml/di.xml'],
        ];
    }

    /**
     * Every <argument name> bound in di.xml exists on the target constructor; object values resolve
     *
     * @dataProvider diFiles
     * @param string $file
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('diFiles')]
    public function testDiArgumentNamesMatchConstructors(string $file): void
    {
        $xpath = self::xpath($file);
        $virtual = [];
        foreach ($xpath->query('//virtualType') as $node) {
            $virtual[$node->getAttribute('name')] = ltrim($node->getAttribute('type'), '\\');
        }
        $problems = [];
        foreach ($xpath->query('//type|//virtualType') as $node) {
            $name = $node->getAttribute('name');
            $class = $node->nodeName === 'virtualType' ? $node->getAttribute('type') : $name;
            $class = ltrim($class, '\\');
            if (!self::typeExists($class)) {
                $problems[] = "$name: class $class does not exist";
                continue;
            }
            $ctor = (new \ReflectionClass($class))->getConstructor();
            $params = $ctor ? array_map(static fn ($p) => $p->getName(), $ctor->getParameters()) : [];
            foreach ($xpath->query('arguments/argument', $node) as $arg) {
                $argName = $arg->getAttribute('name');
                if (!in_array($argName, $params, true)) {
                    $problems[] = "$name: constructor of $class has no parameter \$$argName";
                }
            }
            foreach ($xpath->query('.//argument[@xsi:type="object"]|.//item[@xsi:type="object"]', $node) as $obj) {
                $value = trim($obj->textContent);
                if (!isset($virtual[$value]) && !self::typeExists($value)) {
                    $problems[] = "$name: object $value does not exist";
                }
            }
            foreach ($xpath->query('plugin', $node) as $plugin) {
                if (!self::typeExists($plugin->getAttribute('type'))) {
                    $problems[] = "$name: plugin class " . $plugin->getAttribute('type') . ' does not exist';
                }
            }
        }
        foreach ($xpath->query('//preference') as $pref) {
            $for = ltrim($pref->getAttribute('for'), '\\');
            $type = ltrim($pref->getAttribute('type'), '\\');
            if (!self::typeExists($type) || !is_a($type, $for, true)) {
                $problems[] = "preference $type does not implement $for";
            }
        }
        $this->assertSame([], $problems);
    }

    /**
     * Every cron job, observer and admin controller is wired to an existing class/method/ACL resource
     *
     * @return void
     */
    public function testCronObserverControllerAndAclWiring(): void
    {
        $problems = [];
        foreach (self::xpath('etc/crontab.xml')->query('//job') as $job) {
            $class = $job->getAttribute('instance');
            $method = $job->getAttribute('method');
            if (!class_exists($class) || !method_exists($class, $method)
                || !(new \ReflectionMethod($class, $method))->isPublic()) {
                $problems[] = 'cron ' . $job->getAttribute('name') . " -> $class::$method";
            }
            if (!str_starts_with($job->getAttribute('name'), 'panth_aiv_')) {
                $problems[] = 'cron job code without panth_aiv_ prefix: ' . $job->getAttribute('name');
            }
        }
        foreach (['etc/events.xml', 'etc/frontend/events.xml'] as $file) {
            foreach (self::xpath($file)->query('//observer') as $observer) {
                $class = $observer->getAttribute('instance');
                if (!class_exists($class) || !is_a($class, ObserverInterface::class, true)) {
                    $problems[] = "observer $class in $file";
                }
            }
        }
        $acl = [];
        foreach (self::xpath('etc/acl.xml')->query('//resource') as $resource) {
            $acl[] = $resource->getAttribute('id');
        }
        foreach (self::xpath('etc/adminhtml/menu.xml')->query('//add') as $item) {
            if (!in_array($item->getAttribute('resource'), $acl, true)) {
                $problems[] = 'menu resource ' . $item->getAttribute('resource') . ' not in acl.xml';
            }
        }
        $section = self::xpath('etc/adminhtml/system.xml')->query('//section/resource')->item(0);
        if ($section === null || !in_array(trim($section->textContent), $acl, true)) {
            $problems[] = 'config section resource missing from acl.xml';
        }
        foreach (self::sourceFiles() as $relative) {
            if (!str_starts_with($relative, 'Controller/Adminhtml/')) {
                continue;
            }
            $class = self::classOf($relative);
            $resource = constant($class . '::ADMIN_RESOURCE');
            if (!in_array($resource, $acl, true) || !str_starts_with($resource, 'Panth_AiVisibility::')) {
                $problems[] = "$class uses ACL resource $resource that acl.xml does not declare";
            }
            if (!is_a($class, HttpGetActionInterface::class, true)
                && !is_a($class, HttpPostActionInterface::class, true)
            ) {
                $problems[] = "$class declares no HTTP method interface";
            }
        }
        $this->assertSame([], $problems);
    }

    /**
     * Health checks registered in di.xml implement the interface and use unique codes
     *
     * @return void
     */
    public function testHealthChecksRegisteredAndUnique(): void
    {
        $codes = [];
        foreach (self::xpath('etc/di.xml')->query('//argument[@name="checks"]/item') as $item) {
            $class = trim($item->textContent);
            $this->assertTrue(is_a($class, HealthCheckInterface::class, true), $class);
            $codes[] = constant($class . '::CODE');
        }
        $this->assertCount(7, $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));
    }

    /**
     * Every system.xml field has a config.xml default and a Config constant, and vice versa
     *
     * @return void
     */
    public function testConfigPathsAreConsistent(): void
    {
        $system = [];
        $xpath = self::xpath('etc/adminhtml/system.xml');
        foreach ($xpath->query('//section/group/field') as $field) {
            $group = $field->parentNode;
            $section = $group->parentNode;
            $system[] = $section->getAttribute('id') . '/' . $group->getAttribute('id') . '/'
                . $field->getAttribute('id');
            $source = $xpath->query('source_model', $field)->item(0);
            if ($source !== null) {
                $this->assertTrue(self::typeExists(trim($source->textContent)), trim($source->textContent));
            }
        }
        $defaults = [];
        foreach (self::xpath('etc/config.xml')->query('/config/default/*/*/*') as $leaf) {
            $defaults[] = $leaf->parentNode->parentNode->nodeName . '/' . $leaf->parentNode->nodeName . '/'
                . $leaf->nodeName;
        }
        $constants = [];
        foreach ((new \ReflectionClass(self::NS . 'Model\\Config'))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'XML_PATH_') && is_string($value)
                && str_starts_with($value, 'panth_aivisibility/')
            ) {
                $constants[] = $value;
            }
        }
        sort($system);
        sort($defaults);
        sort($constants);
        $this->assertSame($system, $defaults, 'system.xml fields vs config.xml defaults');
        $this->assertSame($system, $constants, 'system.xml fields vs Model\\Config constants');
    }

    /**
     * Constructor dependency types of every module class exist (on 2.4.7 too, when run there)
     *
     * @return void
     */
    public function testConstructorDependencyTypesExist(): void
    {
        $problems = [];
        foreach (self::sourceFiles() as $relative) {
            $class = self::classOf($relative);
            if ($class === null || !class_exists($class)) {
                continue;
            }
            $ctor = (new \ReflectionClass($class))->getConstructor();
            if ($ctor === null) {
                continue;
            }
            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()
                    && !self::typeExists($type->getName())
                ) {
                    $problems[] = "$class::__construct(\$" . $param->getName() . ') type ' . $type->getName();
                }
            }
        }
        $this->assertSame([], $problems);
    }

    /**
     * Methods called on injected dependencies ($this->dep->method()) exist on the declared type
     *
     * @return void
     */
    public function testMethodsCalledOnInjectedDependenciesExist(): void
    {
        $problems = [];
        $checked = 0;
        foreach (self::sourceFiles() as $relative) {
            $class = self::classOf($relative);
            if ($class === null || !class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            $types = [];
            foreach ($reflection->getProperties() as $property) {
                $type = $property->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $types[$property->getName()] = $type->getName();
                }
            }
            $source = (string)file_get_contents(self::root() . '/' . $relative);
            preg_match_all('/\$this->(\w+)->(\w+)\(/', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $property, $method]) {
                $type = $types[$property] ?? null;
                if ($type === null || !self::typeExists($type)) {
                    continue;
                }
                if (str_ends_with($type, 'Factory') && $method === 'create') {
                    continue;
                }
                $checked++;
                if (!method_exists($type, $method) && !method_exists($type, '__call')) {
                    $problems[] = "$class: \$this->$property->$method() not declared on $type";
                }
            }
        }
        $this->assertGreaterThan(200, $checked);
        $this->assertSame([], array_values(array_unique($problems)));
    }

    /**
     * composer.json and module.xml agree; module.xml sequence modules are required in composer.json
     *
     * @return void
     */
    public function testReleaseMetadataIsConsistent(): void
    {
        $composer = json_decode((string)file_get_contents(self::root() . '/composer.json'), true);
        $this->assertIsArray($composer);
        $this->assertSame('mage2kishan/module-ai-visibility', $composer['name']);
        $this->assertSame('magento2-module', $composer['type']);
        $this->assertSame(['Panth\\AiVisibility\\' => ''], $composer['autoload']['psr-4']);

        $map = ['Panth_Core' => 'mage2kishan/module-core'];
        $missing = [];
        foreach (self::xpath('etc/module.xml')->query('//sequence/module') as $module) {
            $name = $module->getAttribute('name');
            $package = $map[$name] ?? 'magento/module-'
                . strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', substr($name, strlen('Magento_'))));
            if (!isset($composer['require'][$package])) {
                $missing[] = "$name ($package)";
            }
        }
        $this->assertSame([], $missing);
        $registration = (string)file_get_contents(self::root() . '/registration.php');
        $this->assertStringContainsString("'Panth_AiVisibility'", $registration);
        foreach (['LICENSE.txt', 'COPYING.txt'] as $file) {
            $text = (string)file_get_contents(self::root() . '/' . $file);
            $this->assertStringContainsString('Panth_AiVisibility', $text);
        }
    }

    /**
     * Release gate: the latest CHANGELOG entry is the composer version
     *
     * While a minor version is in development composer.json is already raised and the CHANGELOG
     * entry is written at release time; the test is skipped (reported) until then, never passed.
     *
     * @return void
     */
    public function testChangelogMatchesComposerVersion(): void
    {
        $composer = json_decode((string)file_get_contents(self::root() . '/composer.json'), true);
        $changelog = (string)file_get_contents(self::root() . '/CHANGELOG.md');
        preg_match_all('/^## \[([0-9.]+)\]/m', $changelog, $versions);
        $latest = $versions[1][0] ?? null;
        $this->assertNotNull($latest, 'CHANGELOG has no version entry');
        if (version_compare((string)$composer['version'], (string)$latest, '>')) {
            $this->markTestSkipped(
                'Release gate: CHANGELOG has no entry for ' . $composer['version'] . ' yet (latest ' . $latest . ').'
            );
        }
        $this->assertSame($composer['version'], $latest, 'latest CHANGELOG entry = composer version');
    }

    /**
     * db_schema_whitelist.json lists exactly the tables and columns of db_schema.xml; only own tables
     *
     * @return void
     */
    public function testWhitelistMatchesSchemaAndSchemaIsAdditive(): void
    {
        $schema = [];
        foreach (self::xpath('etc/db_schema.xml')->query('/schema/table') as $table) {
            $name = $table->getAttribute('name');
            $this->assertStringStartsWith('panth_aiv_', $name, 'module must only declare its own tables');
            foreach ($table->getElementsByTagName('column') as $column) {
                if ($column->parentNode === $table) {
                    $schema[$name][] = $column->getAttribute('name');
                }
            }
            sort($schema[$name]);
        }
        $whitelist = [];
        $json = json_decode((string)file_get_contents(self::root() . '/etc/db_schema_whitelist.json'), true);
        foreach ($json as $table => $parts) {
            $whitelist[$table] = array_keys($parts['column'] ?? []);
            sort($whitelist[$table]);
        }
        ksort($schema);
        ksort($whitelist);
        $this->assertCount(19, $schema);
        $this->assertSame($schema, $whitelist);
    }

    /**
     * Production files: strict types + copyright header, no debug calls, no TODO, no ObjectManager
     *
     * @return void
     */
    public function testSourceHygiene(): void
    {
        $problems = [];
        foreach (self::sourceFiles(['.php', '.phtml']) as $relative) {
            $source = (string)file_get_contents(self::root() . '/' . $relative);
            if (str_ends_with($relative, '.php') && !str_contains($source, 'declare(strict_types=1);')) {
                $problems[] = "$relative: no strict_types";
            }
            if (!str_contains($source, 'Copyright © Panth Infotech. All rights reserved.')) {
                $problems[] = "$relative: no copyright header";
            }
            $debug = '/\b(var_dump|print_r|error_log|var_export|debug_print_backtrace)\s*\(|\bdie\s*\(|\bexit\s*\(/';
            if (preg_match($debug, $source)) {
                $problems[] = "$relative: debug call";
            }
            if (preg_match('/\b(TODO|FIXME|XXX)\b/', $source)) {
                $problems[] = "$relative: TODO marker";
            }
            if (str_contains($source, 'ObjectManager::getInstance')) {
                $problems[] = "$relative: ObjectManager::getInstance";
            }
        }
        $this->assertSame([], $problems);
    }

    /**
     * The module ships its translation dictionary (house style: i18n/en_US.csv), covering every __() literal
     *
     * @return void
     */
    public function testTranslationDictionaryCoversPhrases(): void
    {
        $csv = self::root() . '/i18n/en_US.csv';
        $this->assertFileExists($csv, 'i18n/en_US.csv is missing although the module uses __() in many files');
        $known = [];
        $handle = fopen($csv, 'r');
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $known[$row[0] ?? ''] = true;
        }
        fclose($handle);
        $missing = [];
        foreach (self::sourceFiles(['.php', '.phtml']) as $relative) {
            $source = (string)file_get_contents(self::root() . '/' . $relative);
            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*[,)]/", $source, $matches);
            foreach ($matches[1] as $phrase) {
                $phrase = stripslashes($phrase);
                if (!isset($known[$phrase])) {
                    $missing[] = $phrase;
                }
            }
        }
        $this->assertSame([], array_values(array_unique($missing)));
    }

    /**
     * Every listing data source registered in di.xml has a ui_component that uses it (no dead wiring)
     *
     * @return void
     */
    public function testEveryRegisteredListingDataSourceIsUsed(): void
    {
        $components = '';
        foreach (glob(self::root() . '/view/adminhtml/ui_component/*.xml') as $file) {
            $components .= (string)file_get_contents($file);
        }
        $unused = [];
        foreach (self::xpath('etc/di.xml')->query('//argument[@name="collections"]/item') as $item) {
            if (!str_contains($components, '"' . $item->getAttribute('name') . '"')) {
                $unused[] = $item->getAttribute('name') . ' -> ' . trim($item->textContent);
            }
        }
        $this->assertSame([], $unused);
    }

    /**
     * README documents every console command the module registers
     *
     * @return void
     */
    public function testReadmeDocumentsEveryConsoleCommand(): void
    {
        $readme = (string)file_get_contents(self::root() . '/README.md');
        $undocumented = [];
        foreach (self::sourceFiles() as $relative) {
            if (!str_starts_with($relative, 'Console/Command/')) {
                continue;
            }
            $source = (string)file_get_contents(self::root() . '/' . $relative);
            if (preg_match("/setName\(\s*'([^']+)'/", $source, $m)
                || preg_match("/NAME\s*=\s*'([^']+)'/", $source, $m)
            ) {
                if (!str_contains($readme, $m[1])) {
                    $undocumented[] = $m[1];
                }
            }
        }
        $this->assertSame([], $undocumented);
    }

    /**
     * Every module file uses LF line endings (phpcs Generic.Files.LineEndings)
     *
     * @return void
     */
    public function testNoCrlfLineEndings(): void
    {
        $crlf = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(self::root()) + 1));
            if (str_starts_with($relative, '.git/')
                || !preg_match('/\.(php|phtml|xml|json|md|css|txt|csv)$/', $relative)
            ) {
                continue;
            }
            if (str_contains((string)file_get_contents($file->getPathname()), "\r\n")) {
                $crlf[] = $relative;
            }
        }
        sort($crlf);
        $this->assertSame([], $crlf);
    }
}
