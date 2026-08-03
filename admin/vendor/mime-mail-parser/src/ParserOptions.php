<?php

/**
 * Configurable safety limits for the MIME message parser.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */

namespace Erseco;

/**
 * Parser safety limits.
 *
 * Defaults are generous enough for ordinary email while bounding pathological
 * input. Existing constructors keep these defaults when no options are passed.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */
final class ParserOptions
{
    public const DEFAULT_MAX_MESSAGE_BYTES = 10485760;

    public const DEFAULT_MAX_PARTS = 1000;

    public const DEFAULT_MAX_DEPTH = 20;

    public const DEFAULT_MAX_HEADERS = 500;

    public const DEFAULT_MAX_HEADER_LINE_LENGTH = 998;

    public const DEFAULT_MAX_DECODED_PART_BYTES = 10485760;

    /**
     * @param int $maxMessageBytes       Maximum raw message size in bytes.
     * @param int $maxParts              Maximum number of leaf MIME parts.
     * @param int $maxDepth              Maximum nested multipart depth.
     * @param int $maxHeaders            Maximum headers per header block.
     * @param int $maxHeaderLineLength   Maximum length of a single header line.
     * @param int $maxDecodedPartBytes   Maximum decoded size of any leaf part.
     */
    public function __construct(
        public int $maxMessageBytes = self::DEFAULT_MAX_MESSAGE_BYTES,
        public int $maxParts = self::DEFAULT_MAX_PARTS,
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxHeaders = self::DEFAULT_MAX_HEADERS,
        public int $maxHeaderLineLength = self::DEFAULT_MAX_HEADER_LINE_LENGTH,
        public int $maxDecodedPartBytes = self::DEFAULT_MAX_DECODED_PART_BYTES
    ) {
    }

    /**
     * Create options with the package defaults.
     *
     * @return self
     */
    public static function defaults(): self
    {
        return new self();
    }
}
