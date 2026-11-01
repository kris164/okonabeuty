<?php
declare(strict_types=1);

namespace Misseye;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'calendar-query.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql-schema.php';

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
        error_log('MySQL unavailable: the PHP pdo_mysql extension is not enabled.');
        throw new HttpError(503, 'Baza rezerwacji jest niedostępna: PHP nie ma włączonego rozszerzenia pdo_mysql. Skontaktuj się z administratorem hostingu.');
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
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        error_log('MySQL connection failed (SQLSTATE ' . $error->getCode() . ', driver code ' . $driverCode . '): ' . $error->getMessage());
        $message = match ($driverCode) {
            1049 => 'MySQL nie znalazł wskazanej bazy. Sprawdź nazwę bazy w config.php.',
            1044 => 'MySQL odrzucił dostęp do wskazanej bazy. Sprawdź nazwę bazy i uprawnienia użytkownika.',
            1045 => 'MySQL odrzucił login lub hasło użytkownika. Sprawdź dane w config.php.',
            2005 => 'Nie znaleziono hosta MySQL. Sprawdź nazwę hosta i DNS w config.php.',
            2002, 2003 => 'Nie można połączyć się z hostem MySQL. Sprawdź host, port i dostęp sieciowy hostingu.',
            1130 => 'Serwer MySQL nie zezwala na połączenie z tego hostingu. Sprawdź dozwolone hosty w OVH.',
            default => 'Nie można połączyć z bazą MySQL (kod błędu ' . ($driverCode ?: 'nieznany') . '). Sprawdź log PHP i konfigurację bazy.',
        };
        throw new HttpError(503, $message);
    }
}

