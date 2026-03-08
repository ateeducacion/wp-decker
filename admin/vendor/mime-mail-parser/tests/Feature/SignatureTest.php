<?php

/**
 * Signature ignore tests for MimeMailParser class
 *
 * @category Tests
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */

namespace Tests\Feature;

use Erseco\Message;

test(
    'keeps signature when ignoreSignature is false',
    function () {
        $message = Message::fromFile(__DIR__ . '/../Fixtures/raw_email_with_signature.eml');

        $textPart = $message->getTextPart();
        expect($textPart)->not->toBeNull();
        expect($textPart->getContent())->toContain('--');
        expect($textPart->getContent())->toContain('Test User');
    }
);

test(
    'removes signature when ignoreSignature is true via fromFile',
    function () {
        $message = Message::fromFile(__DIR__ . '/../Fixtures/raw_email_with_signature.eml', ignoreSignature: true);

        $textPart = $message->getTextPart();
        expect($textPart)->not->toBeNull();
        expect($textPart->getContent())->toBe('This is the main body of the email.');
        expect($textPart->getContent())->not->toContain('Test User');
    }
);

test(
    'removes signature when ignoreSignature is true via fromString',
    function () {
        $raw = file_get_contents(__DIR__ . '/../Fixtures/raw_email_with_signature.eml');
        $message = Message::fromString($raw, ignoreSignature: true);

        $textPart = $message->getTextPart();
        expect($textPart)->not->toBeNull();
        expect($textPart->getContent())->toBe('This is the main body of the email.');
        expect($textPart->getContent())->not->toContain('Test User');
    }
);

test(
    'removes signature when ignoreSignature is true via constructor',
    function () {
        $raw = file_get_contents(__DIR__ . '/../Fixtures/raw_email_with_signature.eml');
        $message = new Message($raw, ignoreSignature: true);

        $textPart = $message->getTextPart();
        expect($textPart)->not->toBeNull();
        expect($textPart->getContent())->toBe('This is the main body of the email.');
    }
);

test(
    'does not affect html part when ignoreSignature is true',
    function () {
        $message = Message::fromFile(__DIR__ . '/../Fixtures/raw_email_with_signature.eml', ignoreSignature: true);

        $htmlPart = $message->getHtmlPart();
        expect($htmlPart)->not->toBeNull();
        expect($htmlPart->getContent())->toContain('<div>This is the main body of the email.</div>');
    }
);
