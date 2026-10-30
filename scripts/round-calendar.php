<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'http.php';
if (count($argv) > 2 || !in_array($argv[1] ?? '--dry-run', ['--dry-run', '--apply'], true)) {
    fwrite(STDERR, "Usage: php scripts/round-calendar.php [--dry-run|--apply]\n");
    exit(1);
}
$config = \Misseye\loadConfig();
$store = new \Misseye\BookingStore($config['data_file']);
$apply = ($argv[1] ?? '') === '--apply';
$backup = $apply ? $config['data_file'] . '.before-hour-rounding-' . bin2hex(random_bytes(8)) . '.json' : null;
if ($backup !== null) {
    \Misseye\assertPrivatePath($backup);
}
$report = $store->roundFutureBookings($apply, null, $backup);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
if ($report['applied']) {
    echo "Private backup created next to booking storage. Original start times were preserved.\n";
}
if ($report['conflicts'] !== []) {
    fwrite(STDERR, "Conflicts detected. No bookings were changed. Resolve conflicts before applying.\n");
    exit(2);
}
