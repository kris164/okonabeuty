<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'http.php';
$config = \Misseye\loadConfig();
if ($config['email'] === null) {
    fwrite(STDERR, "Email reminders are disabled. Configure hosting mail, sender and reply-to first.\n");
    exit(1);
}
$report = \Misseye\sendDueReminders(\Misseye\bookingStore($config), $config['email']);
echo json_encode($report, JSON_THROW_ON_ERROR) . "\n";
if ($report['failed'] || $report['unknown']) {
    exit(2);
}
