<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\DeepTest;

use PHPUnit\Framework\TestCase;

/**
 * Deep test E6 (1.1 compatibility and quality).
 *
 * Static checks that the 1.1 documentation, translations and release files match the code: README table
 * count, ACL resources, admin pages, configuration groups, cron jobs, console commands, health checks,
 * the 1.0 -> 1.1 upgrade note, en_US.csv coverage of translatable PHP/PHTML strings and release hygiene.
 */
class V11QualityTest extends TestCase
{
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
     * File contents relative to the module root
     *
     * @param string $relative
     * @return string
     */
    private static function read(string $relative): string
    {
        return (string)file_get_contents(self::root() . '/' . $relative);
    }

    /**
     * XPath over a module XML file
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
     * Production files (no tests) with one of the suffixes
     *
     * @param string[] $suffixes
     * @return string[]
     */
    private static function sourceFiles(array $suffixes): array
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

    public function testReadmeTableCountMatchesDbSchema(): void
    {
        $tables = self::xpath('etc/db_schema.xml')->query('/schema/table')->length;
        preg_match_all('/(\d+) `panth_aiv_\*` tables/', self::read('README.md'), $m);
        $this->assertNotEmpty($m[1], 'README states how many panth_aiv_* tables setup:upgrade creates');
        foreach ($m[1] as $stated) {
            $this->assertSame($tables, (int)$stated, 'README table count vs db_schema.xml');
        }
    }

    public function testRawHitsHaveTheStatusIndexTheErrorRulesRead(): void
    {
        // bot_404 / bot_errors_or_slow read 4xx / 5xx hits of a 7-day window through (status, created_at)
        $columns = [];
        $nodes = self::xpath('etc/db_schema.xml')->query(
            "/schema/table[@name='panth_aiv_hit']/index[@referenceId='PANTH_AIV_HIT_STATUS_CREATED_AT']/column"
        );
        foreach ($nodes as $node) {
            $columns[] = $node->getAttribute('name');
        }
        $this->assertSame(['status', 'created_at'], $columns);
        $whitelist = json_decode(self::read('etc/db_schema_whitelist.json'), true);
        $this->assertTrue($whitelist['panth_aiv_hit']['index']['PANTH_AIV_HIT_STATUS_CREATED_AT'] ?? false);
    }

    public function testReadmeHasNoInDevelopmentMarkers(): void
    {
        $lines = array_values(array_filter(
            explode("\n", self::read('README.md')),
            static fn (string $line): bool => stripos($line, 'in development') !== false
        ));
        $this->assertSame([], array_map(static fn ($l) => substr($l, 0, 60), $lines), 'README lines still marked "in development"');
    }

    public function testReadmeListsEveryAclResource(): void
    {
        $readme = self::read('README.md');
        $missing = [];
        foreach (self::xpath('etc/acl.xml')->query('//resource/@id') as $id) {
            $value = $id->nodeValue;
            if (str_starts_with($value, 'Panth_AiVisibility::') && $value !== 'Panth_AiVisibility::aivisibility'
                && !str_contains($readme, $value)) {
                $missing[] = $value;
            }
        }
        $this->assertSame([], $missing, 'ACL resources not documented in README');
    }

    public function testReadmeListsEveryAdminPage(): void
    {
        $readme = self::read('README.md');
        $missing = [];
        foreach (self::xpath('etc/adminhtml/menu.xml')->query('//add[@action]/@title') as $title) {
            if (!str_contains($readme, $title->nodeValue)) {
                $missing[] = $title->nodeValue;
            }
        }
        $this->assertSame([], $missing, 'Admin menu pages not documented in README');
    }

    public function testReadmeListsEveryConfigurationGroup(): void
    {
        $readme = self::read('README.md');
        $missing = [];
        foreach (self::xpath('etc/adminhtml/system.xml')->query('//section/group/label') as $label) {
            if (!str_contains($readme, trim($label->nodeValue))) {
                $missing[] = trim($label->nodeValue);
            }
        }
        $this->assertSame([], $missing, 'Configuration groups not documented in README');
    }

