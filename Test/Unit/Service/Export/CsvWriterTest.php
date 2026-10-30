<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Test\Unit\Service\Export;

use Panth\AiVisibility\Service\Export\CsvWriter;
use PHPUnit\Framework\TestCase;

class CsvWriterTest extends TestCase
{
    /**
     * @dataProvider cellProvider
     * @param mixed $value
     * @param string $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cellProvider')]
    public function testCellEscapesFormulaStarts($value, string $expected): void
    {
        $this->assertSame($expected, (new CsvWriter())->cell($value));
    }

    /**
     * @return array<string, array>
     */
    public static function cellProvider(): array
    {
        return [
            'equals formula' => ['=HYPERLINK("http://example.invalid","x")', '\'=HYPERLINK("http://example.invalid","x")'],
            'plus' => ['+1+2', "'+1+2"],
            'minus' => ['-2+3', "'-2+3"],
            'at' => ['@SUM(A1)', "'@SUM(A1)"],
            'tab' => ["\t=1", "'\t=1"],
            'carriage return' => ["\r=1", "'\r=1"],
            'formula later in the text' => ['a=1', 'a=1'],
            'plain text' => ['/product-1.html', '/product-1.html'],
            'empty string' => ['', ''],
            'null' => [null, ''],
            'int' => [42, '42'],
            'negative int stays a number' => [-1, '-1'],
            'float trimmed' => [1.5, '1.5'],
            'float rounded to 4 decimals' => [1.234567, '1.2346'],
            'float whole' => [2.0, '2'],
            'negative zero' => [-0.00001, '0'],
            'not finite' => [INF, ''],
            'bool' => [true, '1'],
            'numeric string with minus is text' => ['-5', "'-5"],
            'array is empty' => [['x'], ''],
        ];
    }

    public function testLineQuotesSeparatorsQuotesAndNewlines(): void
    {
        $writer = new CsvWriter();
        $this->assertSame(
            "plain,\"a,b\",\"say \"\"hi\"\"\",\"two\nlines\",,7\r\n",
            $writer->line(['plain', 'a,b', 'say "hi"', "two\nlines", null, 7])
        );
    }

    public function testFormulaCellIsQuotedWhenItAlsoNeedsQuoting(): void
    {
        $this->assertSame("\"'=1,2\"\r\n", (new CsvWriter())->line(['=1,2']));
        $this->assertSame("\"'\r=1\"\r\n", (new CsvWriter())->line(["\r=1"]));
    }

    public function testDocumentWithAndWithoutBom(): void
    {
        $writer = new CsvWriter();
        $rows = [['2026-10-01', 3], ['2026-10-02', 0]];
        $csv = $writer->document(['day', 'hits'], $rows);
        $this->assertSame(CsvWriter::BOM . "day,hits\r\n2026-10-01,3\r\n2026-10-02,0\r\n", $csv);
        $this->assertSame("day,hits\r\n", $writer->document(['day', 'hits'], [], false));
    }

    public function testDocumentUsesRowValuesInOrder(): void
    {
        $csv = (new CsvWriter())->document(['a', 'b'], [['x' => 'first', 'y' => '@second']], false);
        $this->assertSame("a,b\r\nfirst,'@second\r\n", $csv);
    }

    public function testUtf8TextIsKept(): void
    {
        $this->assertSame("Café ☕\r\n", (new CsvWriter())->line(['Café ☕']));
    }
}
