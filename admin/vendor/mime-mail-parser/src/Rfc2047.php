<?php

/**
 * RFC 2047 encoded-word decoder for MIME headers.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */

namespace Erseco;

/**
 * Decode RFC 2047 encoded words without requiring mbstring or iconv.
 *
 * When charset conversion is unavailable or fails, the decoder returns the
 * safest available value (original token or decoded bytes) without throwing.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */
final class Rfc2047
{
    /**
     * Decode all RFC 2047 encoded words in a header value.
     *
     * Adjacent encoded words separated only by linear whitespace are joined
     * without that whitespace, per RFC 2047.
     *
     * @param string $value Unfolded header value.
     *
     * @return string Decoded header value, preferably UTF-8.
     */
    public static function decode(string $value): string
    {
        $pattern = '/((?:=\?[^?\s]+\?[BbQq]\?[^?]*\?=)'
            . '(?:[ \t]+(?:=\?[^?\s]+\?[BbQq]\?[^?]*\?=))*)/';

        $decoded = preg_replace_callback(
            $pattern,
            static function (array $matches): string {
                preg_match_all(
                    '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
                    $matches[1],
                    $words,
                    PREG_SET_ORDER
                );

                $result = '';

                foreach ($words as $word) {
                    $result .= self::decodeWord($word[0], $word[1], $word[2], $word[3]);
                }

                return $result;
            },
            $value
        );

        return $decoded ?? $value;
    }

    /**
     * Decode a single encoded-word token.
     *
     * @param string $original Original encoded-word text.
     * @param string $charset  Charset name.
     * @param string $encoding B or Q (any case).
     * @param string $text     Encoded payload.
     *
     * @return string Decoded text or the original token on failure.
     */
    private static function decodeWord(
        string $original,
        string $charset,
        string $encoding,
        string $text
    ): string {
        $encoding = strtoupper($encoding);

        if ($encoding === 'B') {
            $bytes = base64_decode($text, true);

            if ($bytes === false) {
                return $original;
            }
        } elseif ($encoding === 'Q') {
            $bytes = quoted_printable_decode(str_replace('_', ' ', $text));
        } else {
            return $original;
        }

        return self::convertToUtf8($bytes, $charset);
    }

    /**
     * Convert decoded bytes to UTF-8 when possible.
     *
     * @param string $bytes   Decoded raw bytes.
     * @param string $charset Source charset.
     *
     * @return string UTF-8 text or original bytes on failure.
     */
    private static function convertToUtf8(string $bytes, string $charset): string
    {
        $normalized = strtoupper(str_replace('_', '-', trim($charset)));
        $aliases = [
            'UTF8' => 'UTF-8',
            'US-ASCII' => 'ASCII',
            'ISO8859-1' => 'ISO-8859-1',
            'ISO-8859-1' => 'ISO-8859-1',
            'LATIN1' => 'ISO-8859-1',
            'CP1252' => 'WINDOWS-1252',
            'WIN-1252' => 'WINDOWS-1252',
            'WINDOWS-1252' => 'WINDOWS-1252',
        ];
        $normalized = $aliases[$normalized] ?? $normalized;

        if ($normalized === 'UTF-8' || $normalized === 'ASCII') {
            return $bytes;
        }

        if (function_exists('mb_convert_encoding')) {
            try {
                set_error_handler(static function (): bool {
                    return true;
                });

                try {
                    $converted = mb_convert_encoding($bytes, 'UTF-8', $normalized);
                } finally {
                    restore_error_handler();
                }

                if (is_string($converted) && ($converted !== '' || $bytes === '')) {
                    return $converted;
                }
            } catch (\Throwable $exception) {
                // Unknown charsets may throw on newer PHP versions.
            }
        }

        if (function_exists('iconv')) {
            try {
                set_error_handler(static function (): bool {
                    return true;
                });

                try {
                    $converted = iconv($normalized, 'UTF-8//IGNORE', $bytes);
                } finally {
                    restore_error_handler();
                }

                if (is_string($converted)) {
                    return $converted;
                }
            } catch (\Throwable $exception) {
                // Unknown charsets may throw on newer PHP versions.
            }
        }

        if ($normalized === 'ISO-8859-1') {
            return self::iso88591ToUtf8($bytes);
        }

        if ($normalized === 'WINDOWS-1252') {
            return self::windows1252ToUtf8($bytes);
        }

        return $bytes;
    }

    /**
     * Convert ISO-8859-1 bytes to UTF-8 without extensions.
     *
     * @param string $bytes ISO-8859-1 text.
     *
     * @return string UTF-8 text.
     */
    private static function iso88591ToUtf8(string $bytes): string
    {
        $result = '';
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $code = ord($bytes[$i]);

            if ($code < 0x80) {
                $result .= $bytes[$i];
                continue;
            }

            $result .= chr(0xC0 | ($code >> 6));
            $result .= chr(0x80 | ($code & 0x3F));
        }

        return $result;
    }

    /**
     * Convert Windows-1252 bytes to UTF-8 without extensions.
     *
     * @param string $bytes Windows-1252 text.
     *
     * @return string UTF-8 text.
     */
    private static function windows1252ToUtf8(string $bytes): string
    {
        /** @var array<int, string> $map */
        static $map = [
            0x80 => "\xE2\x82\xAC",
            0x82 => "\xE2\x80\x9A",
            0x83 => "\xC6\x92",
            0x84 => "\xE2\x80\x9E",
            0x85 => "\xE2\x80\xA6",
            0x86 => "\xE2\x80\xA0",
            0x87 => "\xE2\x80\xA1",
            0x88 => "\xCB\x86",
            0x89 => "\xE2\x80\xB0",
            0x8A => "\xC5\xA0",
            0x8B => "\xE2\x80\xB9",
            0x8C => "\xC5\x92",
            0x8E => "\xC5\xBD",
            0x91 => "\xE2\x80\x98",
            0x92 => "\xE2\x80\x99",
            0x93 => "\xE2\x80\x9C",
            0x94 => "\xE2\x80\x9D",
            0x95 => "\xE2\x80\xA2",
            0x96 => "\xE2\x80\x93",
            0x97 => "\xE2\x80\x94",
            0x98 => "\xCB\x9C",
            0x99 => "\xE2\x84\xA2",
            0x9A => "\xC5\xA1",
            0x9B => "\xE2\x80\xBA",
            0x9C => "\xC5\x93",
            0x9E => "\xC5\xBE",
            0x9F => "\xC5\xB8",
        ];

        $result = '';
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $code = ord($bytes[$i]);

            if ($code < 0x80) {
                $result .= $bytes[$i];
                continue;
            }

            if (array_key_exists($code, $map)) {
                $result .= $map[$code];
                continue;
            }

            if ($code >= 0xA0) {
                $result .= chr(0xC0 | ($code >> 6));
                $result .= chr(0x80 | ($code & 0x3F));
                continue;
            }

            // Undefined Windows-1252 slots (0x81, 0x8D, 0x8F, 0x90, 0x9D).
            $result .= self::iso88591ToUtf8(chr($code));
        }

        return $result;
    }
}
