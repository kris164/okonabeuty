<?php
declare(strict_types=1);

namespace Misseye;

use DateTimeImmutable;
use RuntimeException;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'booking.php';

function assertPrivatePath(string $file): void
{
    $parent = realpath(dirname($file));
    $public = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'www');
    if ($parent === false || $public === false) {
        throw new RuntimeException('Storage directory is missing. Run CLI setup first.');
    }
    $resolved = realpath($file);
    $path = $resolved === false ? $parent . DIRECTORY_SEPARATOR . basename($file) : $resolved;
    if (PHP_OS_FAMILY === 'Windows') {
        $path = strtolower($path);
        $public = strtolower($public);
    }
    if ($path === $public || str_starts_with($path, $public . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Private data must be outside the public document root.');
    }
}

function loadConfig(?string $file = null): array
{
    $file ??= getenv('MISSEYE_CONFIG') ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($file)) {
        throw new HttpError(503, 'Rezerwacje nie zostały jeszcze skonfigurowane. Skontaktuj się z salonem.');
    }
    $config = require $file;
    if (!is_array($config) || !is_string($config['admin_token'] ?? null) || strlen($config['admin_token']) < 32
        || !is_string($config['origin'] ?? null) || !is_string($config['data_file'] ?? null)
        || !is_array($config['whatsapp'] ?? null)) {
        throw new RuntimeException('Invalid private configuration.');
    }
    $url = parse_url($config['origin']);
    if ($url === false || !in_array($url['scheme'] ?? '', ['https', 'http'], true)
        || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
        || (isset($url['path']) && $url['path'] !== '' && $url['path'] !== '/')) {
        throw new RuntimeException('Configure origin as an HTTP(S) origin, without a path.');
    }
    $config['origin'] = rtrim($config['origin'], '/');
    assertPrivatePath($config['data_file']);
    $config['rate_file'] = dirname($config['data_file']) . DIRECTORY_SEPARATOR . 'rate-limits.json';
    assertPrivatePath($config['rate_file']);
    $config['whatsapp'] = whatsappConfig($config['whatsapp']);
    return $config;
}

function securityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
}

function rateLimit(array $config, string $address): void
{
    $file = new JsonFile($config['rate_file'], static function (mixed $data): void {
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['clients'] ?? null)) {
            throw new RuntimeException('Invalid rate limit storage.');
        }
        foreach ($data['clients'] as $key => $value) {
            if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/', $key) || !is_array($value)
                || !is_int($value['reset'] ?? null) || !is_int($value['count'] ?? null) || $value['count'] < 1) {
                throw new RuntimeException('Invalid rate limit record.');
            }
        }
    });
    $file->initialize(['version' => 1, 'clients' => []]);
    $key = hash_hmac('sha256', $address, $config['admin_token']);
    $file->write(static function (array &$data) use ($key): void {
        $now = time();
        $data['clients'] = array_filter($data['clients'], static fn ($value) => $value['reset'] > $now);
        if (!isset($data['clients'][$key])) {
            if (count($data['clients']) >= 2000) {
                throw new HttpError(429, 'Zbyt wiele żądań. Spróbuj później.');
            }
            $data['clients'][$key] = ['count' => 1, 'reset' => $now + 60];
        } elseif ($data['clients'][$key]['count'] >= 120) {
            throw new HttpError(429, 'Zbyt wiele żądań. Spróbuj za minutę.');
        } else {
            $data['clients'][$key]['count']++;
        }
    });
}

function handleApi(
    string $method,
    string $path,
    array $query,
    array $headers,
    string $body,
    array $config,
    string $address,
    ?callable $sender = null
): array {
    if ($method === 'POST' && isset($headers['origin']) && $headers['origin'] !== $config['origin']) {
        throw new HttpError(403, 'Niedozwolone źródło żądania.');
    }
    rateLimit($config, $address);
    if (str_starts_with($path, '/api/admin/')) {
        $authorization = $headers['authorization'] ?? '';
        if (!str_starts_with($authorization, 'Bearer ')
            || !hash_equals(hash('sha256', $config['admin_token']), hash('sha256', substr($authorization, 7)))) {
            throw new HttpError(401, 'Nieprawidłowy klucz panelu.');
        }
    }
    $store = new BookingStore($config['data_file']);
    $store->recoverNotifications($config['whatsapp'] !== null);
    if ($method === 'GET' && $path === '/api/config') {
        $now = new DateTimeImmutable();
        return [200, [
            'services' => services(), 'specialists' => specialists(),
            'today' => localDate($now), 'maxDate' => lastDate($now),
            'timezone' => ZONE, 'whatsappNumber' => WHATSAPP_NUMBER,
            'automatedWhatsApp' => $config['whatsapp'] !== null,
        ]];
    }
    if ($method === 'GET' && $path === '/api/availability') {
        return [200, ['slots' => $store->availability($query['date'] ?? null, $query['service'] ?? null)]];
    }
    if ($method === 'GET' && preg_match('~\A/api/bookings/([a-f0-9-]{36})\z~', $path, $match)) {
        $booking = $store->get($match[1]);
        if ($booking === null) {
            throw new HttpError(404, 'Nie znaleziono rezerwacji.');
        }
        return [200, ['booking' => publicBooking($booking)]];
    }
    if ($method === 'POST' && $path === '/api/bookings') {
        if (strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])) !== 'application/json') {
            throw new HttpError(415, 'Wymagany format JSON.');
        }
        if (strlen($body) > 4096) {
            throw new HttpError(413, 'Formularz jest zbyt duży.');
        }
        try {
            $input = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new HttpError(400, 'Nieprawidłowy JSON.');
        }
        $saved = $store->reserve($input, $config['whatsapp'] !== null);
        notify($store, $saved['booking']['id'], $config['whatsapp'], $sender);
        return [$saved['created'] ? 201 : 200, ['booking' => publicBooking($store->get($saved['booking']['id']))]];
    }
    if ($method === 'GET' && $path === '/api/admin/bookings') {
        return [200, [
            'bookings' => $store->list($query['date'] ?? null),
            'services' => services(), 'specialists' => specialists(),
        ]];
    }
    if ($method === 'POST' && preg_match('~\A/api/admin/bookings/([a-f0-9-]{36})/cancel\z~', $path, $match)) {
        return [200, ['booking' => $store->cancel($match[1])]];
    }
    throw new HttpError(404, 'Nie znaleziono strony.');
}

function serveApi(): void
{
    ini_set('display_errors', '0');
    securityHeaders();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read request body.');
        }
        try {
            $body = stream_get_contents($stream, 4097);
        } finally {
            fclose($stream);
        }
        if ($body === false) {
            throw new RuntimeException('Cannot read request body.');
        }
        [$status, $payload] = handleApi(
            $_SERVER['REQUEST_METHOD'],
            parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
            $_GET,
            array_filter([
                'origin' => $_SERVER['HTTP_ORIGIN'] ?? null,
                'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
                'content-type' => $_SERVER['CONTENT_TYPE'] ?? null,
            ], static fn ($value) => $value !== null),
            $body,
            loadConfig(),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        );
    } catch (HttpError $error) {
        $status = $error->status;
        $payload = ['error' => $error->getMessage()];
    } catch (\Throwable $error) {
        error_log('MISSEYE request failed: ' . get_class($error) . ': ' . $error->getMessage());
        $status = 500;
        $payload = ['error' => 'Błąd serwera. Nie twórz drugiej rezerwacji; skontaktuj się z salonem.'];
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
