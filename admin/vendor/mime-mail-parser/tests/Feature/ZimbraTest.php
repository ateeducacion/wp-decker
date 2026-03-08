<?php

/**
 * Zimbra tests for MimeMailParser class
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
    'can parse a zimbra message',
    function () {
        $message = Message::fromFile(__DIR__ . '/../Fixtures/raw_email_from_zimbra.eml');

        expect($message->getFrom())->toBe('Test User 2 <test2@example.com>')
        ->and($message->getTo())->toBe('taskwp <receiver@example.com>')
        ->and($message->getSubject())->toBe('test from zimbra')
        ->and($message->getContentType())->toContain('multipart/alternative');

        $parts = $message->getParts();
        expect($parts)->toHaveCount(2)
            ->and($parts[0]->getContentType())->toBe('text/plain; charset=utf-8')
            ->and($parts[0]->getContent())->toContain('this is a mail from zimbra')
            ->and($parts[1]->getContentType())->toBe('text/html; charset=utf-8')
            ->and($parts[1]->getContent())->toContain('this is a mail from zimbra');
    }
);

test(
    'can parse a zimbra message with attachments',
    function () {
        $message = Message::fromFile(__DIR__ . '/../Fixtures/raw_email_from_zimbra_attachments.eml');

        expect($message->getFrom())->toBe('Test User 2 <test2@example.com>')
        ->and($message->getTo())->toBe('taskwp <receiver@example.com>')
        ->and($message->getSubject())->toBe('test from zimbra with attachments');

        $attachments = $message->getAttachments();
        expect($attachments)->toHaveCount(2)
            ->and($attachments[0]->getFilename())->toBe('sample-2.jpg')
            ->and($attachments[0]->getContentType())->toContain('image/jpeg');
    }
);

test(
    'can parse a zimbra message with embedded content',
    function () {
        $message = Message::fromFile(__DIR__ . '/../Fixtures/raw_email_from_zimbra_embedded.eml');

        expect($message->getFrom())->toBe('Test User 2 <test2@example.com>')
        ->and($message->getTo())->toBe('taskwp <receiver@example.com>')
        ->and($message->getSubject())->toBe('test from zimbra with embedded image');

        $parts = $message->getParts();
        expect($parts)->toHaveCount(3)
            ->and($parts[0]->getContentType())->toContain('text/plain')
            ->and($parts[1]->getContentType())->toContain('text/html')
            ->and($parts[2]->getContentType())->toContain('image/jpeg');
    }
);
