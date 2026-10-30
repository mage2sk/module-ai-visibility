<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Service\LogImport;

use Panth\AiVisibility\Observer\TrackRequest;

/**
 * Parses one access log line in the combined format, which is also the varnishncsa default:
 *
 *   ip - user [d/M/Y:H:i:s O] "METHOD URI PROTO" status bytes "referer" "user-agent"
 *
 * Extra fields after the user agent (nginx $request_time and similar) are ignored. The common
 * format without referer and user agent is accepted with an empty user agent. Quotes inside
 * quoted fields may be escaped as \" (Apache, varnishncsa) or \x22 (nginx).
 *
 * Pure: no I/O, no clock.
 */
class CombinedLogParser
{
    /**
     * Longest URL path that is kept (panth_aiv_hit.path is varchar(512))
     */
    public const MAX_PATH_LENGTH = 512;

    /**
     * Possessive quantifiers keep very long lines from backtracking
     */
    private const LINE_PATTERN = '/^(\S++) \S++ \S++ \[([^\]]{1,64})\] "((?:[^"\\\\]++|\\\\.)*+)" (\d{3}) (?:\d++|-)'
        . '(?: "((?:[^"\\\\]++|\\\\.)*+)" "((?:[^"\\\\]++|\\\\.)*+)")?(?:\s.*)?$/s';

    private const TIME_PATTERN = '/^(\d{2})\/([A-Za-z]{3})\/(\d{4}):(\d{2}):(\d{2}):(\d{2}) ([+-])(\d{2})(\d{2})$/';

    private const REQUEST_PATTERN = '/^([A-Z][A-Z_-]{0,19}) (\S+)(?: HTTP\/\d(?:\.\d)?)?$/';

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Parse a line; null when it is malformed
     *
     * The result holds: ip, timestamp (Unix time, UTC), method, path (no query, max 512
     * characters), has_query, status, referer, user_agent.
     *
     * @param string $line One line without its line break
     * @return array{ip: string, timestamp: int, method: string, path: string, has_query: bool,
     *     status: int, referer: string, user_agent: string}|null
     */
    public function parse(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if ($line === '' || preg_match(self::LINE_PATTERN, $line, $m) !== 1) {
            return null;
        }
        $timestamp = $this->parseTime($m[2]);
        if ($timestamp === null) {
            return null;
        }
        $request = $this->parseRequest($this->unescape($m[3]));
        if ($request === null) {
            return null;
        }
        return [
            'ip' => $m[1],
            'timestamp' => $timestamp,
            'method' => $request['method'],
            'path' => $request['path'],
            'has_query' => $request['has_query'],
            'status' => (int)$m[4],
            'referer' => $this->text($m[5] ?? ''),
            'user_agent' => $this->text($m[6] ?? ''),
        ];
    }

    /**
     * Convert "d/M/Y:H:i:s O" to Unix time; null for impossible dates or offsets
     *
     * @param string $value
     * @return int|null
     */
    public function parseTime(string $value): ?int
    {
        if (preg_match(self::TIME_PATTERN, $value, $m) !== 1) {
            return null;
        }
        $month = self::MONTHS[strtolower($m[2])] ?? 0;
        [$day, $year, $hour, $minute, $second] = [(int)$m[1], (int)$m[3], (int)$m[4], (int)$m[5], (int)$m[6]];
        $offsetHours = (int)$m[8];
        $offsetMinutes = (int)$m[9];
        if ($month === 0 || $year < 1990 || $year > 2100 || !checkdate($month, $day, $year)
            || $hour > 23 || $minute > 59 || $second > 60 || $offsetHours > 14 || $offsetMinutes > 59
        ) {
            return null;
        }
        // A leap second is stored as the last second of its minute.
        $second = min($second, 59);
        $offset = ($offsetHours * 3600 + $offsetMinutes * 60) * ($m[7] === '-' ? -1 : 1);
        $local = gmmktime($hour, $minute, $second, $month, $day, $year);
        return $local === false ? null : $local - $offset;
    }

    /**
     * Split "METHOD URI PROTO" into method, path and query flag
     *
     * The URI may be absolute (varnishncsa logs the full URL). The path is normalised by
     * TrackRequest::splitUri() exactly like a request handled by PHP.
     *
     * @param string $request
     * @return array{method: string, path: string, has_query: bool}|null
     */
    private function parseRequest(string $request): ?array
    {
        if (preg_match(self::REQUEST_PATTERN, $request, $m) !== 1) {
            return null;
        }
        $uri = $m[2];
        if (preg_match('#^https?://[^/?\#]*(.*)$#i', $uri, $abs) === 1) {
            $uri = $abs[1] === '' ? '/' : $abs[1];
        }
        if (!str_starts_with($uri, '/')) {
            return null;
        }
        // Same normalisation as live requests, so a page gets the same path (and path_hash) from both.
        [$path, $hasQuery] = TrackRequest::splitUri($uri);
        return [
            'method' => $m[1],
            'path' => $path,
            'has_query' => $hasQuery,
        ];
    }

    /**
     * Unescape a quoted field and make it valid UTF-8; "-" means empty
     *
     * @param string $value
     * @return string
     */
    private function text(string $value): string
    {
        $value = $this->unescape($value);
        return $value === '-' ? '' : trim(mb_scrub($value, 'UTF-8'));
    }

    /**
     * Undo \" \\ \xHH \n \t escapes written by nginx, Apache and varnishncsa
     *
     * @param string $value
     * @return string
     */
    private function unescape(string $value): string
    {
        if (!str_contains($value, '\\')) {
            return $value;
        }
        return (string)preg_replace_callback(
            '/\\\\(x[0-9A-Fa-f]{2}|.)/s',
            static function (array $m): string {
                $code = $m[1];
                if (strlen($code) === 3) {
                    return sprintf('%c', (int)hexdec(substr($code, 1)));
                }
                return match ($code) {
                    'n', 'r', 't' => ' ',
                    default => $code,
                };
            },
            $value
        );
    }
}