final class MysqlStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function installSchema(): void
    {
        migrateMysqlSchema($this->pdo, true);
        $this->targetStatus();
    }

    public function assertReady(): void
    {
        try {
            if ($this->state()['document'] === null) {
                throw new HttpError(503, 'Baza nie została zainicjalizowana. Administrator musi wykonać migrację CLI.');
            }
        } catch (PDOException $error) {
            self::rethrow($error);
        }
    }

    public function targetStatus(): string
    {
        $info = mysqlSchemaInfo($this->pdo);
        if ($info['version'] === 0) {
            return 'schema_missing';
        }
        if ($info['initialized']) {
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

    public function read(callable $operation, ?CalendarQuery $query = null): mixed
    {
        return $this->access(false, $operation, query: $query);
    }

    public function write(callable $operation, ?CalendarQuery $query = null): mixed
    {
        return $this->access(true, $operation, query: $query);
    }

    private function state(string $lock = ''): array
    {
        $state = $this->pdo->query('SELECT schema_version, document FROM misseye_state WHERE id = 1' . $lock)->fetch(PDO::FETCH_ASSOC);
        if ($state === false || (int) $state['schema_version'] !== MYSQL_SCHEMA_VERSION) {
            throw new HttpError(503, 'Wymagana aktualizacja schematu MySQL. Administrator musi uruchomić upgrade-mysql.php.');
        }
        return $state;
    }

    private function load(array $state, ?CalendarQuery $query, ?string $specialist): array
    {
        if ($state['document'] === null) {
            throw new RuntimeException('MySQL storage is not initialized. Run the migration CLI; no empty fallback is allowed.');
        }
        $data = json_decode($state['document'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || isset($data['bookings']) || (isset($data['blocks']) && $data['blocks'] !== [])) {
            throw new RuntimeException('Invalid MySQL metadata.');
        }
        $data['bookings'] = $this->selectedRecords('misseye_bookings', $query, $specialist);
        $blocks = $this->selectedRecords('misseye_blocks', $query, $specialist);
        if (array_key_exists('blocks', $data) || $blocks !== []) {
            $data['blocks'] = $blocks;
        }
        validateBookings($data);
        return $data;
    }

    private function records(string $table, string $where = '', array $parameters = []): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . $table . ($where === '' ? '' : ' WHERE ' . $where) . ' ORDER BY position');
        $statement->execute($parameters);
        $records = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $record = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($record) || ($record['id'] ?? null) !== $row['id']
                || ($record['starts'] ?? null) !== (int) $row['starts']
                || ($record['ends'] ?? null) !== (int) $row['ends']
                || ($record['status'] ?? null) !== $row['status']
                || ($table === 'misseye_bookings' ? bookingSpecialist($record) : ($record['specialist_id'] ?? null)) !== $row['specialist_id']
                || ($table === 'misseye_bookings' && ($record['request_id'] ?? null) !== $row['request_id'])) {
                throw new RuntimeException('Inconsistent MySQL record. Restore a verified backup.');
            }
            $records[$row['position']] = $record;
        }
        return $records;
    }

    private function selectedRecords(string $table, ?CalendarQuery $query, ?string $specialist): array
    {
        if ($query === null) {
            return array_values($this->records($table));
        }
        $bookings = $table === 'misseye_bookings';
        $records = [];
        if (($bookings && $query->kind === 'booking') || (!$bookings && $query->kind === 'block')) {
            $records = $this->records($table, 'id = ?', [$query->id]);
        }
        if ($bookings && $query->requestId !== null) {
            $records += $this->records($table, 'request_id = ?', [$query->requestId]);
        }
        $where = [];
        $parameters = [];
        if ($query->until !== null && $query->from !== null && $query->kind !== 'notifications'
            && ($query->kind !== 'booking' || $specialist !== null)) {
            if ($specialist !== null) {
                $where[] = 'specialist_id = ?';
                $parameters[] = $specialist;
            }
            if ($query->activeOnly || !$bookings) {
                $where[] = 'status = ?';
                $parameters[] = $bookings ? 'confirmed' : 'active';
            }
            if ($bookings) {
                // Booking validation caps reserved duration at 12 hours, bounding the index scan.
                $where[] = 'starts >= ?';
                $parameters[] = $query->from - 720 * 60000;
            }
            $where[] = 'starts < ? AND ends > ?';
            array_push($parameters, $query->until, $query->from);
        } elseif ($query->kind === 'offer' && $bookings) {
            $where = ["specialist_id = ? AND status = 'confirmed' AND starts > ?"];
            $parameters = [$query->specialistId, $query->from];
        } elseif ($query->kind === 'notifications' && $bookings) {
            $field = $query->channel === 'email' ? 'email_notification' : 'notification';
            $where[] = $field . ' = ?';
            $parameters[] = $query->state;
            if ($query->state === 'sending') {
                $started = $query->channel === 'email' ? 'email_started_at' : 'notification_started_at';
                $where[] = '(' . $started . ' < ? OR ' . $started . ' IS NULL)';
                $parameters[] = $query->until;
            } else {
                if ($query->activeOnly) {
                    $where[] = "status = 'confirmed'";
                }
                if ($query->from !== null && $query->until !== null) {
                    $where[] = 'starts > ? AND starts <= ?';
                    array_push($parameters, $query->from, $query->until);
                }
            }
        }
        if ($where !== []) {
            $records += $this->records($table, implode(' AND ', $where), $parameters);
        }
        ksort($records, SORT_NUMERIC);
        return array_values($records);
    }

    private function owner(CalendarQuery $query): ?string
    {
        if ($query->specialistId !== null) {
            return $query->specialistId;
        }
        if (!in_array($query->kind, ['booking', 'block'], true)) {
            return null;
        }
        $table = $query->kind === 'booking' ? 'misseye_bookings' : 'misseye_blocks';
        $statement = $this->pdo->prepare('SELECT specialist_id FROM ' . $table . ' WHERE id = ?');
        $statement->execute([$query->id]);
        $owner = $statement->fetchColumn();
        return $owner === false ? null : $owner;
    }

    private function access(bool $write, callable $operation, ?array $initial = null, bool $import = false, ?CalendarQuery $query = null): mixed
    {
        try {
            $exclusive = $query === null || $query->kind === 'offer';
            // Resolve immutable ownership before the transaction; establish its snapshot only after acquiring locks.
            $specialist = $query === null ? null : $this->owner($query);
            $this->pdo->beginTransaction();
            $state = $this->state($write ? ($exclusive ? ' FOR UPDATE' : ' FOR SHARE') : '');
            if ($write && !$exclusive && $specialist !== null) {
                $lock = $this->pdo->prepare('SELECT specialist_id FROM misseye_calendars WHERE specialist_id = ? FOR UPDATE');
                $lock->execute([$specialist]);
                if ($lock->fetchColumn() === false) {
                    throw new RuntimeException('Specialist calendar is not provisioned. Run the schema migration.');
                }
            }
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
                $data = $this->load($state, $query, $specialist);
                $before = $data;
            }
            $result = $operation($data);
            if ($write && $data !== $before) {
                validateBookings($data);
                if (!$exclusive) {
                    $this->assertScopedChanges($data, $before, $specialist, $query);
                }
                $this->save($data, $before, $exclusive);
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
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new HttpError(409, 'Identyfikator zgłoszenia został już użyty. Odśwież formularz.');
            }
            error_log('MySQL operation failed (SQLSTATE ' . $error->getCode() . ', driver code ' . ($error->errorInfo[1] ?? 'unknown') . ').');
            throw new HttpError(503, 'Wystąpił błąd bazy rezerwacji. Szczegóły zapisano w logu PHP. Ponów to samo zgłoszenie lub skontaktuj się z managerem.');
        }
        throw $error;
    }

    private function assertScopedChanges(array $data, array $before, ?string $specialist, CalendarQuery $query): void
    {
        $metadata = $data;
        $oldMetadata = $before;
        unset($metadata['bookings'], $metadata['blocks'], $oldMetadata['bookings'], $oldMetadata['blocks']);
        if ($metadata !== $oldMetadata) {
            throw new RuntimeException('Calendar writes cannot change shared offer metadata.');
        }
        foreach (['bookings', 'blocks'] as $collection) {
            $old = array_column($before[$collection] ?? [], null, 'id');
            foreach ($data[$collection] ?? [] as $record) {
                if (($old[$record['id']] ?? null) !== $record) {
                    $owner = $collection === 'bookings' ? bookingSpecialist($record) : $record['specialist_id'];
                    if ($specialist === null || $owner !== $specialist
                        || (isset($old[$record['id']]) && ($collection === 'bookings'
                            ? bookingSpecialist($old[$record['id']]) : $old[$record['id']]['specialist_id']) !== $specialist)) {
                        throw new RuntimeException('Mutation escaped the locked specialist calendar.');
                    }
                    $previous = $old[$record['id']] ?? null;
                    $occupies = in_array($record['status'], ['confirmed', 'active'], true);
                    $sameInterval = $previous !== null && $previous['status'] === $record['status']
                        && $previous['starts'] === $record['starts'] && $previous['ends'] === $record['ends'];
                    if ($occupies && !$sameInterval && ($query->from === null || $query->until === null
                        || $record['starts'] < $query->from || $record['ends'] > $query->until)) {
                        throw new RuntimeException('New occupancy escaped the validated calendar interval.');
                    }
                }
                unset($old[$record['id']]);
            }
            if ($old !== []) {
                throw new RuntimeException('Scoped calendar writes cannot delete records.');
            }
        }
    }

    private function save(array $data, ?array $before, bool $metadataWritable): void
    {
        $this->saveRecords('misseye_bookings', $data['bookings'], $before['bookings'] ?? []);
        $this->saveRecords('misseye_blocks', $data['blocks'] ?? [], $before['blocks'] ?? []);
        if (!$metadataWritable) {
            return;
        }
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
        $ids = array_column($records, null, 'id');
        $delete = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE id = ?');
        foreach (array_diff_key($old, $ids) as $id => $unused) {
            $delete->execute([$id]);
        }
        $columns = ['id', 'specialist_id', 'starts', 'ends', 'status', 'payload'];
        if ($table === 'misseye_bookings') {
            $columns[] = 'request_id';
        }
        $insert = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns)
            . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $update = $this->pdo->prepare('UPDATE ' . $table . ' SET '
            . implode(',', array_map(static fn ($column) => $column . ' = ?', array_slice($columns, 1))) . ' WHERE id = ?');
        foreach ($records as $record) {
            if (($old[$record['id']] ?? null) === $record) {
                continue;
            }
            $values = [
                $record['id'],
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
    try {
        $hit = $pdo->prepare('UPDATE misseye_rates SET attempts = attempts + 1
            WHERE client_key = ? AND expires > ? AND attempts < 120');
        $hit->execute([$key, $now]);
        if ($hit->rowCount() === 1) {
            return;
        }
        // Only new/expired clients share admission control; active clients lock their own counter.
        $pdo->beginTransaction();
        $version = $pdo->query('SELECT schema_version FROM misseye_state WHERE id = 2 FOR UPDATE')->fetchColumn();
        if ($version === false || (int) $version !== MYSQL_SCHEMA_VERSION) {
            throw new RuntimeException('MySQL rate limiter is not initialized.');
        }
        $delete = $pdo->prepare('DELETE FROM misseye_rates WHERE expires <= ?');
        $delete->execute([$now]);
        $select = $pdo->prepare('SELECT attempts FROM misseye_rates WHERE client_key = ? FOR UPDATE');
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
