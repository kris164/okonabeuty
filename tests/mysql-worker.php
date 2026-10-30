<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'http.php';

[$script, $barrier, $payload] = $argv;
$config = \Misseye\loadConfig(getenv('MISSEYE_MYSQL_TEST_CONFIG') ?: throw new RuntimeException('Missing test config.'));
if ($config['database']['host'] !== '127.0.0.1' || !str_starts_with($config['database']['name'], 'misseye_test_')) {
    throw new RuntimeException('Workers require an isolated local test database.');
}
$store = \Misseye\bookingStore($config);
$deadline = microtime(true) + 10;
while (!is_file($barrier)) {
    clearstatcache(true, $barrier);
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('MySQL concurrency barrier timed out.');
    }
    usleep(1000);
}
try {
    $input = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    $now = new DateTimeImmutable('2026-10-29T06:00:00Z');
    switch ($input['_operation'] ?? 'reserve') {
        case 'email-claim':
            $status = $store->claimEmailReminder($input['id'], $input['starts'], $now) === null ? 409 : 200;
            break;
        case 'notify-claim':
            $status = $store->claimNotification($input['id']) === null ? 409 : 200;
            break;
        case 'offer':
            unset($input['_operation']);
            $store->saveOffer($input, $now);
            $status = 200;
            break;
        case 'block':
            $store->addBlock($input, $now);
            $status = 201;
            break;
        case 'reschedule':
            $store->reschedule($input['id'], $input, $now);
            $status = 200;
            break;
        case 'rate':
            \Misseye\mysqlRateLimit(\Misseye\connectMysql($config['database']), $input['key'], $input['now']);
            $status = 200;
            break;
        default:
            $status = $store->reserve($input, false, $now)['created'] ? 201 : 200;
    }
    echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
} catch (\Misseye\HttpError $error) {
    echo json_encode(['status' => $error->status], JSON_THROW_ON_ERROR);
}
