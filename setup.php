<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80200 || PHP_INT_SIZE < 8) {
    throw new RuntimeException('PHP 8.2+ (64-bit) is required.');
}
require __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'http.php';

try {
    $file = getenv('MISSEYE_CONFIG') ?: __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    \Misseye\assertPrivatePath($file);
    if (!is_file($file)) {
        $options = getopt('', ['origin:']);
        $config = require __DIR__ . DIRECTORY_SEPARATOR . 'config.example.php';
        $config['origin'] = $options['origin'] ?? 'https://okonabeauty.pl';
        $config['admin_token'] = bin2hex(random_bytes(32));
        $content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        $handle = fopen($file, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Cannot create private config.php.');
        }
        try {
            if (!chmod($file, 0600) || fwrite($handle, $content) !== strlen($content) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Cannot persist private configuration.');
            }
        } finally {
            fclose($handle);
        }
        fwrite(STDERR, "Created private config with a new owner key. Fill in the MySQL password privately, then rerun setup. No database or booking data was initialized.\n");
        exit(1);
    }
    $config = \Misseye\loadConfig($file);
    if ($config['storage'] !== 'mysql') {
        throw new RuntimeException('Setup requires storage=mysql. Existing legacy JSON was not changed.');
    }
    $store = new \Misseye\MysqlStore(\Misseye\connectMysql($config['database']));
    $status = $store->targetStatus();
    echo 'MySQL connection OK; target: ' . $status . ".\n";
    echo "Run scripts/migrate-mysql.php --dry-run before applying a migration. Setup never creates an empty calendar.\n";
    if ($status !== 'initialized') {
        exit(1);
    }
    $store->read(static fn ($data) => null);
} catch (Throwable $error) {
    fwrite(STDERR, 'Setup failed: ' . $error->getMessage() . "\n");
    exit(2);
}
