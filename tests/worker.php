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
    $input = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    $now = new DateTimeImmutable('2026-10-29T06:00:00Z');
    if (($input['_operation'] ?? null) === 'block') {
        $store->addBlock($input, $now);
        $status = 201;
    } elseif (($input['_operation'] ?? null) === 'reschedule') {
        $store->reschedule($input['id'], $input, $now);
        $status = 200;
    } elseif (($input['_operation'] ?? null) === 'offer') {
        unset($input['_operation']);
        $store->saveOffer($input, $now);
        $status = 200;
    } elseif (($input['_operation'] ?? null) === 'email-claim') {
        $status = $store->claimEmailReminder($input['id'], $input['starts'], $now) === null ? 409 : 200;
    } else {
        $result = $store->reserve($input, false, $now);
        $status = $result['created'] ? 201 : 200;
    }
    echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
} catch (\Misseye\HttpError $error) {
    echo json_encode(['status' => $error->status], JSON_THROW_ON_ERROR);
}
