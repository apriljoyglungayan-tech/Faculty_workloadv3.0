<?php
declare(strict_types=1);

$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if (preg_match('#^/api(?:/(.*))?$#', $requestPath, $matches)) {
    $_GET['path'] = $matches[1] ?? '';
    require __DIR__ . '/api.php';
    return true;
}

$relativePath = ltrim($requestPath, '/');
$assetPath = __DIR__ . '/dist/' . $relativePath;
$resolvedAsset = realpath($assetPath);
$distRoot = realpath(__DIR__ . '/dist');
if (
    $relativePath !== ''
    && $resolvedAsset !== false
    && $distRoot !== false
    && str_starts_with($resolvedAsset, $distRoot . DIRECTORY_SEPARATOR)
    && is_file($resolvedAsset)
) {
    $extension = strtolower(pathinfo($resolvedAsset, PATHINFO_EXTENSION));
    $mimeTypes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];
    header('Content-Type: ' . ($mimeTypes[$extension] ?? (mime_content_type($resolvedAsset) ?: 'application/octet-stream')));
    readfile($resolvedAsset);
    return true;
}

require __DIR__ . '/index.php';
return true;
