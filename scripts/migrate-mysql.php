<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'http.php';

try {
    $apply = false;
    $empty = false;
    $mode = null;
    $source = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'bookings.json';
    $sourceSet = false;
    foreach (array_slice($argv, 1) as $argument) {
        if (in_array($argument, ['--dry-run', '--apply'], true) && $mode === null) {
            $mode = $argument;
            $apply = $argument === '--apply';
        } elseif ($argument === '--empty' && !$empty) {
            $empty = true;
        } elseif (str_starts_with($argument, '--source=') && !$sourceSet && substr($argument, 9) !== '') {
            $source = substr($argument, 9);
            $sourceSet = true;
        } else {
            throw new RuntimeException('Usage: php scripts/migrate-mysql.php [--dry-run|--apply] [--source=PRIVATE_JSON | --empty]');
        }
    }
    if ($empty && $sourceSet) {
        throw new RuntimeException('--empty cannot be combined with --source.');
    }
    $config = \Misseye\loadConfig();
    if ($config['storage'] !== 'mysql') {
        throw new RuntimeException('Migration requires explicit MySQL configuration. Source JSON remains read-only.');
    }
    $target = new \Misseye\MysqlStore(\Misseye\connectMysql($config['database']));
    $migrate = static function (array $document) use ($target, $apply, $empty): array {
        \Misseye\validateBookings($document);
        $status = $target->targetStatus();
        if ($status === 'initialized') {
            throw new RuntimeException('MySQL already contains an initialized calendar. Import/empty initialization refused.');
        }
        $report = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'source' => $empty ? 'explicit-empty-installation' : 'legacy-json',
            'target' => $status,
            'bookings' => count($document['bookings']),
            'blocks' => count($document['blocks'] ?? []),
            'offer_preserved' => array_key_exists('offer', $document),
            'applied' => false,
        ];
        if ($apply) {
            $target->installSchema();
            $target->importDocument($document);
            $actual = $target->read(static fn ($data) => $data);
            if ($actual != $document) {
                throw new RuntimeException('Post-import verification failed. Keep bookings offline and inspect backups; do not retry an empty initialization.');
            }
            $report['applied'] = true;
        }
        return $report;
    };
    if ($empty) {
        $legacy = [$source, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'bookings.sqlite'];
        if (isset($config['data_file']) && is_string($config['data_file'])) {
            $legacy[] = $config['data_file'];
        }
        foreach (array_unique($legacy) as $file) {
            if (file_exists($file)) {
                throw new RuntimeException('Legacy data exists. Empty initialization refused. Inspect and migrate it explicitly.');
            }
        }
        $report = $migrate(['version' => 1, 'bookings' => []]);
    } else {
        if (!is_file($source)) {
            throw new RuntimeException('Source JSON was not found. Locate existing data and use --source. Only for a verified new installation use --empty.');
        }
        \Misseye\assertPrivatePath($source);
        $json = new \Misseye\JsonFile($source, \Misseye\validateBookings(...));
        $report = $json->read($migrate);
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if ($apply) {
        echo "Source was not modified. Keep the legacy writer and old cron disabled; use MySQL for every application process.\n";
    } else {
        echo "No SQL schema or data was changed. Before --apply, back up data and stop the old API and reminder cron.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . "\n");
    exit(2);
}
