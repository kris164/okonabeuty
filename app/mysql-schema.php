<?php
declare(strict_types=1);

namespace Misseye;

use PDO;
use RuntimeException;
use Throwable;

const MYSQL_SCHEMA_VERSION = 2;

function mysqlSchemaInfo(PDO $pdo): array
{
    $engines = $pdo->query("SELECT table_name, engine FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name IN
        ('misseye_state', 'misseye_bookings', 'misseye_blocks', 'misseye_rates', 'misseye_calendars', 'misseye_migrations')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($engines === []) {
        return ['version' => 0, 'initialized' => false];
    }
    if (array_filter($engines, static fn ($engine) => $engine !== 'InnoDB') !== []) {
        throw new RuntimeException('All application and migration tables must use InnoDB.');
    }
    foreach (['misseye_state', 'misseye_bookings', 'misseye_blocks', 'misseye_rates'] as $table) {
        if (($engines[$table] ?? null) !== 'InnoDB') {
            throw new RuntimeException('Incomplete MySQL schema or non-InnoDB table. Restore or repair it before migration.');
        }
    }
    $states = $pdo->query('SELECT id, schema_version, document FROM misseye_state ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $version = (int) ($states[0]['schema_version'] ?? 0);
    if (count($states) !== 2 || (int) $states[0]['id'] !== 1 || (int) $states[1]['id'] !== 2
        || $version < 1 || $version > MYSQL_SCHEMA_VERSION || (int) $states[1]['schema_version'] !== $version) {
        throw new RuntimeException('Missing or unsupported MySQL schema version.');
    }
    if ($version >= 2) {
        foreach (['misseye_calendars', 'misseye_migrations'] as $table) {
            if (($engines[$table] ?? null) !== 'InnoDB') {
                throw new RuntimeException('Incomplete versioned MySQL schema. Restore a verified backup.');
            }
        }
    }
    return ['version' => $version, 'initialized' => $states[0]['document'] !== null];
}

function mysqlMigrationFiles(): array
{
    $scripts = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR;
    return [1 => $scripts . 'mysql-schema.sql', 2 => $scripts . 'migrations' . DIRECTORY_SEPARATOR . '002-scoped-calendars.sql'];
}

function migrateMysqlSchema(PDO $pdo, bool $apply): array
{
    $lock = 'misseye-schema-' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 48);
    $locked = false;
    try {
        if ($apply) {
            $statement = $pdo->prepare('SELECT GET_LOCK(?, 5)');
            $statement->execute([$lock]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException('Another schema migration is running.');
            }
            $locked = true;
        }
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        if (stripos($version, 'mariadb') !== false || version_compare($version, '8.4.0', '<')) {
            throw new RuntimeException('MySQL 8.4 or newer is required.');
        }
        $info = mysqlSchemaInfo($pdo);
        $files = mysqlMigrationFiles();
        $checksums = [];
        foreach ($files as $number => $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException('Cannot read versioned migration file.');
            }
            $checksums[$number] = hash('sha256', str_replace("\r\n", "\n", $contents));
        }
        $hasLedger = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'misseye_migrations'")->fetchColumn() === 1;
        if ($hasLedger) {
            $applied = [];
            foreach ($pdo->query('SELECT version, checksum, status FROM misseye_migrations')->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                if ($entry['status'] !== 'applied') {
                    throw new RuntimeException('Interrupted schema migration. Keep the application offline; inspect DDL and restore/repair from backup before retrying.');
                }
                if (($checksums[(int) $entry['version']] ?? null) !== $entry['checksum']) {
                    throw new RuntimeException('Applied migration checksum mismatch. Do not edit historical migrations.');
                }
                $applied[] = (int) $entry['version'];
            }
            $required = array_filter(array_keys($files), static fn ($number) => $number <= $info['version']);
            if (array_diff($required, $applied) !== []) {
                throw new RuntimeException('Migration history is incomplete. Restore or repair it before continuing.');
            }
        }
        $report = ['from' => $info['version'], 'to' => MYSQL_SCHEMA_VERSION, 'initialized' => $info['initialized'],
            'pending' => array_values(array_filter(array_keys($files), static fn ($number) => $number > $info['version'])), 'applied' => false];
        if (!$apply || $report['pending'] === []) {
            return $report;
        }
        if ($info['version'] === 0) {
            executeMysqlMigration($pdo, $files[1]);
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS misseye_migrations (
            version INT UNSIGNED NOT NULL PRIMARY KEY,
            checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            status VARCHAR(16) NOT NULL,
            applied_at TIMESTAMP NULL
        ) ENGINE=InnoDB");
        $statement = $pdo->prepare("INSERT INTO misseye_migrations (version, checksum, status, applied_at)
            VALUES (1, ?, 'applied', CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE version = misseye_migrations.version");
        $statement->execute([$checksums[1]]);
        foreach ($report['pending'] as $number) {
            if ($number === 1) {
                continue;
            }
            $statement = $pdo->prepare("INSERT INTO misseye_migrations (version, checksum, status) VALUES (?, ?, 'applying')");
            $statement->execute([$number, $checksums[$number]]);
            $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            $pdo->exec("SET SESSION sql_mode = " . $pdo->quote($mode . ',NO_AUTO_VALUE_ON_ZERO'));
            try {
                executeMysqlMigration($pdo, $files[$number]);
            } finally {
                $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($mode));
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE misseye_state SET schema_version = ?')->execute([$number]);
                $pdo->prepare("UPDATE misseye_migrations SET status = 'applied', applied_at = CURRENT_TIMESTAMP WHERE version = ?")
                    ->execute([$number]);
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        }
        return [...$report, 'applied' => true];
    } finally {
        if ($locked) {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lock]);
        }
    }
}

function executeMysqlMigration(PDO $pdo, string $file): void
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Cannot read MySQL migration.');
    }
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
}