    public function testReadmeListsEveryCronJobAndConsoleCommand(): void
    {
        $readme = self::read('README.md');
        $missing = [];
        foreach (self::xpath('etc/crontab.xml')->query('//job/@name') as $job) {
            if (!str_contains($readme, '`' . $job->nodeValue . '`')) {
                $missing[] = $job->nodeValue;
            }
        }
        foreach (self::sourceFiles(['Command.php']) as $file) {
            if (preg_match("/setName\\(\\s*'([^']+)'/", self::read($file), $m)
                && !str_contains($readme, $m[1])) {
                $missing[] = $m[1];
            }
        }
        $this->assertSame([], $missing);
    }

    public function testReadmeHealthCheckCountMatchesDi(): void
    {
        $checks = self::xpath('etc/di.xml')->query('//argument[@name="checks"]/item')->length;
        $words = [5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine'];
        $this->assertStringContainsString(($words[$checks] ?? (string)$checks) . ' checks', self::read('README.md'));
    }

    public function testReadmeDocumentsTheUpgradeFrom10(): void
    {
        $this->assertSame(
            1,
            preg_match('/upgrad\w*[^\n]{0,80}\b1\.0\b|\b1\.0(\.\d+)?\b[^\n]{0,80}upgrad/i', self::read('README.md')),
            'README should tell 1.0 users how to upgrade to 1.1 (setup:upgrade, new tables, history of entity_daily)'
        );
    }

    public function testComposerVersionIs110(): void
    {
        $composer = json_decode(self::read('composer.json'), true);
        $this->assertSame('1.1.0', $composer['version'] ?? null);
    }

    /**
     * Every literal (or concatenation of literals) passed to __() in PHP/PHTML has an en_US.csv row
     */
    public function testEveryTranslatablePhpStringIsInEnUsCsv(): void
    {
        $known = [];
        $handle = fopen(self::root() . '/i18n/en_US.csv', 'r');
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (isset($row[0])) {
                $known[$row[0]] = true;
            }
        }
        fclose($handle);

        $literal = "'(?:[^'\\\\]|\\\\.)*'";
        $pattern = '/(?<![A-Za-z0-9_>:])__\(\s*(' . $literal . '(?:\s*\.\s*' . $literal . ')*)\s*[,)]/s';
        $missing = [];
        foreach (self::sourceFiles(['.php', '.phtml']) as $file) {
            preg_match_all($pattern, self::read($file), $matches);
            foreach ($matches[1] as $expr) {
                preg_match_all('/' . $literal . '/s', $expr, $parts);
                $text = '';
                foreach ($parts[0] as $part) {
                    $text .= str_replace(["\\'", '\\\\'], ["'", '\\'], substr($part, 1, -1));
                }
                if (!isset($known[$text])) {
                    $missing[] = $file . ': ' . substr($text, 0, 70);
                }
            }
        }
        $this->assertSame([], array_values(array_unique($missing)), 'Translatable strings missing in i18n/en_US.csv');
    }

    public function testNoDebugCodeTodoOrEnvironmentDetailsInReleaseFiles(): void
    {
        $hits = [];
        foreach (self::sourceFiles(['.php', '.phtml', '.xml', '.html', '.js', '.md', '.json', '.csv']) as $file) {
            $text = self::read($file);
            if (preg_match('/\b(var_dump|print_r|error_log|debug_print_backtrace)\s*\(|console\.log|debugger;/', $text)) {
                $hits[] = $file . ' debug';
            }
            if (preg_match('/\b(TODO|FIXME)\b/', $text)) {
                $hits[] = $file . ' todo';
            }
            if (preg_match('#/Users/|/home/[a-z]|\b192\.168\.\d|\b10\.\d+\.\d+\.\d+\b|\.local\b#', $text)) {
                $hits[] = $file . ' environment detail';
            }
        }
        $this->assertSame([], $hits);
    }
}
