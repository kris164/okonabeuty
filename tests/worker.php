<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'booking.php';

[$script, $file, $barrier, $payload] = $argv;
$deadline = microtime(true) + 10;
while (!is_file($barrier)) {
    clearstatcache(true, $barrier);
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency test barrier timed out.');
    }
    usleep(1000);
}
$store = new \Misseye\BookingStore($file);
try {
    $result = $store->reserve(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), false, new DateTimeImmutable('2026-10-29T06:00:00Z'));
    echo json_encode(['status' => $result['created'] ? 201 : 200], JSON_THROW_ON_ERROR);
} catch (\Misseye\HttpError $error) {
    echo json_encode(['status' => $error->status], JSON_THROW_ON_ERROR);
}
