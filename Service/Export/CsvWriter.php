<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\Export;

/**
 * RFC 4180 CSV lines with spreadsheet formula-injection protection.
 *
 * Text cells that start with = + - @, a tab or a carriage return get a leading single quote, so a
 * spreadsheet shows them as text instead of running them as a formula. PHP int / float values are
 * written as plain numbers (never prefixed, so negative numbers stay numbers); null is an empty cell.
 * Cells with a comma, double quote, CR or LF are quoted, quotes doubled. Lines end with CRLF.
 */
class CsvWriter
{
    public const BOM = "\xEF\xBB\xBF";

    /**
     * First characters that make a spreadsheet treat a cell as a formula
     */
    public const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Whole CSV document: optional UTF-8 BOM, header line, one line per row
     *
     * @param string[] $headers
     * @param iterable<array> $rows Each row a list of cells in header order
     * @param bool $bom Prefix the UTF-8 byte order mark (helps spreadsheet apps detect UTF-8)
     * @return string
     */
    public function document(array $headers, iterable $rows, bool $bom = true): string
    {
        $csv = ($bom ? self::BOM : '') . $this->line($headers);
        foreach ($rows as $row) {
            $csv .= $this->line(array_values($row));
        }
        return $csv;
    }

    /**
     * One CSV line (with CRLF)
     *
     * @param array $cells
     * @return string
     */
    public function line(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            $text = $this->cell($cell);
            if (strpbrk($text, ",\"\r\n") !== false) {
                $text = '"' . str_replace('"', '""', $text) . '"';
            }
            $out[] = $text;
        }
        return implode(',', $out) . "\r\n";
    }

    /**
     * Cell text before CSV quoting, with formula protection for text values
     *
     * @param mixed $value
     * @return string
     */
    public function cell($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_float($value)) {
            return $this->number($value);
        }
        $text = is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))
            ? (string)$value
            : '';
        if ($text !== '' && in_array($text[0], self::FORMULA_START, true)) {
            $text = "'" . $text;
        }
        return $text;
    }

    /**
     * Float with at most 4 decimals, no trailing zeros, '.' as decimal separator
     *
     * @param float $value
     * @return string
     */
    private function number(float $value): string
    {
        if (!is_finite($value)) {
            return '';
        }
        $text = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
        return $text === '-0' ? '0' : $text;
    }
}
