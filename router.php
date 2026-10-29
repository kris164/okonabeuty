<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'http.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'media.php';
\Misseye\securityHeaders();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) {
    \Misseye\serveApi();
    return;
}
if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)
    && preg_match('~\A/media/[a-z0-9-]+\.(mp4|webp)\z~', $path, $media)
    && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'www' . str_replace('/', DIRECTORY_SEPARATOR, $path))) {
    \Misseye\serveMedia(
        __DIR__ . DIRECTORY_SEPARATOR . 'www' . str_replace('/', DIRECTORY_SEPARATOR, $path),
        $media[1] === 'mp4' ? 'video/mp4' : 'image/webp'
    );
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
    '/gallery.js' => ['gallery.js', 'text/javascript; charset=utf-8'],
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
