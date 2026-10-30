<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\LogImport;

use Panth\AiVisibility\Observer\TrackRequest;
use Panth\AiVisibility\Service\HitWriter;
use Panth\AiVisibility\Service\LogImport\CombinedLogParser;
use PHPUnit\Framework\TestCase;

/**
 * A page requested through PHP and the same page read from an access log must get the same
 * stored path, path_hash and query flag.
 */
class PathParityTest extends TestCase
{
    /**
     * Request URIs as a browser or crawler sends them
     *
     * @return array<string, array{0: string}>
     */
    public static function uris(): array
    {
        return [
            'plain' => ['/gear/tent.html'],
            'root' => ['/'],
            'query' => ['/catalogsearch/result/?q=tent'],
            'empty query' => ['/gear.html?'],
            'fragment' => ['/gear.html#reviews'],
            'encoded space' => ['/gear/blue%20tent.html'],
            'encoded utf8' => ['/caf%C3%A9.html?x=1'],
            'invalid utf8 escape' => ['/bad%FF.html'],
            'encoded slash' => ['/a%2Fb.html'],
            'double slashes' => ['//gear///tent.html'],
            'upper case' => ['/Gear/Tent.HTML'],
            'llms' => ['/llms.txt'],
            'store code' => ['/en/gear.html'],
            'long' => ['/' . str_repeat('abcdefghij', 60) . '.html'],
            'long encoded' => ['/' . str_repeat('%C3%A9', 300)],
        ];
    }

    /**
     * @dataProvider uris
     * @param string $uri
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('uris')]
    public function testSameUriGivesSamePathHashFromPhpAndFromTheLog(string $uri): void
    {
        [$phpPath, $phpQuery] = TrackRequest::splitUri($uri);
        $phpStored = HitWriter::cleanPath($phpPath);

        $line = '192.0.2.10 - - [29/Oct/2026:10:15:30 +0000] "GET ' . $uri . ' HTTP/1.1" 200 512 "-" "GPTBot/1.2"';
        $entry = (new CombinedLogParser())->parse($line);
        $this->assertNotNull($entry);
        $logStored = HitWriter::cleanPath($entry['path']);

        $this->assertSame($phpStored, $logStored);
        $this->assertSame(sha1($phpStored), sha1($logStored));
        $this->assertSame($phpQuery, $entry['has_query']);
        $this->assertSame(TrackRequest::isSkippedPath($phpPath), TrackRequest::isSkippedPath($entry['path']));
    }

    public function testAbsoluteUriInTheLogMatchesTheRelativeRequest(): void
    {
        $line = '192.0.2.10 - - [29/Oct/2026:10:15:30 +0000] "GET https://shop.test/caf%C3%A9.html?x=1 HTTP/1.1" '
            . '200 512 "-" "GPTBot/1.2"';
        $entry = (new CombinedLogParser())->parse($line);
        $this->assertNotNull($entry);
        [$phpPath, $phpQuery] = TrackRequest::splitUri('/caf%C3%A9.html?x=1');
        $this->assertSame('/café.html', $entry['path']);
        $this->assertSame(sha1(HitWriter::cleanPath($phpPath)), sha1(HitWriter::cleanPath($entry['path'])));
        $this->assertSame($phpQuery, $entry['has_query']);
    }
}
