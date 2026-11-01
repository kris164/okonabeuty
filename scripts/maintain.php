<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'http.php';

try {
    $config = \Misseye\loadConfig();
    $pdo = $config['storage'] === 'mysql' ? \Misseye\connectMysql($config['database']) : null;
    $store = \Misseye\bookingStore($config, $pdo);
    $now = new DateTimeImmutable();
    $report = [
        'whatsappRecovered' => $store->recoverNotifications($config['whatsapp'] !== null, \Misseye\milliseconds($now)),
        'emailUnknown' => $store->recoverEmailReminders($now),
    ];
    if ($pdo !== null) {
        $statement = $pdo->prepare('DELETE FROM misseye_rates WHERE expires <= ?');
        $statement->execute([$now->getTimestamp()]);
        $report['expiredRatesRemoved'] = $statement->rowCount();
    }
    echo json_encode($report, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Maintenance failed: ' . $error->getMessage() . "\n");
    exit(2);
}
