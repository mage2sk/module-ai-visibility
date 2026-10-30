<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\LogImport;

use Panth\AiVisibility\Service\LogImport\CombinedLogParser;
use PHPUnit\Framework\TestCase;

class CombinedLogParserTest extends TestCase
{
    private const UA_BOT = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://example.invalid/bot)';

    /**
     * @var CombinedLogParser
     */
    private CombinedLogParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CombinedLogParser();
    }

    /**
     * Valid lines: line, expected subset of the parsed result
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function validLines(): array
    {
        $ua = self::UA_BOT;
        return [
            'nginx combined ipv4' => [
                '203.0.113.7 - - [29/Oct/2026:10:15:30 +0000] "GET /gear/tent.html HTTP/1.1" 200 5123 "-" '
                    . '"' . $ua . '"',
                [
                    'ip' => '203.0.113.7', 'timestamp' => gmmktime(10, 15, 30, 10, 29, 2026), 'method' => 'GET',
                    'path' => '/gear/tent.html', 'has_query' => false, 'status' => 200, 'referer' => '',
                    'user_agent' => $ua,
                ],
            ],
            'positive offset converted to utc' => [
                '203.0.113.7 - - [29/Oct/2026:12:15:30 +0200] "GET / HTTP/1.1" 200 10 "-" "x"',
                ['timestamp' => gmmktime(10, 15, 30, 10, 29, 2026), 'path' => '/'],
            ],
            'negative offset crosses midnight' => [
                '203.0.113.7 - - [31/Dec/2025:20:30:00 -0530] "GET / HTTP/1.1" 200 10 "-" "x"',
                ['timestamp' => gmmktime(2, 0, 0, 1, 1, 2026)],
            ],
            'ipv6 client and query' => [
                '2001:db8::1 - - [01/Mar/2026:00:00:01 +0000] "GET /search?q=tent HTTP/2.0" 404 0 "-" "y"',
                ['ip' => '2001:db8::1', 'path' => '/search', 'has_query' => true, 'status' => 404],
            ],
            'varnishncsa absolute url' => [
                '198.51.100.4 - - [01/Mar/2026:00:00:01 +0000] "GET http://shop.test/llms.txt?x=1 HTTP/1.1" 200 '
                    . '77 "https://example.invalid/page" "PerplexityBot/1.0"',
                [
                    'path' => '/llms.txt', 'has_query' => true, 'referer' => 'https://example.invalid/page',
                    'user_agent' => 'PerplexityBot/1.0',
                ],
            ],
            'absolute url without path' => [
                '198.51.100.4 - - [01/Mar/2026:00:00:01 +0000] "HEAD https://shop.test HTTP/1.1" 301 - "-" "z"',
                ['method' => 'HEAD', 'path' => '/', 'status' => 301],
            ],
            'apache escaped quotes' => [
                '203.0.113.7 - frank [01/Mar/2026:00:00:01 +0000] "GET / HTTP/1.1" 200 1 '
                    . '"https://shop.test/?a=\"b\"" "Agent \"Quoted\" 1.0"',
                ['referer' => 'https://shop.test/?a="b"', 'user_agent' => 'Agent "Quoted" 1.0'],
            ],
            'nginx hex escaped quotes' => [
                '203.0.113.7 - - [01/Mar/2026:00:00:01 +0000] "GET / HTTP/1.1" 200 1 "-" "Agent \x22Q\x22 \\\\ 2"',
                ['user_agent' => 'Agent "Q" \\ 2'],
            ],
            'extra trailing fields' => [
                '203.0.113.7 - - [01/Mar/2026:00:00:01 +0000] "GET /a HTTP/1.1" 200 1 "-" "ua" 0.012 "-"',
                ['path' => '/a', 'user_agent' => 'ua'],
            ],
            'common format without agent' => [
                '203.0.113.7 - - [01/Mar/2026:00:00:01 +0000] "GET /a HTTP/1.0" 200 1',
                ['path' => '/a', 'user_agent' => '', 'referer' => ''],
            ],
            'http 0.9 request without protocol' => [
                '203.0.113.7 - - [01/Mar/2026:00:00:01 +0000] "GET /a" 200 1 "-" "ua"',
                ['path' => '/a'],
            ],
            'leap second clamped' => [
                '203.0.113.7 - - [31/Dec/2016:23:59:60 +0000] "GET / HTTP/1.1" 200 1 "-" "ua"',
                ['timestamp' => gmmktime(23, 59, 59, 12, 31, 2016)],
            ],
            'lower case month and crlf' => [
                "203.0.113.7 - - [05/jun/2026:08:00:00 +0000] \"GET /b HTTP/1.1\" 500 1 \"-\" \"ua\"\r",
                ['timestamp' => gmmktime(8, 0, 0, 6, 5, 2026), 'status' => 500],
            ],
            'fragment removed' => [
                '203.0.113.7 - - [01/Mar/2026:00:00:01 +0000] "GET /a#top HTTP/1.1" 200 1 "-" "ua"',
                ['path' => '/a', 'has_query' => false],
            ],
            'empty query is no query' => [
                '203.0.113.7 - - [01/Mar/2026:00:00:01 +0000] "GET /a? HTTP/1.1" 200 1 "-" "ua"',
                ['path' => '/a', 'has_query' => false],
            ],
        ];
    }

    /**
     * @dataProvider validLines
     * @param string $line
     * @param array<string, mixed> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validLines')]
    public function testParsesValidLines(string $line, array $expected): void
    {
        $entry = $this->parser->parse($line);
        $this->assertIsArray($entry);
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $entry[$key], $key);
        }
    }

    /**
     * Malformed lines
     *
     * @return array<string, array{0: string}>
     */
    public static function malformedLines(): array
    {
        $tail = ' "GET / HTTP/1.1" 200 1 "-" "ua"';
        return [
            'empty' => [''],
            'garbage' => ['this is not a log line'],
            'json line' => ['{"time":"2026-03-01T00:00:00Z","path":"/"}'],
            'unknown month' => ['203.0.113.7 - - [01/Foo/2026:00:00:01 +0000]' . $tail],
            'february 31' => ['203.0.113.7 - - [31/Feb/2026:00:00:01 +0000]' . $tail],
            'hour 25' => ['203.0.113.7 - - [01/Mar/2026:25:00:01 +0000]' . $tail],
            'minute 61' => ['203.0.113.7 - - [01/Mar/2026:10:61:01 +0000]' . $tail],
            'offset too large' => ['203.0.113.7 - - [01/Mar/2026:10:00:01 +1500]' . $tail],
            'offset minutes 75' => ['203.0.113.7 - - [01/Mar/2026:10:00:01 +0175]' . $tail],
            'year 1900' => ['203.0.113.7 - - [01/Mar/1900:10:00:01 +0000]' . $tail],
            'iso timestamp' => ['203.0.113.7 - - [2026-03-01T10:00:01+00:00]' . $tail],
            'missing offset' => ['203.0.113.7 - - [01/Mar/2026:10:00:01]' . $tail],
            'empty request' => ['203.0.113.7 - - [01/Mar/2026:10:00:01 +0000] "-" 400 0 "-" "-"'],
            'binary request' => ['203.0.113.7 - - [01/Mar/2026:10:00:01 +0000] "\x16\x03\x01" 400 0 "-" "-"'],
            'connect authority' => [
                '203.0.113.7 - - [01/Mar/2026:10:00:01 +0000] "CONNECT shop.test:443 HTTP/1.1" 400 0 "-" "-"',
            ],
            'unterminated quote' => ['203.0.113.7 - - [01/Mar/2026:10:00:01 +0000] "GET / HTTP/1.1 200 1 "-" "ua'],
            'status not numeric' => ['203.0.113.7 - - [01/Mar/2026:10:00:01 +0000] "GET / HTTP/1.1" OK 1 "-" "ua"'],
            'space in uri' => ['203.0.113.7 - - [01/Mar/2026:10:00:01 +0000] "GET /a b c HTTP/1.1" 200 1 "-" "ua"'],
        ];
    }

    /**
     * @dataProvider malformedLines
     * @param string $line
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedLines')]
    public function testRejectsMalformedLines(string $line): void
    {
        $this->assertNull($this->parser->parse($line));
    }

    public function testVeryLongUserAgentAndPathAreHandled(): void
    {
        $ua = str_repeat('a"b\\', 1) . str_repeat('x', 12000);
        $path = '/' . str_repeat('p', 2000);
        $line = '203.0.113.7 - - [01/Mar/2026:10:00:01 +0000] "GET ' . $path . '?q=1 HTTP/1.1" 200 1 "-" "'
            . str_replace(['\\', '"'], ['\\\\', '\\"'], $ua) . '"';
        $entry = $this->parser->parse($line);
        $this->assertIsArray($entry);
        $this->assertSame($ua, $entry['user_agent']);
        $this->assertSame(CombinedLogParser::MAX_PATH_LENGTH, strlen($entry['path']));
        $this->assertTrue($entry['has_query']);
    }

    public function testInvalidUtf8InUserAgentIsScrubbed(): void
    {
        $line = '203.0.113.7 - - [01/Mar/2026:10:00:01 +0000] "GET / HTTP/1.1" 200 1 "-" "Bot\xC3\x28/1"';
        $entry = $this->parser->parse($line);
        $this->assertIsArray($entry);
        $this->assertTrue(mb_check_encoding($entry['user_agent'], 'UTF-8'));
    }

    public function testParseTimeReturnsUnixSeconds(): void
    {
        $this->assertSame(0, $this->parser->parseTime('01/Jan/1990:00:00:00 +0000') - gmmktime(0, 0, 0, 1, 1, 1990));
        $this->assertNull($this->parser->parseTime('29/Feb/2025:00:00:00 +0000'));
        $this->assertSame(gmmktime(0, 0, 0, 2, 29, 2024), $this->parser->parseTime('29/Feb/2024:00:00:00 +0000'));
    }
}
