<?php

/**
 * Shared mutable parser state for nested MIME parsing.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */

namespace Erseco;

/**
 * Tracks counters that must be shared across nested Message parsers.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */
final class ParserContext
{
    public int $partCount = 0;

    public int $depth = 0;

    /**
     * @param ParserOptions $options Active parser limits.
     */
    public function __construct(public ParserOptions $options)
    {
    }
}
