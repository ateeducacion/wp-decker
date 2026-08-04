<?php

/**
 * Message.php
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */

namespace Erseco;

/**
 * Message class for parsing email messages.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */
class Message implements \JsonSerializable
{
    protected string $message;

    protected ?string $boundary = null;

    /** @var array<string, string> */
    protected array $headers = [];

    /** @var list<MessagePart> */
    protected array $parts = [];

    protected bool $ignoreSignature = false;

    protected ParserOptions $options;

    protected ParserContext $context;

    /**
     * Create a new Message instance.
     *
     * @param string              $message         The raw email message.
     * @param bool                $ignoreSignature Whether to ignore message signatures.
     * @param ParserOptions|null  $options         Optional safety limits.
     * @param ParserContext|null  $context         Shared state for nested parsers.
     *
     * @throws ParserLimitExceededException When a configured limit is exceeded.
     */
    public function __construct(
        string $message,
        bool $ignoreSignature = false,
        ?ParserOptions $options = null,
        ?ParserContext $context = null
    ) {
        $this->options = $options ?? ($context !== null ? $context->options : ParserOptions::defaults());
        $this->context = $context ?? new ParserContext($this->options);
        $this->ignoreSignature = $ignoreSignature;

        if ($context === null) {
            $this->assertWithinLimit(
                'maxMessageBytes',
                $this->options->maxMessageBytes,
                strlen($message)
            );
        }

        if ($this->context->depth > $this->options->maxDepth) {
            $this->assertWithinLimit(
                'maxDepth',
                $this->options->maxDepth,
                $this->context->depth
            );
        }

        $this->message = $message;
        $this->parse();
    }

    /**
     * Create a Message instance from a string.
     *
     * @param string             $message         The raw email message string.
     * @param bool               $ignoreSignature Whether to ignore message signatures.
     * @param ParserOptions|null $options         Optional safety limits.
     *
     * @return self
     */
    public static function fromString(
        string $message,
        bool $ignoreSignature = false,
        ?ParserOptions $options = null
    ): self {
        return new self($message, $ignoreSignature, $options);
    }

    /**
     * Create a Message instance from a file.
     *
     * @param string             $path            Path to the email message file.
     * @param bool               $ignoreSignature Whether to ignore message signatures.
     * @param ParserOptions|null $options         Optional safety limits.
     *
     * @throws \RuntimeException            When the file cannot be read.
     * @throws ParserLimitExceededException When the file exceeds maxMessageBytes.
     *
     * @return self
     */
    public static function fromFile(
        string $path,
        bool $ignoreSignature = false,
        ?ParserOptions $options = null
    ): self {
        if (!is_readable($path)) {
            throw new \RuntimeException(sprintf('Unable to read email message from "%s".', $path));
        }

        $options = $options ?? ParserOptions::defaults();
        $fileSize = filesize($path);

        if ($fileSize !== false) {
            if ($fileSize > $options->maxMessageBytes) {
                throw new ParserLimitExceededException(
                    'maxMessageBytes',
                    $options->maxMessageBytes,
                    $fileSize
                );
            }
        }

        $message = file_get_contents($path);

        if ($message === false) {
            throw new \RuntimeException(sprintf('Unable to read email message from "%s".', $path));
        }

        return new self($message, $ignoreSignature, $options);
    }

    /**
     * Get the active parser options.
     *
     * @return ParserOptions
     */
    public function getOptions(): ParserOptions
    {
        return $this->options;
    }

    /**
     * Get the message boundary.
     *
     * @return string|null The message boundary, or null for non-multipart messages.
     */
    public function getBoundary(): ?string
    {
        return $this->boundary;
    }

    /**
     * Get all headers from the email message.
     *
     * @return array<string, string> Array of headers with key-value pairs.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific header value from the email message.
     *
     * @param string $header  The name of the header to retrieve.
     * @param mixed  $default Default value if the header is not found.
     *
     * @return mixed The header value or the supplied default.
     */
    public function getHeader(string $header, $default = null): mixed
    {
        $key = $this->findHeaderKey($this->headers, $header);

        if ($key === null) {
            return $default;
        }

        return $this->unfoldHeaderValue($this->headers[$key]);
    }

