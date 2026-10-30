<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model;

/**
 * Byte-level clean-up of request text before it is classified or stored.
 */
class TextFilter
{
    /**
     * Longest User-Agent that is classified and stored
     */
    public const MAX_USER_AGENT = 2048;

    /**
     * One valid UTF-8 character (group 1) or any other single byte (group 2)
     */
    private const UTF8_OR_BYTE = '/([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]'
        . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}'
        . '|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|(.)/s';

    /**
     * Percent-encode every byte that is not part of a valid UTF-8 character ("\xFF" -> "%FF")
     *
     * Unlike a "?" replacement character this keeps distinct paths distinct and never introduces a
     * query separator.
     *
     * @param string $value
     * @return string
     */
    public static function encodeInvalidUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        return (string)preg_replace_callback(
            self::UTF8_OR_BYTE,
            static fn (array $m): string => isset($m[2]) ? sprintf('%%%02X', ord($m[2])) : $m[1],
            $value
        );
    }

    /**
     * User-Agent as classified and stored: no control characters, valid UTF-8, at most 2048 characters
     *
     * @param string $userAgent
     * @return string
     */
    public static function userAgent(string $userAgent): string
    {
        $userAgent = (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $userAgent);
        if (!mb_check_encoding($userAgent, 'UTF-8')) {
            $userAgent = (string)mb_convert_encoding($userAgent, 'UTF-8', 'UTF-8');
        }
        return trim(mb_substr($userAgent, 0, self::MAX_USER_AGENT, 'UTF-8'));
    }
}
