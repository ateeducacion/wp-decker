<?php

/**
 * Backwards-compatible loader for the Message class.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */

declare(strict_types=1);

require_once __DIR__ . '/Message.php';

if (!class_exists(\Erseco\Message::class, false)) {
    class_alias(\Erseco\MimeMailParser\Message::class, \Erseco\Message::class);
}
