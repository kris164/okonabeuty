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

$directory = __DIR__ . DIRECTORY_SEPARATOR . 'data';
if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create private data directory.');
}
$file = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($file)) {
    $legacy = [];
    $legacyFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';
    if (is_file($legacyFile)) {
        $lines = file($legacyFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Cannot read legacy configuration.');
        }
        foreach ($lines as $line) {
            if (preg_match('/\A([A-Z_]+)=(.*)\z/', $line, $match)) {
                $value = trim($match[2]);
                if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))) {
                    $value = substr($value, 1, -1);
                }
                $legacy[$match[1]] = $value;
            }
        }
    }
    $target = $directory . DIRECTORY_SEPARATOR . 'bookings.json';
    $legacyDatabase = $legacy['DB_PATH'] ?? $directory . DIRECTORY_SEPARATOR . 'bookings.sqlite';
    if (is_file($legacyDatabase) && !is_file($target)) {
        throw new RuntimeException('Legacy SQLite detected. Stop the old server and run scripts/migrate-sqlite.mjs before setup.');
    }
    $config = require __DIR__ . DIRECTORY_SEPARATOR . 'config.example.php';
    $options = getopt('', ['origin:']);
    $config['origin'] = $options['origin'] ?? $legacy['PUBLIC_ORIGIN'] ?? 'http://127.0.0.1:3000';
    $config['admin_token'] = $legacy['ADMIN_TOKEN'] ?? bin2hex(random_bytes(32));
    if (!is_string($config['admin_token']) || strlen($config['admin_token']) < 32) {
        throw new RuntimeException('Legacy ADMIN_TOKEN is invalid. Set a private key of at least 32 characters.');
    }
    foreach ([
        'WA_ACCESS_TOKEN' => 'access_token', 'WA_PHONE_NUMBER_ID' => 'phone_number_id',
        'WA_GRAPH_VERSION' => 'graph_version', 'WA_TEMPLATE_NAME' => 'template_name',
        'WA_TEMPLATE_LANGUAGE' => 'template_language',
    ] as $old => $new) {
        if (isset($legacy[$old])) {
            $config['whatsapp'][$new] = $legacy[$old];
        }
    }
    \Misseye\whatsappConfig($config['whatsapp']);
    unset($config['data_file']);
    $content = "<?php\ndeclare(strict_types=1);\n\n\$config = " . var_export($config, true)
        . ";\n\$config['data_file'] = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'bookings.json';\nreturn \$config;\n";
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
    echo "Created private config.php; owner key is admin_token in that file.\n";
} else {
    echo "Existing config.php retained without changes.\n";
}
$config = \Misseye\loadConfig($file);
$store = new \Misseye\BookingStore($config['data_file']);
$store->initialize();
echo "JSON storage validated. No SQL database is required.\n";
echo "Local preview: http://127.0.0.1:3000/#rezerwacja\n";
echo "Owner panel: http://127.0.0.1:3000/admin\n";
