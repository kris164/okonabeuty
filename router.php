<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'http.php';
\Misseye\securityHeaders();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) {
    \Misseye\serveApi();
    return;
}
$files = [
    '/' => ['index.html', 'text/html; charset=utf-8'],
    '/index.html' => ['index.html', 'text/html; charset=utf-8'],
    '/admin' => ['admin.html', 'text/html; charset=utf-8'],
    '/admin/' => ['admin.html', 'text/html; charset=utf-8'],
    '/admin.html' => ['admin.html', 'text/html; charset=utf-8'],
    '/booking.js' => ['booking.js', 'text/javascript; charset=utf-8'],
    '/booking.css' => ['booking.css', 'text/css; charset=utf-8'],
    '/admin.js' => ['admin.js', 'text/javascript; charset=utf-8'],
];
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true) || !isset($files[$path])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nie znaleziono strony.';
    return;
}
[$file, $type] = $files[$path];
header('Content-Type: ' . $type);
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    readfile(__DIR__ . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . $file);
}
