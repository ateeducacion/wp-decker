<?php

/**
 * MessagePart.php
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */

namespace Erseco;

/**
 * MessagePart class for handling individual parts of an email message.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */
class MessagePart implements \JsonSerializable
{
    protected string $content;

    /** @var array<string, string> */
    protected array $headers;

    /**
     * Create a new MessagePart instance.
     *
     * @param string                $content The content of the message part.
     * @param array<string, string> $headers The headers associated with this part.
     */
    public function __construct(string $content, array $headers = [])
    {
        $this->content = $content;
        $this->headers = $headers;
    }

    /**
     * Get the content type of this message part.
     *
     * @return string The content type or an empty string if not set.
     */
    public function getContentType(): string
    {
        return $this->getStringHeader('Content-Type');
    }

    /**
     * Get all headers for this message part.
     *
     * @return array<string, string> Array of headers.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific header value using a case-insensitive name.
     *
     * @param string $name    The name of the header to retrieve.
     * @param mixed  $default Default value if the header is not found.
     *
     * @return mixed The header value or default if not found.
     */
    public function getHeader(string $name, $default = null): mixed
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return preg_replace('/\n[\t ]+/', ' ', $value) ?? $value;
            }
        }

        return $default;
    }

    /**
     * Get the decoded content of this message part.
     *
     * Decodes Content-Transfer-Encoding only. Charset conversion is not applied.
     * Use getContentAsUtf8() when UTF-8 text is required.
     *
     * @return string The decoded content.
     */
    public function getContent(): string
    {
        $encoding = strtolower(trim($this->getStringHeader('Content-Transfer-Encoding')));

        if ($encoding === 'base64') {
            $decoded = base64_decode($this->content, true);

            return $decoded === false ? $this->content : $decoded;
        }

        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($this->content);
        }

        return $this->content;
    }

    /**
     * Get transfer-decoded content converted to UTF-8 when the part is textual.
     *
     * Behaviour:
     * - Decodes Content-Transfer-Encoding first (same as getContent()).
     * - Converts only text/* parts using the Content-Type charset parameter.
     * - Leaves binary/non-text parts unchanged.
     * - Missing, unknown, or failed conversions return the transfer-decoded bytes.
     *
     * @return string UTF-8 text when conversion succeeds; otherwise decoded bytes.
     */
    public function getContentAsUtf8(): string
    {
        $content = $this->getContent();

        if (!$this->isTextualPart()) {
            return $content;
        }

        $charset = $this->getCharset();

        if ($charset === null || $charset === '') {
            return $content;
        }

        return $this->convertParameterCharset($content, $charset);
    }

    /**
     * Read the charset parameter from Content-Type.
     *
     * @return string|null Charset name or null when absent.
     */
    public function getCharset(): ?string
    {
        $charset = $this->getHeaderParameter('Content-Type', 'charset');

        if ($charset === null || $charset === '') {
            return null;
        }

        return trim($charset, " \t\"'");
    }

    /**
     * Whether this part is a textual MIME type eligible for charset conversion.
     *
     * @return bool True for text/* content types.
     */
    protected function isTextualPart(): bool
    {
        return str_starts_with(strtolower($this->getContentType()), 'text/');
    }

    /**
     * Get a header value guaranteed to be a string.
     *
     * @param string $name Header name.
     *
     * @return string Header value or an empty string.
     */
    protected function getStringHeader(string $name): string
    {
        $value = $this->getHeader($name, '');

        return is_string($value) ? $value : '';
    }

    /**
     * Check if this part is HTML content.
     *
     * @return bool True if the content type is text/html.
     */
    public function isHtml(): bool
    {
        return str_starts_with(strtolower($this->getContentType()), 'text/html');
    }

    /**
     * Check if this part is plain text content.
     *
     * @return bool True if the content type is text/plain.
     */
    public function isText(): bool
    {
        return str_starts_with(strtolower($this->getContentType()), 'text/plain');
    }

    /**
     * Check if this part is an image.
     *
     * @return bool True if the content type starts with image/.
     */
    public function isImage(): bool
    {
        return str_starts_with(strtolower($this->getContentType()), 'image/');
    }

    /**
     * Check if this part is an inline resource.
     *
     * @return bool True if the content disposition is inline.
     */
    public function isInline(): bool
    {
        return str_starts_with(
            strtolower(ltrim($this->getStringHeader('Content-Disposition'))),
            'inline'
        );
    }

    /**
     * Check if this part is an attachment.
     *
     * @return bool True if the part is an attachment.
     */
    public function isAttachment(): bool
    {
        $disposition = strtolower(ltrim($this->getStringHeader('Content-Disposition')));

        if (str_starts_with($disposition, 'attachment')) {
            return true;
        }

        return !$this->isInline() && $this->getFilename() !== '';
    }

    /**
     * Get the Content-ID without angle brackets.
     *
     * @return string Content-ID or an empty string if not found.
     */
    public function getContentId(): string
    {
        return trim($this->getStringHeader('Content-ID'), '<>');
    }

    /**
     * Get the filename of this part if available.
     *
     * Supports regular MIME parameters and RFC 2231 extended parameters.
     *
     * @return string The filename or an empty string if not found.
     */
    public function getFilename(): string
    {
        foreach (
            [
                ['Content-Disposition', 'filename'],
                ['Content-Type', 'name'],
            ] as [$header, $parameter]
        ) {
            $filename = $this->getHeaderParameter($header, $parameter);

            if ($filename !== null) {
                return $filename;
            }
        }

        return '';
    }

    /**
     * Get the size of the decoded content in bytes.
     *
     * @return int Size in bytes.
     */
    public function getSize(): int
    {
        return strlen($this->getContent());
    }

    /**
     * Convert the message part to an array representation.
     *
     * @return array<string, mixed> Array containing message part data.
     */
    public function toArray(): array
    {
        return [
            'headers' => $this->getHeaders(),
            'content' => $this->getContent(),
            'filename' => $this->getFilename(),
            'content_id' => $this->getContentId(),
            'inline' => $this->isInline(),
            'size' => $this->getSize(),
        ];
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * @return array<string, mixed> Array containing message part data.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Extract and decode a MIME header parameter.
     *
     * Preference order:
     * 1. RFC 2231 continuations (`name*0*=`, `name*1*=`, …)
     * 2. Single extended parameter (`name*=`)
     * 3. Regular parameter (`name=`)
     *
     * @param string $headerName Header name.
     * @param string $parameter  Parameter name.
     *
     * @return string|null Parameter value.
     */
    protected function getHeaderParameter(string $headerName, string $parameter): ?string
    {
        $header = $this->getHeader($headerName, '');

        if (!is_string($header) || $header === '') {
            return null;
        }

        $continued = $this->getContinuedParameter($header, $parameter);

        if ($continued !== null) {
            return $continued;
        }

        $extendedPattern = '/(?:^|;)\s*'
            . preg_quote($parameter, '/')
            . '\*\s*=\s*(?:"(?<quoted>(?:\\\\.|[^"])*)"|(?<plain>[^;]*))/i';

        if (preg_match($extendedPattern, $header, $matches)) {
            $value = $matches['quoted'] !== '' ? $matches['quoted'] : trim($matches['plain']);

            return $this->decodeExtendedParameter(stripcslashes($value));
        }

        $regularPattern = '/(?:^|;)\s*'
            . preg_quote($parameter, '/')
            . '\s*=\s*(?:"(?<quoted>(?:\\\\.|[^"])*)"|(?<plain>[^;]*))/i';

        if (!preg_match($regularPattern, $header, $matches)) {
            return null;
        }

        $value = $matches['quoted'] !== '' ? $matches['quoted'] : trim($matches['plain']);

        return stripcslashes($value);
    }

    /**
     * Assemble an RFC 2231 continued parameter value.
     *
     * Segments may appear out of order. Duplicate indexes keep the last value.
     * Missing indexes are skipped so remaining segments still concatenate.
     *
     * @param string $header    Full header value.
     * @param string $parameter Base parameter name (e.g. filename).
     *
     * @return string|null Assembled value or null when no continuations exist.
     */
    protected function getContinuedParameter(string $header, string $parameter): ?string
    {
        $pattern = '/(?:^|;)\s*'
            . preg_quote($parameter, '/')
            . '\*(?<index>\d+)(?<encoded>\*)?\s*=\s*'
            . '(?:"(?<quoted>(?:\\\\.|[^"])*)"|(?<plain>[^;]*))/i';

        if (!preg_match_all($pattern, $header, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $segments = [];

        foreach ($matches as $match) {
            $index = (int) $match['index'];
            $encoded = ($match['encoded'] ?? '') === '*';
            $raw = $match['quoted'] !== '' ? $match['quoted'] : trim($match['plain']);
            $raw = stripcslashes($raw);

            if ($encoded) {
                $segments[$index] = [
                    'encoded' => true,
                    'value' => $raw,
                ];
            } else {
                $segments[$index] = [
                    'encoded' => false,
                    'value' => $raw,
                ];
            }
        }

        if ($segments === []) {
            return null;
        }

        ksort($segments, SORT_NUMERIC);

        $charset = null;
        $parts = [];

        foreach ($segments as $index => $segment) {
            $value = $segment['value'];

            if ($segment['encoded']) {
                if ($index === 0 && preg_match("/^([^']*)'[^']*'(.*)$/", $value, $charsetMatch)) {
                    $charset = $charsetMatch[1];
                    $value = $charsetMatch[2];
                }

                $parts[] = rawurldecode($value);
                continue;
            }

            $parts[] = $value;
        }

        $joined = implode('', $parts);

        if ($charset !== null && $charset !== '') {
            return $this->convertParameterCharset($joined, $charset);
        }

        return $joined;
    }

    /**
     * Decode an RFC 2231 extended parameter value.
     *
     * @param string $value Encoded parameter value.
     *
     * @return string Decoded value.
     */
    protected function decodeExtendedParameter(string $value): string
    {
        if (!preg_match("/^([^']*)'[^']*'(.*)$/", $value, $matches)) {
            return rawurldecode($value);
        }

        $charset = $matches[1];
        $decoded = rawurldecode($matches[2]);

        return $this->convertParameterCharset($decoded, $charset);
    }

    /**
     * Convert parameter bytes to UTF-8 when a charset is declared.
     *
     * Falls back to the original bytes when conversion is unavailable.
     *
     * @param string $bytes   Decoded parameter bytes.
     * @param string $charset Source charset.
     *
     * @return string Converted or original value.
     */
    protected function convertParameterCharset(string $bytes, string $charset): string
    {
        $normalized = strtoupper(str_replace('_', '-', trim($charset)));
        $aliases = [
            'UTF8' => 'UTF-8',
            'US-ASCII' => 'ASCII',
            'ISO8859-1' => 'ISO-8859-1',
            'LATIN1' => 'ISO-8859-1',
            'CP1252' => 'WINDOWS-1252',
            'WIN-1252' => 'WINDOWS-1252',
        ];
        $normalized = $aliases[$normalized] ?? $normalized;

        if (
            $normalized === ''
            || $normalized === 'UTF-8'
            || $normalized === 'ASCII'
        ) {
            return $bytes;
        }

        if ($normalized === 'ISO-8859-1') {
            return Rfc2047::decode('=?ISO-8859-1?B?' . base64_encode($bytes) . '?=');
        }

        if ($normalized === 'WINDOWS-1252') {
            return Rfc2047::decode('=?Windows-1252?B?' . base64_encode($bytes) . '?=');
        }

        $converted = $this->convertWithOptionalExtensions($bytes, $normalized);

        return $converted ?? $bytes;
    }

    /**
     * Attempt charset conversion using optional PHP extensions.
     *
     * @param string $bytes   Source bytes.
     * @param string $charset Source charset name.
     *
     * @return string|null Converted UTF-8 string or null on failure.
     */
    protected function convertWithOptionalExtensions(string $bytes, string $charset): ?string
    {
        if (function_exists('mb_convert_encoding')) {
            try {
                set_error_handler(static function (): bool {
                    return true;
                });

                try {
                    $converted = mb_convert_encoding($bytes, 'UTF-8', $charset);
                } finally {
                    restore_error_handler();
                }

                if (is_string($converted) && ($converted !== '' || $bytes === '')) {
                    return $converted;
                }
            } catch (\Throwable $exception) {
                // Unknown charsets may throw; fall through.
            }
        }

        if (function_exists('iconv')) {
            try {
                set_error_handler(static function (): bool {
                    return true;
                });

                try {
                    $converted = iconv($charset, 'UTF-8//IGNORE', $bytes);
                } finally {
                    restore_error_handler();
                }

                if (is_string($converted)) {
                    return $converted;
                }
            } catch (\Throwable $exception) {
                // Unknown charsets may throw; fall through.
            }
        }

        return null;
    }
}