    /**
     * Get every value for a repeated header.
     *
     * @param string $header The name of the header to retrieve.
     *
     * @return array<int, string> Header values.
     */
    public function getHeaderValues(string $header): array
    {
        $value = $this->getHeader($header);

        if (!is_string($value)) {
            return [];
        }

        return preg_split('/\n(?=\S)/', $value) ?: [$value];
    }

    /**
     * Get the Content-Type header.
     *
     * @return string Content type or an empty string.
     */
    public function getContentType(): string
    {
        return $this->getStringHeader('Content-Type');
    }

    /**
     * Get the Message-ID without angle brackets.
     *
     * @return string Message ID.
     */
    public function getId(): string
    {
        return trim($this->getStringHeader('Message-ID'), '<>');
    }

    /**
     * Get the message subject.
     *
     * @return string Subject.
     */
    public function getSubject(): string
    {
        return $this->getStringHeader('Subject');
    }

    /**
     * Get the From header.
     *
     * @return string Sender header.
     */
    public function getFrom(): string
    {
        return $this->getStringHeader('From');
    }

    /**
     * Get the To header.
     *
     * @return string Recipient header.
     */
    public function getTo(): string
    {
        return $this->getStringHeader('To');
    }

    /**
     * Get the Reply-To header.
     *
     * @return string Reply-To header.
     */
    public function getReplyTo(): string
    {
        return $this->getStringHeader('Reply-To');
    }

    /**
     * Get a header value with RFC 2047 encoded words decoded.
     *
     * Unlike getHeader(), this returns human-readable text (preferably UTF-8).
     * The raw unfolded value remains available via getHeader().
     *
     * @param string $header  The name of the header to retrieve.
     * @param mixed  $default Default value if the header is not found.
     *
     * @return mixed Decoded header value or the supplied default.
     */
    public function getDecodedHeader(string $header, $default = null): mixed
    {
        $value = $this->getHeader($header, $default);

        if (!is_string($value)) {
            return $value;
        }

        return Rfc2047::decode($value);
    }

    /**
     * Get the subject with RFC 2047 decoding applied.
     *
     * @return string Decoded subject.
     */
    public function getDecodedSubject(): string
    {
        return $this->getDecodedStringHeader('Subject');
    }

    /**
     * Get the From header with RFC 2047 decoding applied.
     *
     * @return string Decoded From header.
     */
    public function getDecodedFrom(): string
    {
        return $this->getDecodedStringHeader('From');
    }

    /**
     * Get the To header with RFC 2047 decoding applied.
     *
     * @return string Decoded To header.
     */
    public function getDecodedTo(): string
    {
        return $this->getDecodedStringHeader('To');
    }

    /**
     * Get the Reply-To header with RFC 2047 decoding applied.
     *
     * @return string Decoded Reply-To header.
     */
    public function getDecodedReplyTo(): string
    {
        return $this->getDecodedStringHeader('Reply-To');
    }

