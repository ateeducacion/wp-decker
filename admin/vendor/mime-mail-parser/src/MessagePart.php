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
 * MessagePart class for handling individual parts of an email message
 *
 * This class represents a single part of an email message, which could be
 * the body text, HTML content, or an attachment.
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

    protected array $headers;

    /**
     * Create a new MessagePart instance
     *
     * @param string $content The content of the message part
     * @param array  $headers The headers associated with this part
     */
    public function __construct(string $content, array $headers = [])
    {
        $this->content = $content;
        $this->headers = $headers;
    }

    /**
     * Get the content type of this message part
     *
     * @return string The content type or empty string if not set
     */
    public function getContentType(): string
    {
        return $this->headers['Content-Type'] ?? '';
    }

    /**
     * Get all headers for this message part
     *
     * @return array Array of headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific header value
     *
     * @param string $name    The name of the header to retrieve
     * @param mixed  $default Default value if header not found
     *
     * @return mixed The header value or default if not found
     */
    public function getHeader(string $name, $default = null): mixed
    {
        return $this->headers[$name] ?? $default;
    }

    /**
     * Get the decoded content of this message part
     *
     * @return string The decoded content
     */
    public function getContent(): string
    {
        $content = $this->content;
        $encoding = strtolower($this->getHeader('Content-Transfer-Encoding', ''));

        if ($encoding === 'base64') {
            return base64_decode($content);
        } elseif ($encoding === 'quoted-printable') {
            return quoted_printable_decode($content);
        }

        return $content;
    }

    /**
     * Check if this part is HTML content
     *
     * @return bool True if content type is text/html
     */
    public function isHtml(): bool
    {
        return str_starts_with(strtolower($this->getContentType()), 'text/html');
    }

    /**
     * Check if this part is plain text content
     *
     * @return bool True if content type is text/plain
     */
    public function isText(): bool
    {
        return str_starts_with(strtolower($this->getContentType()), 'text/plain');
    }

    /**
     * Check if this part is an image
     *
     * @return bool True if content type starts with image/
     */
    public function isImage(): bool
    {
        return str_starts_with(strtolower($this->getContentType()), 'image/');
    }

    /**
     * Check if this part is an attachment
     *
     * @return bool True if content disposition is attachment
     */
    public function isAttachment(): bool
    {
        return str_starts_with($this->getHeader('Content-Disposition', ''), 'attachment');
    }

    /**
     * Get the filename of this part if it's an attachment
     *
     * @return string The filename or empty string if not found
     */
    public function getFilename(): string
    {
        if (preg_match('/filename=([^;]+)/', $this->getHeader('Content-Disposition'), $matches)) {
            return trim($matches[1], '"');
        }

        if (preg_match('/name=([^;]+)/', $this->getContentType(), $matches)) {
            return trim($matches[1], '"');
        }

        return '';
    }

    /**
     * Get the size of the content in bytes
     *
     * @return int Size in bytes
     */
    public function getSize(): int
    {
        return strlen($this->getContent());
    }

    /**
     * Convert the message part to an array representation
     *
     * @return array Array containing message part data including headers, content, filename, and size
     */
    public function toArray(): array
    {
        return [
            'headers' => $this->getHeaders(),
            'content' => $this->getContent(),
            'filename' => $this->getFilename(),
            'size' => $this->getSize(),
        ];
    }

    /**
     * Specify data which should be serialized to JSON
     *
     * @return array Array containing message part data
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
