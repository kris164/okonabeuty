<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'http.php';

try {
    if (count($argv) > 2 || !in_array($argv[1] ?? '--dry-run', ['--dry-run', '--apply'], true)) {
        throw new RuntimeException('Usage: php scripts/upgrade-mysql.php [--dry-run|--apply]');
    }
    $config = \Misseye\loadConfig();
    if ($config['storage'] !== 'mysql') {
        throw new RuntimeException('Schema upgrades require MySQL configuration.');
    }
    $report = \Misseye\migrateMysqlSchema(\Misseye\connectMysql($config['database']), ($argv[1] ?? '') === '--apply');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    echo "Schema only: no empty calendar is initialized. Before --apply, back up MySQL and stop every API/cron writer.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Schema upgrade failed: ' . $error->getMessage() . "\n");
    exit(2);
}