    /**
     * Get the message date.
     *
     * @return \DateTime|null Parsed date or null.
     */
    public function getDate(): ?\DateTime
    {
        $date = $this->getHeader('Date');

        if (!is_string($date) || $date === '') {
            return null;
        }

        try {
            return new \DateTime($date);
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * Get all message parts.
     *
     * @return MessagePart[] Message parts.
     */
    public function getParts(): array
    {
        return $this->parts;
    }

    /**
     * Get the first HTML part.
     *
     * @return MessagePart|null HTML part.
     */
    public function getHtmlPart(): ?MessagePart
    {
        foreach ($this->parts as $part) {
            if ($part->isHtml()) {
                return $part;
            }
        }

        return null;
    }

    /**
     * Get the first plain-text part.
     *
     * @return MessagePart|null Text part.
     */
    public function getTextPart(): ?MessagePart
    {
        foreach ($this->parts as $part) {
            if ($part->isText()) {
                return $part;
            }
        }

        return null;
    }

    /**
     * Get attachment parts.
     *
     * @return MessagePart[] Attachments.
     */
    public function getAttachments(): array
    {
        return array_values(
            array_filter(
                $this->parts,
                static fn (MessagePart $part): bool => $part->isAttachment()
            )
        );
    }

    /**
     * Get the raw message size.
     *
     * @return int Size in bytes.
     */
    public function getSize(): int
    {
        return strlen($this->message);
    }

    /**
     * Convert the message to an array.
     *
     * @return array<string, mixed> Message data.
     */
    public function toArray(): array
    {
        $date = $this->getDate();

        return [
            'id' => $this->getId(),
            'subject' => $this->getSubject(),
            'from' => $this->getFrom(),
            'to' => $this->getTo(),
            'reply_to' => $this->getReplyTo(),
            'date' => $date ? $date->format('c') : null,
            'headers' => $this->getHeaders(),
            'parts' => array_map(
                static fn (MessagePart $part): array => $part->toArray(),
                $this->getParts()
            ),
        ];
    }

    /**
     * Specify JSON data.
     *
     * @return array<string, mixed> Message data.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Parse the raw message.
     *
     * @return void
     */
    protected function parse(): void
    {
        [$headerBlock, $body] = $this->splitHeaderAndBody(
            $this->removeLeadingNoise($this->message)
        );
        $this->headers = $this->parseHeaders($headerBlock);
        $contentType = $this->getContentType();
        $isMultipart = str_starts_with(strtolower($contentType), 'multipart/');
        $this->boundary = $this->extractParameter($contentType, 'boundary');

        if ($isMultipart && $this->boundary === null) {
            $this->boundary = $this->inferBoundary($body);
        }

        if ($isMultipart && $this->boundary !== null) {
            foreach ($this->splitMultipartBody($body, $this->boundary) as $rawPart) {
                [$partHeaderBlock, $partBody] = $this->splitHeaderAndBody($rawPart);
                $partHeaders = $this->parseHeaders($partHeaderBlock);

                if ($partHeaders === [] && trim($partBody) === '') {
                    continue;
                }

                $this->addPart($this->trimPartLineEndings($partBody), $partHeaders);
            }

            return;
        }

        $partHeaders = $this->extractContentHeaders($this->headers);
        $contentTypeKey = $this->findHeaderKey($partHeaders, 'Content-Type');

        foreach (array_keys($partHeaders) as $contentHeaderKey) {
            unset($this->headers[$contentHeaderKey]);
        }

        if ($contentTypeKey !== null) {
            $this->headers[$contentTypeKey] = $partHeaders[$contentTypeKey];
        } else {
            $partHeaders['Content-Type'] = 'text/plain; charset=us-ascii';
        }

        $this->addPart($this->trimPartLineEndings($body), $partHeaders);
    }

    /**
     * Add a parsed message part.
     *
     * @param string                $body    Part body.
     * @param array<string, string> $headers Part headers.
     *
     * @return void
     */
    protected function addPart(string $body, array $headers): void
    {
        $contentTypeKey = $this->findHeaderKey($headers, 'Content-Type');
        $contentType = $contentTypeKey === null ? '' : $this->unfoldHeaderValue($headers[$contentTypeKey]);

        if (str_starts_with(strtolower($contentType), 'multipart/')) {
            $innerMessage = $this->buildHeaderBlock($headers) . "\r\n\r\n" . $body;
            $this->context->depth++;

            try {
                $innerParser = new self(
                    $innerMessage,
                    $this->ignoreSignature,
                    $this->options,
                    $this->context
                );

                foreach ($innerParser->getParts() as $innerPart) {
                    $this->parts[] = $innerPart;
                }
            } finally {
                $this->context->depth--;
            }

            return;
        }

        if ($this->ignoreSignature && str_starts_with(strtolower($contentType), 'text/plain')) {
            $body = $this->stripSignature($body);
        }

        $this->context->partCount++;
        $this->assertWithinLimit(
            'maxParts',
            $this->options->maxParts,
            $this->context->partCount
        );

        $part = new MessagePart($body, $headers);
        $decodedSize = strlen($part->getContent());
        $this->assertWithinLimit(
            'maxDecodedPartBytes',
            $this->options->maxDecodedPartBytes,
            $decodedSize
        );

        $this->parts[] = $part;
    }

    /**
     * Parse an RFC-style header block.
     *
     * @param string $headerBlock Raw header block.
     *
     * @return array<string, string> Parsed headers.
     */
    protected function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        $currentKey = null;
        $lines = preg_split('/\r\n|\n|\r/', $headerBlock) ?: [];

        foreach ($lines as $line) {
            $this->assertWithinLimit(
                'maxHeaderLineLength',
                $this->options->maxHeaderLineLength,
                strlen($line)
            );

            if ($currentKey !== null && preg_match('/^[\t ]/', $line)) {
                $headers[$currentKey] .= "\n" . $line;
                continue;
            }

            if (
                !preg_match(
                    "/^(?<key>[!#$%&'*+\\-.^_`|~0-9A-Za-z]+):[\\t ]*(?<value>.*)$/",
                    $line,
                    $matches
                )
            ) {
                $currentKey = null;
                continue;
            }

            $existingKey = $this->findHeaderKey($headers, $matches['key']);

            if ($existingKey !== null) {
                $headers[$existingKey] .= "\n" . $matches['value'];
                $currentKey = $existingKey;
                continue;
            }

            $headers[$matches['key']] = $matches['value'];
            $currentKey = $matches['key'];

            $this->assertWithinLimit(
                'maxHeaders',
                $this->options->maxHeaders,
                count($headers)
            );
        }

        return $headers;
    }

    /**
     * Throw when a measured value exceeds a configured limit.
     *
     * @param string $limitName   Option name.
     * @param int    $limitValue  Configured maximum.
     * @param int    $actualValue Observed value.
     *
     * @throws ParserLimitExceededException When actualValue is greater than limitValue.
     *
     * @return void
     */
    protected function assertWithinLimit(string $limitName, int $limitValue, int $actualValue): void
    {
        if ($actualValue > $limitValue) {
            throw new ParserLimitExceededException($limitName, $limitValue, $actualValue);
        }
    }

    /**
     * Get a header value guaranteed to be a string.
     *
     * @param string $header Header name.
     *
     * @return string Header value or an empty string.
     */
    protected function getStringHeader(string $header): string
    {
        $value = $this->getHeader($header, '');

        return is_string($value) ? $value : '';
    }

    /**
     * Get a decoded header value guaranteed to be a string.
     *
     * @param string $header Header name.
     *
     * @return string Decoded header value or an empty string.
     */
    protected function getDecodedStringHeader(string $header): string
    {
        $value = $this->getDecodedHeader($header, '');

        return is_string($value) ? $value : '';
    }

    /**
     * Split headers and body at the first blank line.
     *
     * @param string $message Raw message or MIME part.
     *
     * @return array{0: string, 1: string} Header block and body.
     */
    protected function splitHeaderAndBody(string $message): array
    {
        if (!preg_match('/\r\n\r\n|\n\n|\r\r/', $message, $matches, PREG_OFFSET_CAPTURE)) {
            return [$message, ''];
        }

        $separator = $matches[0][0];
        $offset = $matches[0][1];

        return [
            substr($message, 0, $offset),
            substr($message, $offset + strlen($separator)),
        ];
    }

    /**
     * Remove content preceding the first valid header.
     *
     * @param string $message Raw message.
     *
     * @return string Message beginning with a header.
     */
    protected function removeLeadingNoise(string $message): string
    {
        $headerPattern = "[!#$%&'*+\\-.^_`|~0-9A-Za-z]+:[\\t ]*";

        if (preg_match('/^' . $headerPattern . '/', $message)) {
            return $message;
        }

        if (
            preg_match(
                '/(?:\r\n|\n|\r)(?<header>' . $headerPattern . ')/',
                $message,
                $matches,
                PREG_OFFSET_CAPTURE
            )
        ) {
            return substr($message, $matches['header'][1]);
        }

        return $message;
    }

    /**
     * Split a multipart body into child parts.
     *
     * Correct messages place delimiters on their own lines. Real-world messages
     * sometimes append a delimiter to the previous content line; that form is
     * also supported via str_ends_with matching (same behaviour as the legacy
     * parser). An explode-based fallback remains for other malformed layouts.
     *
     * @param string $body     Multipart body.
     * @param string $boundary Multipart boundary.
     *
     * @return array<int, string> Raw child parts.
     */
    protected function splitMultipartBody(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $closeDelimiter = $delimiter . '--';
        $lines = preg_split('/\r\n|\n|\r/', $body) ?: [];
        $parts = [];
        $currentLines = [];
        $collecting = false;
        $foundClosingDelimiter = false;

        foreach ($lines as $line) {
            $trimmedLine = rtrim($line, "\t ");

            // Closing delimiter may share the line with the last content bytes.
            if (str_ends_with($trimmedLine, $closeDelimiter)) {
                if ($collecting) {
                    $prefix = substr($trimmedLine, 0, -strlen($closeDelimiter));

                    if ($prefix !== '') {
                        $currentLines[] = $prefix;
                    }

                    $parts[] = implode("\n", $currentLines);
                    $currentLines = [];
                }

                $foundClosingDelimiter = true;
                break;
            }

            // Part delimiter may also appear at the end of a content line.
            if (str_ends_with($trimmedLine, $delimiter)) {
                if ($collecting) {
                    $prefix = substr($trimmedLine, 0, -strlen($delimiter));

                    if ($prefix !== '') {
                        $currentLines[] = $prefix;
                    }

                    $parts[] = implode("\n", $currentLines);
                    $currentLines = [];
                }

                $collecting = true;
                continue;
            }

            if ($collecting) {
                $currentLines[] = $line;
            }
        }

        if ($collecting && $currentLines !== []) {
            $parts[] = implode("\n", $currentLines);
        }

        if ($parts !== [] && ($foundClosingDelimiter || substr_count($body, $delimiter) === 1)) {
            return $parts;
        }

        $parts = [];
        $chunks = explode($delimiter, $body);

        foreach (array_slice($chunks, 1) as $chunk) {
            if (str_starts_with($chunk, '--')) {
                break;
            }

            $chunk = trim($chunk, "\r\n");

            if ($chunk !== '') {
                $parts[] = $chunk;
            }
        }

        return $parts;
    }

    /**
     * Extract a parameter from a MIME header.
     *
     * @param string $header    Header value.
     * @param string $parameter Parameter name.
     *
     * @return string|null Parameter value.
     */
    protected function extractParameter(string $header, string $parameter): ?string
    {
        $pattern = '/(?:^|;)\s*'
            . preg_quote($parameter, '/')
            . '\s*=\s*(?:"(?<quoted>(?:\\\\.|[^"])*)"|(?<plain>[^;\s]+))/i';

        if (!preg_match($pattern, $header, $matches)) {
            return null;
        }

        $value = $matches['quoted'] !== '' ? $matches['quoted'] : $matches['plain'];

        return stripcslashes($value);
    }

    /**
     * Infer a boundary from a delimiter line.
     *
     * @param string $body Multipart body.
     *
     * @return string|null Boundary.
     */
    protected function inferBoundary(string $body): ?string
    {
        if (preg_match('/^--(?<boundary>[^\r\n]+?)(?:--)?[\t ]*\r?$/m', $body, $matches)) {
            return $matches['boundary'];
        }

        return null;
    }

    /**
     * Extract Content-* headers from message headers.
     *
     * @param array<string, string> $headers Message headers.
     *
     * @return array<string, string> Content headers.
     */
    protected function extractContentHeaders(array $headers): array
    {
        return array_filter(
            $headers,
            static fn (string $key): bool => str_starts_with(strtolower($key), 'content-'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Find a case-insensitive header key.
     *
     * @param array<string, string> $headers Headers.
     * @param string                $header  Header name.
     *
     * @return string|null Original key.
     */
    protected function findHeaderKey(array $headers, string $header): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $header) === 0) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Unfold a header value according to RFC 5322.
     *
     * @param string $value Raw value.
     *
     * @return string Unfolded value.
     */
    protected function unfoldHeaderValue(string $value): string
    {
        return preg_replace('/\n[\t ]+/', ' ', $value) ?? $value;
    }

    /**
     * Build a raw header block.
     *
     * @param array<string, string> $headers Parsed headers.
     *
     * @return string Header block.
     */
    protected function buildHeaderBlock(array $headers): string
    {
        $lines = [];

        foreach ($headers as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }

        return implode("\r\n", $lines);
    }

    /**
     * Remove MIME framing line endings from a body.
     *
     * @param string $body Part body.
     *
     * @return string Body without framing line endings.
     */
    protected function trimPartLineEndings(string $body): string
    {
        return rtrim($body, "\r\n");
    }

    /**
     * Strip the plain-text signature delimiter and following content.
     *
     * @param string $body Plain-text body.
     *
     * @return string Body without signature.
     */
    protected function stripSignature(string $body): string
    {
        if (preg_match('/^-- ?\r?$/m', $body, $matches, PREG_OFFSET_CAPTURE)) {
            return rtrim(substr($body, 0, $matches[0][1]));
        }

        return $body;
    }
}
