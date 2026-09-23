<?php

/**
 * Test-only HTTP sink: appends every request (method, path, headers, body) as one JSON line.
 */

declare(strict_types=1);

$entry = [
    'time' => gmdate('c'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'body' => (string) file_get_contents('php://input'),
];
file_put_contents(__DIR__.'/requests.log', json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
echo '{"ok":true}';
