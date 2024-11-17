<?php

require_once "includes/class-decker-email-parser.php";

$rawEmail = file_get_contents('tests/mail1.txt'); // Load your raw email content
$emailParser = new Decker_Email_Parser($rawEmail);

$headers = $emailParser->getHeaders();
$htmlContent = $emailParser->getHtmlPart();
$textContent = $emailParser->getTextPart();
$attachments = $emailParser->getAttachments();

// Display the parsed content
echo "Headers:\n";
print_r($headers);

// Display the parsed content
echo "HTML Content:\n$htmlContent\n";
echo "Text Content:\n$textContent\n";

// Process attachments
foreach ($attachments as $attachment) {
    $filename = $attachment['filename'];
    $content = $attachment['content'];
    $mimetype = $attachment['mimetype'];

    // Save attachment to a file
    file_put_contents("tests/".$filename, $content);
    echo "Saved attachment: $filename\n";
}
