<?php
declare(strict_types=1);

namespace Misseye;

function mediaRange(?string $header, int $size): ?array
{
    if ($header === null || !preg_match('/\Abytes=(\d*)-(\d*)\z/', $header, $match)
        || ($match[1] === '' && $match[2] === '')) {
        return null;
    }
    if ($match[1] === '') {
        $length = (int) $match[2];
        if ($length === 0 || $size === 0) {
            throw new HttpError(416, 'Żądany zakres filmu jest niedostępny.');
        }
        return [max(0, $size - $length), $size - 1];
    }
    $start = (int) $match[1];
    $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);
    if ($start >= $size || $start > $end) {
        throw new HttpError(416, 'Żądany zakres filmu jest niedostępny.');
    }
    return [$start, $end];
}

function serveMedia(string $file, string $type): void
{
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        http_response_code(500);
        error_log('Cannot open public media file.');
        return;
    }
    try {
        $stat = fstat($handle);
        if ($stat === false) {
            throw new \RuntimeException('Cannot read public media size.');
        }
        $size = $stat['size'];
        $modified = gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT';
        header('Content-Type: ' . $type);
        header('Cache-Control: public, max-age=3600');
        header('Last-Modified: ' . $modified);
        header('Accept-Ranges: bytes');
        $useRange = $_SERVER['REQUEST_METHOD'] === 'GET'
            && (!isset($_SERVER['HTTP_IF_RANGE']) || $_SERVER['HTTP_IF_RANGE'] === $modified);
        try {
            $range = mediaRange($useRange ? ($_SERVER['HTTP_RANGE'] ?? null) : null, $size);
        } catch (HttpError $error) {
            http_response_code($error->status);
            header('Content-Range: bytes */' . $size);
            header('Content-Length: 0');
            return;
        }
        [$start, $end] = $range ?? [0, $size - 1];
        if (fseek($handle, $start) !== 0) {
            throw new \RuntimeException('Cannot seek public media file.');
        }
        if ($range !== null) {
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        $remaining = $size === 0 ? 0 : $end - $start + 1;
        header('Content-Length: ' . $remaining);
        if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
            return;
        }
        while ($remaining > 0 && !connection_aborted()) {
            $chunk = fread($handle, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Cannot read public media bytes.');
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
    } catch (\RuntimeException $error) {
        error_log($error->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
            header_remove('Content-Range');
            header('Content-Length: 0');
        }
    } finally {
        fclose($handle);
    }
}
