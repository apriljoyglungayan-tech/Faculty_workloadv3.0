<?php
declare(strict_types=1);

$builtIndex = __DIR__ . '/dist/index.html';
$indexFile = is_file($builtIndex) ? $builtIndex : __DIR__ . '/index.html';

if (!is_file($indexFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The frontend build is missing. Build the React app and upload the contents of dist/ to this directory.';
    exit;
}

readfile($indexFile);
