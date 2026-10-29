<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'http.php';
$config = \Misseye\loadConfig();
if ($config['whatsapp'] === null) {
    fwrite(STDERR, "WhatsApp Cloud API is not configured.\n");
    exit(1);
}
$store = new \Misseye\BookingStore($config['data_file']);
$store->recoverNotifications(true);
foreach ($store->pending() as $booking) {
    \Misseye\notify($store, $booking['id'], $config['whatsapp']);
}
echo "Pending notifications processed. Check outcomes in the owner panel.\n";
