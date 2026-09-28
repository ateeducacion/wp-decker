<?php

/**
 * Backwards-compatible aliases for the pre-namespace public API.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */

declare(strict_types=1);

namespace Erseco;

$legacyAliases = [
    Message::class => \Erseco\MimeMailParser\Message::class,
    MessagePart::class => \Erseco\MimeMailParser\MessagePart::class,
    ParserContext::class => \Erseco\MimeMailParser\ParserContext::class,
    ParserLimitExceededException::class => \Erseco\MimeMailParser\ParserLimitExceededException::class,
    ParserOptions::class => \Erseco\MimeMailParser\ParserOptions::class,
    Rfc2047::class => \Erseco\MimeMailParser\Rfc2047::class,
];

foreach ($legacyAliases as $legacyClass => $currentClass) {
    if (!class_exists($legacyClass, false)) {
        class_alias($currentClass, $legacyClass);
    }
}

unset($legacyAliases, $legacyClass, $currentClass);
