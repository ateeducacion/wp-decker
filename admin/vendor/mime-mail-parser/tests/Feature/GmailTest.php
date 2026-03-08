<?php

/**
 * Gmail tests for MimeMailParser class
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
    'can parse a simple gmail message',
    function () {
        $message = Message::fromFile(__DIR__ . '/../Fixtures/raw_email_from_gmail.eml');

        expect($message->getFrom())->toBe('Test User 1 <test1@example.com>')
        ->and($message->getTo())->toBe('receiver@example.com')
        ->and($message->getSubject())->toBe('test from gmail')
        ->and($message->getId())->toBe('CA+7vVz36DwsdbzoRus_xujDUM_iZ4dY7bLJ8eG1bCXXX38Sn9Q@mail.gmail.com')
        ->and($message->getDate()?->format('Y-m-d H:i:s'))->toBe('2024-12-04 15:34:38')
        ->and($message->getContentType())->toBe('multipart/alternative; boundary="0000000000008f6e3a06287386f8"');

        $parts = $message->getParts();
        expect($parts)->toHaveCount(2)
            ->and($parts[0]->getContentType())->toBe('text/plain; charset="UTF-8"')
            ->and($parts[0]->getContent())->toBe('this is a mail from gmail')
            ->and($parts[1]->getContentType())->toBe('text/html; charset="UTF-8"')
            ->and($parts[1]->getContent())->toContain('<div><strong>this is a mail from gmail</strong></div>');
    }
);

test(
    'can parse a gmail message with attachments',
    function () {
        $message = Message::fromFile(__DIR__ . '/../Fixtures/raw_email_from_gmail_attachments.eml');

        expect($message->getFrom())->toBe('Test User 1 <test1@example.com>')
        ->and($message->getTo())->toBe('receiver@example.com')
        ->and($message->getSubject())->toBe('test from gmail with attachments')
        ->and($message->getDate()?->format('Y-m-d H:i:s'))->toBe('2024-12-04 15:47:13');

        $parts = $message->getParts();
        expect($parts)->toHaveCount(4)
            ->and($parts[0]->getContentType())->toContain('text/plain')
            ->and($parts[1]->getContentType())->toContain('text/html')
            ->and($parts[2]->getContentType())->toContain('image/jpeg')
            ->and($parts[3]->getContentType())->toContain('application/pdf');

        $attachments = $message->getAttachments();
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0]->getFilename())->toBe('sample-1-small.pdf')
            ->and($attachments[0]->getContentType())->toBe('application/pdf; name="sample-1-small.pdf"');
    }
);

test(
    'can parse a gmail message with GB encoding',
    function () {
        $message = Message::fromFile(__DIR__ . '/../Fixtures/raw_email_from_gmail_gb.eml');

        expect($message->getFrom())->toBe('Test User 2 <test2@example.com>')
        ->and($message->getTo())->toBe('receiver@example.com')
        ->and($message->getSubject())->toBe('test from gmail gb')
        ->and($message->getContentType())->toContain('multipart/alternative');

        $parts = $message->getParts();
        expect($parts)->toHaveCount(2)
            ->and($parts[0]->getContentType())->toContain('text/plain')
            ->and($parts[1]->getContentType())->toContain('text/html');
    }
);
