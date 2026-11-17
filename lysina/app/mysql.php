<?php
declare(strict_types=1);

namespace Misseye;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

function validateDatabase(mixed $database): void
{
    if (!is_array($database)
        || !is_string($database['host'] ?? null)
        || !preg_match('/\A[a-zA-Z0-9.-]+\z/', $database['host'])
        || !is_int($database['port'] ?? null) || $database['port'] < 1 || $database['port'] > 65535
        || !is_string($database['name'] ?? null) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $database['name'])
        || !is_string($database['user'] ?? null) || $database['user'] === ''
        || !is_string($database['password'] ?? null) || $database['password'] === ''
        || !is_string($database['ssl_ca'] ?? null)) {
        throw new RuntimeException('Configure database host, port, name, user, password and ssl_ca in private config.php.');
    }
    if ($database['ssl_ca'] !== '' && !is_file($database['ssl_ca'])) {
        throw new RuntimeException('MySQL CA certificate file does not exist.');
    }
}

function connectMysql(array $database): PDO
{
    validateDatabase($database);
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('Enable the PHP pdo_mysql extension.');
    }
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_TIMEOUT => 5,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ];
    if ($database['ssl_ca'] !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $database['ssl_ca'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    try {
        $pdo = new PDO(
            "mysql:host={$database['host']};port={$database['port']};dbname={$database['name']};charset=utf8mb4",
            $database['user'], $database['password'], $options
        );
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return $pdo;
    } catch (PDOException $error) {
        error_log('MySQL connection failed (SQLSTATE ' . $error->getCode() . ').');
        throw new HttpError(503, 'Baza rezerwacji jest chwilowo niedostępna. Skontaktuj się z managerem.');
    }
}

final class MysqlStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function installSchema(): void
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        if (str_contains($version, 'MariaDB') || version_compare($version, '8.4.0', '<')) {
            throw new RuntimeException('MySQL 8.4 or newer is required.');
        }
        $sql = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'mysql-schema.sql');
        if ($sql === false) {
            throw new RuntimeException('Cannot read MySQL schema.');
        }
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $this->pdo->exec($statement);
            }
        }
        $this->targetStatus();
    }

    public function targetStatus(): string
    {
        $statement = $this->pdo->query("SELECT ENGINE FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name IN
            ('misseye_state', 'misseye_bookings', 'misseye_blocks', 'misseye_rates')");
        $engines = $statement->fetchAll(PDO::FETCH_COLUMN);
        $count = count($engines);
        if ($count === 0) {
            return 'schema_missing';
        }
        if ($count !== 4) {
            throw new RuntimeException('Incomplete MySQL schema. Repair schema before migration.');
        }
        if (array_filter($engines, static fn ($engine) => $engine !== 'InnoDB') !== []) {
            throw new RuntimeException('All calendar tables must use InnoDB. Migration refused.');
        }
        $state = $this->state(false);
        if ($state['document'] !== null) {
            return 'initialized';
        }
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM misseye_bookings')->fetchColumn() !== 0
            || (int) $this->pdo->query('SELECT COUNT(*) FROM misseye_blocks')->fetchColumn() !== 0) {
            throw new RuntimeException('Uninitialized database contains records. Import refused.');
        }
        return 'uninitialized';
    }

    public function initialize(array $document): void
    {
        $this->access(true, static fn (array &$data) => null, $document);
    }

    public function importDocument(array $document): void
    {
        validateBookings($document);
        $this->access(true, static fn (array &$data) => null, $document, true);
    }

    public function read(callable $operation): mixed
    {
        return $this->access(false, $operation);
    }

    public function write(callable $operation): mixed
    {
        return $this->access(true, $operation);
    }

    private function state(bool $write): array
    {
        $state = $this->pdo->query('SELECT schema_version, document FROM misseye_state WHERE id = 1'
            . ($write ? ' FOR UPDATE' : ''))->fetch(PDO::FETCH_ASSOC);
        if ($state === false || (int) $state['schema_version'] !== 1) {
            throw new RuntimeException('Missing or unsupported MySQL schema. Run the migration CLI.');
        }
        return $state;
    }

    private function load(array $state): array
    {
        if ($state['document'] === null) {
            throw new RuntimeException('MySQL storage is not initialized. Run the migration CLI; no empty fallback is allowed.');
        }
        $data = json_decode($state['document'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || isset($data['bookings']) || (isset($data['blocks']) && $data['blocks'] !== [])) {
            throw new RuntimeException('Invalid MySQL metadata.');
        }
        $data['bookings'] = $this->records('misseye_bookings');
        $blocks = $this->records('misseye_blocks');
        if (array_key_exists('blocks', $data) || $blocks !== []) {
            $data['blocks'] = $blocks;
        }
        validateBookings($data);
        return $data;
    }

    private function records(string $table): array
    {
        $rows = $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY position')->fetchAll(PDO::FETCH_ASSOC);
        $records = [];
        foreach ($rows as $row) {
            $record = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($record) || ($record['id'] ?? null) !== $row['id']
                || ($record['starts'] ?? null) !== (int) $row['starts']
                || ($record['ends'] ?? null) !== (int) $row['ends']
                || ($record['status'] ?? null) !== $row['status']
                || ($table === 'misseye_bookings' ? bookingSpecialist($record) : ($record['specialist_id'] ?? null)) !== $row['specialist_id']
                || ($table === 'misseye_bookings' && ($record['request_id'] ?? null) !== $row['request_id'])) {
                throw new RuntimeException('Inconsistent MySQL record. Restore a verified backup.');
            }
            $records[] = $record;
        }
        return $records;
    }

    private function access(bool $write, callable $operation, ?array $initial = null, bool $import = false): mixed
    {
        $this->pdo->beginTransaction();
        try {
            // Every calendar mutation locks the same InnoDB row before checking availability.
            $state = $this->state($write);
            if ($import && $state['document'] !== null) {
                throw new RuntimeException('Target MySQL storage is already initialized. Import refused.');
            }
            if ($state['document'] === null && $initial !== null) {
                if ($this->records('misseye_bookings') !== [] || $this->records('misseye_blocks') !== []) {
                    throw new RuntimeException('Target MySQL storage contains orphan records. Import refused.');
                }
                $data = $initial;
                validateBookings($data);
                $before = null;
            } else {
                $data = $this->load($state);
                $before = $data;
            }
            $result = $operation($data);
            if ($write && $data !== $before) {
                validateBookings($data);
                $this->save($data, $before);
            }
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            self::rethrow($error);
        }
    }

    public static function rethrow(Throwable $error): never
    {
        if ($error instanceof PDOException) {
            error_log('MySQL operation failed (SQLSTATE ' . $error->getCode() . ', code ' . ($error->errorInfo[1] ?? 0) . ').');
            throw new HttpError(503, 'Nie udało się potwierdzić operacji w bazie. Ponów to samo zgłoszenie lub skontaktuj się z managerem.');
        }
        throw $error;
    }

    private function save(array $data, ?array $before): void
    {
        $this->saveRecords('misseye_bookings', $data['bookings'], $before['bookings'] ?? []);
        $this->saveRecords('misseye_blocks', $data['blocks'] ?? [], $before['blocks'] ?? []);
        $metadata = $data;
        unset($metadata['bookings']);
        if (array_key_exists('blocks', $metadata)) {
            $metadata['blocks'] = [];
        }
        $statement = $this->pdo->prepare('UPDATE misseye_state SET document = ? WHERE id = 1');
        $statement->execute([json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    private function saveRecords(string $table, array $records, array $previous): void
    {
        $old = array_column($previous, null, 'id');
        $positions = array_flip(array_column($previous, 'id'));
        $ids = array_column($records, null, 'id');
        $delete = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE id = ?');
        foreach (array_diff_key($old, $ids) as $id => $unused) {
            $delete->execute([$id]);
        }
        $columns = ['id', 'position', 'specialist_id', 'starts', 'ends', 'status', 'payload'];
        if ($table === 'misseye_bookings') {
            $columns[] = 'request_id';
        }
        $insert = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns)
            . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $update = $this->pdo->prepare('UPDATE ' . $table . ' SET '
            . implode(',', array_map(static fn ($column) => $column . ' = ?', array_slice($columns, 1))) . ' WHERE id = ?');
        foreach ($records as $position => $record) {
            if (($old[$record['id']] ?? null) === $record && ($positions[$record['id']] ?? null) === $position) {
                continue;
            }
            $values = [
                $record['id'], $position,
                $table === 'misseye_bookings' ? bookingSpecialist($record) : $record['specialist_id'],
                $record['starts'], $record['ends'], $record['status'],
                json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
            if ($table === 'misseye_bookings') {
                $values[] = $record['request_id'];
            }
            if (isset($old[$record['id']])) {
                $update->execute([...array_slice($values, 1), $record['id']]);
            } else {
                $insert->execute($values);
            }
        }
    }
}

function mysqlRateLimit(PDO $pdo, string $key, ?int $now = null): void
{
    $now ??= time();
    $pdo->beginTransaction();
    try {
        $version = $pdo->query('SELECT schema_version FROM misseye_state WHERE id = 2 FOR UPDATE')->fetchColumn();
        if ($version === false || (int) $version !== 1) {
            throw new RuntimeException('MySQL rate limiter is not initialized.');
        }
        $delete = $pdo->prepare('DELETE FROM misseye_rates WHERE expires <= ?');
        $delete->execute([$now]);
        $select = $pdo->prepare('SELECT attempts FROM misseye_rates WHERE client_key = ?');
        $select->execute([$key]);
        $count = $select->fetchColumn();
        if ($count === false) {
            if ((int) $pdo->query('SELECT COUNT(*) FROM misseye_rates')->fetchColumn() >= 2000) {
                throw new HttpError(429, 'Zbyt wiele żądań. Spróbuj później.');
            }
            $pdo->prepare('INSERT INTO misseye_rates (client_key, expires, attempts) VALUES (?, ?, 1)')
                ->execute([$key, $now + 60]);
        } elseif ((int) $count >= 120) {
            throw new HttpError(429, 'Zbyt wiele żądań. Spróbuj za minutę.');
        } else {
            $pdo->prepare('UPDATE misseye_rates SET attempts = attempts + 1 WHERE client_key = ?')->execute([$key]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        MysqlStore::rethrow($error);
    }
}
