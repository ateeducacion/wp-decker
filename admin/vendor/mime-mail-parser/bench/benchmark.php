<?php

declare(strict_types=1);

use Erseco\MimeMailParser\Message;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Benchmark one parser scenario.
 *
 * @param string   $name       Scenario name.
 * @param callable $factory    Message factory.
 * @param int      $iterations Number of iterations.
 *
 * @return array{name: string, iterations: int, avg_ms: float, peak_mb: float}
 */
function benchmark(string $name, callable $factory, int $iterations): array
{
    gc_collect_cycles();
    $start = hrtime(true);

    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        $message = Message::fromString($factory());

        if ($message->getParts() === []) {
            throw new RuntimeException('Benchmark message produced no parts.');
        }
    }

    $elapsed = hrtime(true) - $start;

    return [
        'name' => $name,
        'iterations' => $iterations,
        'avg_ms' => ($elapsed / 1_000_000) / $iterations,
        'peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
    ];
}

/**
 * Build a multipart benchmark message.
 *
 * @param int $parts Number of leaf parts.
 *
 * @return string
 */
function multipartMessage(int $parts): string
{
    $boundary = 'benchmark-boundary';
    $raw = "Subject: Multipart benchmark\r\n"
        . "Content-Type: multipart/mixed; boundary={$boundary}\r\n\r\n";

    for ($index = 0; $index < $parts; $index++) {
        $raw .= "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n\r\n"
            . "Part {$index}\r\n";
    }

    return $raw . "--{$boundary}--\r\n";
}

$scenarios = [
    benchmark(
        'simple-10kb',
        static fn (): string => "Subject: Simple\r\nContent-Type: text/plain\r\n\r\n"
            . str_repeat('A', 10 * 1024),
        500
    ),
    benchmark(
        'multipart-100',
        static fn (): string => multipartMessage(100),
        50
    ),
    benchmark(
        'base64-1mb',
        static fn (): string => "Subject: Base64\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . base64_encode(str_repeat('B', 1024 * 1024)),
        10
    ),
];

printf("%-18s %10s %12s %12s\n", 'scenario', 'iterations', 'avg_ms', 'peak_mb');

foreach ($scenarios as $result) {
    printf(
        "%-18s %10d %12.3f %12.2f\n",
        $result['name'],
        $result['iterations'],
        $result['avg_ms'],
        $result['peak_mb']
    );
}
