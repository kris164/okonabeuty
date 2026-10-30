<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'http.php';

use Misseye\BookingStore;
use Misseye\HttpError;
use Misseye\MysqlStore;

$configFile = getenv('MISSEYE_MYSQL_TEST_CONFIG');
if (!$configFile) {
    fwrite(STDERR, "Set MISSEYE_MYSQL_TEST_CONFIG to a private MySQL test config. Real MySQL 8.4 with pdo_mysql is required.\n");
    exit(1);
}
$config = \Misseye\loadConfig($configFile);
if ($config['storage'] !== 'mysql' || $config['database']['host'] !== '127.0.0.1'
    || !preg_match('/\Amisseye_test_[a-z0-9_]+\z/', $config['database']['name'])) {
    throw new RuntimeException('Refusing to reset anything except an explicitly configured local misseye_test_* database.');
}
$pdo = \Misseye\connectMysql($config['database']);
$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'misseye-mysql-tests-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700)) {
    throw new RuntimeException('Cannot create private fixture directory.');
}
$now = new DateTimeImmutable('2026-10-29T06:00:00Z');
$failures = $count = 0;

function check(bool $value, string $message = 'Assertion failed'): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function expect(callable $operation, ?int $status = null): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        check($status === null || ($error instanceof HttpError && $error->status === $status), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected an explicit failure.');
}

function resetDatabase(): MysqlStore
{
    global $pdo;
    foreach (['misseye_rates', 'misseye_blocks', 'misseye_bookings', 'misseye_state'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
    $storage = new MysqlStore($pdo);
    $storage->installSchema();
    return $storage;
}

function run(string $name, callable $test): void
{
    global $count, $failures;
    $count++;
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL $name: {$error->getMessage()}\n{$error->getTraceAsString()}\n");
    }
}

function input(array $changes = []): array
{
    return array_replace([
        'requestId' => \Misseye\uuid(), 'date' => '2026-10-30', 'time' => '10:00', 'service' => 'smp',
        'name' => 'Żaneta Test', 'phone' => '+48123456789', 'consent' => true, 'whatsappConsent' => false,
    ], $changes);
}

function phpCommand(string $file): array
{
    return [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_mysql', $file];
}

function race(array $inputs): array
{
    global $temporary, $root;
    $barrier = $temporary . DIRECTORY_SEPARATOR . 'barrier-' . bin2hex(random_bytes(6));
    $children = [];
    foreach ($inputs as $input) {
        $process = proc_open([...phpCommand($root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'mysql-worker.php'),
            $barrier, json_encode($input, JSON_THROW_ON_ERROR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process), 'Cannot start MySQL test worker.');
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    file_put_contents($barrier, 'start');
    $statuses = [];
    foreach ($children as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 0, 'Worker failed: ' . $errors);
        $statuses[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['status'];
    }
    unlink($barrier);
    sort($statuses);
    return $statuses;
}

function cli(array $arguments, ?string $applicationRoot = null): array
{
    global $root, $configFile;
    $previous = getenv('MISSEYE_CONFIG');
    putenv('MISSEYE_CONFIG=' . $configFile);
    try {
        $process = proc_open([...phpCommand(($applicationRoot ?? $root) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'migrate-mysql.php'), ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    } finally {
        putenv($previous === false ? 'MISSEYE_CONFIG' : 'MISSEYE_CONFIG=' . $previous);
    }
}

try {
    run('schema is explicit and repeated installation never wipes data', function () use ($pdo): void {
        $sql = resetDatabase();
        check($sql->targetStatus() === 'uninitialized');
        expect(fn () => $sql->read(static fn ($data) => $data));
        $sql->importDocument(['version' => 1, 'bookings' => []]);
        $sql->installSchema();
        check($sql->targetStatus() === 'initialized');
        expect(fn () => $sql->importDocument(['version' => 1, 'bookings' => []]));
        $engines = $pdo->query("SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
        check(count($engines) === 4 && array_unique($engines) === ['InnoDB']);
    });

    run('incompatible schema or storage configuration fails explicitly', function () use ($pdo, $config, $temporary): void {
        $sql = resetDatabase();
        $pdo->exec('UPDATE misseye_state SET schema_version = 99 WHERE id = 1');
        expect(fn () => $sql->targetStatus());
        expect(fn () => $sql->importDocument(['version' => 1, 'bookings' => []]));
        $path = $temporary . DIRECTORY_SEPARATOR . 'invalid-config.php';
        $raw = [
            'origin' => $config['origin'], 'admin_token' => $config['admin_token'],
            'data_file' => $temporary . DIRECTORY_SEPARATOR . 'legacy.json',
            'whatsapp' => ['access_token' => '', 'phone_number_id' => '', 'graph_version' => '', 'template_name' => '', 'template_language' => 'pl'],
        ];
        file_put_contents($path, '<?php return ' . var_export($raw, true) . ';');
        expect(fn () => \Misseye\loadConfig($path));
        $raw['database'] = $config['database'];
        file_put_contents($path, '<?php return ' . var_export($raw, true) . ';');
        check(\Misseye\loadConfig($path)['storage'] === 'mysql');
        $raw['database']['host'] = '127.0.0.1;dbname=other';
        file_put_contents($path, '<?php return ' . var_export($raw, true) . ';');
        expect(fn () => \Misseye\loadConfig($path));
    });

    run('migration dry run preserves source and SQL; apply preserves every booking block and offer field', function () use ($temporary, $now): void {
        $sql = resetDatabase();
        $source = $temporary . DIRECTORY_SEPARATOR . 'legacy.json';
        $legacy = new BookingStore($source);
        $legacy->initialize();
        $offer = $legacy->catalog()['offer'];
        $offer['prices']['smp'] = ['amount' => 12345, 'mode' => 'from'];
        $offer['week'][4] = 'poznan';
        $offer['addresses']['poznan'] = 'Adres testowy — Poznań';
        $legacy->saveOffer($offer, $now);
        $booking = $legacy->reserve(input(['emailConsent' => true, 'email' => 'client@example.com', 'offerRevision' => 1]), false, $now, true)['booking'];
        $legacy->addBlock(['id' => \Misseye\uuid(), 'specialistId' => 'pitstop-1', 'date' => '2026-10-30',
            'time' => '14:00', 'endDate' => '2026-10-30', 'endTime' => '15:00', 'reason' => 'break'], $now);
        $bytes = file_get_contents($source);
        [$code, $out, $error] = cli(['--dry-run', '--source=' . $source]);
        check($code === 0, $error);
        check(str_contains($out, '"applied": false') && $sql->targetStatus() === 'uninitialized');
        check(file_get_contents($source) === $bytes);
        [$code, $out, $error] = cli(['--apply', '--source=' . $source]);
        check($code === 0, $error);
        check(str_contains($out, '"applied": true'));
        check($sql->read(static fn ($data) => $data) == json_decode($bytes, true, 512, JSON_THROW_ON_ERROR));
        check((new BookingStore($sql))->get($booking['id']) === $booking);
        check(file_get_contents($source) === $bytes);
        check(cli(['--apply', '--source=' . $source])[0] === 2);
        check(cli(['--empty', '--source=' . $source])[0] === 2);
        check(cli(['--source=' . $temporary . DIRECTORY_SEPARATOR . 'missing.json'])[0] === 2);
        $corrupt = $temporary . DIRECTORY_SEPARATOR . 'corrupt.json';
        file_put_contents($corrupt, '{invalid');
        check(cli(['--source=' . $corrupt])[0] === 2);
        check((new BookingStore($sql))->get($booking['id']) === $booking);
    });

    run('MySQL snapshots, prices and historical locations survive reopen and catalog changes', function () use ($now, $config): void {
        $sql = resetDatabase();
        $store = new BookingStore($sql);
        $store->initialize();
        $offer = $store->catalog()['offer'];
        $offer['prices']['smp'] = ['amount' => 12345, 'mode' => 'from'];
        $store->saveOffer($offer, $now);
        $booking = $store->reserve(input(['offerRevision' => 1]), false, $now)['booking'];
        $offer = $store->catalog()['offer'];
        $offer['prices']['smp']['amount'] = 99999;
        $store->saveOffer($offer, $now);
        $reopened = \Misseye\bookingStore($config);
        check($reopened->get($booking['id']) === $booking);
        check(\Misseye\publicBooking($booking)['priceLabel'] === 'od 123,45 zł');
        $sql->write(static function (&$data): void { unset($data['bookings'][0]['location']); });
        check($reopened->calendar('2026-10-30', '2026-10-30')['bookings'][0]['location'] === 'Września');
        check(!array_key_exists('location', $reopened->get($booking['id'])));
    });

    run('legacy duration migration exports a private snapshot before atomic SQL updates', function () use ($now, $temporary): void {
        $sql = resetDatabase();
        $store = new BookingStore($sql);
        $store->initialize();
        $booking = $store->reserve(input(), false, $now)['booking'];
        $sql->write(static function (&$data): void {
            unset($data['bookings'][0]['reserved_minutes']);
            $data['bookings'][0]['ends'] = $data['bookings'][0]['starts'] + 30 * 60000;
        });
        $before = $sql->read(static fn ($data) => $data);
        check($store->roundFutureBookings(false, $now)['changed'] === 1);
        check($sql->read(static fn ($data) => $data) === $before);
        $backup = $temporary . DIRECTORY_SEPARATOR . 'before-rounding.json';
        check($store->roundFutureBookings(true, $now, $backup)['applied']);
        check(json_decode(file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR) === $before);
        $after = $store->get($booking['id']);
        check($after['starts'] === $booking['starts'] && $after['reserved_minutes'] === 60);
        check($store->roundFutureBookings(false, $now)['changed'] === 0);
    });

    run('new installation requires explicit empty opt-in and refuses discovered legacy data', function () use ($root, $temporary): void {
        $sql = resetDatabase();
        $fixture = $temporary . DIRECTORY_SEPARATOR . 'empty-install';
        foreach (['', 'app', 'scripts', 'www', 'data'] as $directory) {
            check(mkdir($fixture . ($directory === '' ? '' : DIRECTORY_SEPARATOR . $directory), 0700));
        }
        foreach (glob($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . '*.php') as $file) {
            check(copy($file, $fixture . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . basename($file)));
        }
        foreach (['migrate-mysql.php', 'mysql-schema.sql'] as $file) {
            check(copy($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $file,
                $fixture . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $file));
        }
        check(cli(['--dry-run'], $fixture)[0] === 2);
        check(cli(['--dry-run', '--empty'], $fixture)[0] === 0);
        check($sql->targetStatus() === 'uninitialized');
        $legacy = $fixture . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'bookings.json';
        file_put_contents($legacy, '{"version":1,"bookings":[]}');
        check(cli(['--apply', '--empty'], $fixture)[0] === 2);
        unlink($legacy);
        [$code, $out, $error] = cli(['--apply', '--empty'], $fixture);
        check($code === 0, $error);
        check(str_contains($out, '"applied": true') && $sql->targetStatus() === 'initialized');
        check(cli(['--apply', '--empty'], $fixture)[0] === 2);
    });

    run('concurrent bookings cannot double-book and independent specialists keep both writes', function (): void {
        $store = new BookingStore(resetDatabase());
        $store->initialize();
        $requests = array_map(static fn ($service) => input(['service' => $service]), ['smp', 'brwi', 'nails', 'podologia', 'smp', 'nails']);
        check(race($requests) === [201, 201, 409, 409, 409, 409]);
        check(count($store->list('2026-10-30')) === 2);
    });

    run('concurrent identical requests are idempotent', function (): void {
        $store = new BookingStore(resetDatabase());
        $store->initialize();
        $request = input();
        check(race([$request, $request, $request]) === [200, 200, 201]);
        check(count($store->list('2026-10-30')) === 1);
    });

    run('blocks compete atomically with treatments and cancellation reopens only one calendar', function () use ($now): void {
        $store = new BookingStore(resetDatabase());
        $store->initialize();
        $block = ['_operation' => 'block', 'id' => \Misseye\uuid(), 'specialistId' => 'pitstop-1',
            'date' => '2026-10-30', 'time' => '10:00', 'endDate' => '2026-10-30', 'endTime' => '12:00', 'reason' => 'break'];
        check(race([$block, input(['service' => 'hybryda'])]) === [201, 409]);
        $other = $store->reserve(input(), false, $now)['booking'];
        $store->cancel($other['id']);
        check(in_array('10:00', $store->availability('2026-10-30', 'smp', $now), true));
        check(!in_array('10:00', $store->availability('2026-10-30', 'nails', $now), true));
    });

    run('concurrent moves use revision checks and preserve rounded durations', function () use ($now): void {
        $store = new BookingStore(resetDatabase());
        $store->initialize();
        $booking = $store->reserve(input(['service' => 'hybryda']), false, $now)['booking'];
        $base = ['_operation' => 'reschedule', 'id' => $booking['id'], 'revision' => 0, 'date' => '2026-10-30'];
        check(race([[...$base, 'time' => '13:00'], [...$base, 'time' => '16:00']]) === [200, 409]);
        $saved = $store->get($booking['id']);
        check($saved['ends'] - $saved['starts'] === 120 * 60000 && $saved['revision'] === 1);
    });

    run('concurrent offer edits cannot overwrite a newer revision', function () use ($now): void {
        $store = new BookingStore(resetDatabase());
        $store->initialize();
        $one = $store->catalog()['offer'];
        $two = $one;
        $one['prices']['smp']['amount'] = 10000;
        $two['prices']['smp']['amount'] = 20000;
        check(race([[...$one, '_operation' => 'offer'], [...$two, '_operation' => 'offer']]) === [200, 409]);
        $saved = $store->catalog()['offer'];
        check($saved['revision'] === 1);
        $previous = $saved;
        $previous['revision'] = 0;
        check($store->saveOffer($previous, $now) === $saved);
    });

    run('reminder and WhatsApp claims stay single across concurrent workers', function () use ($now): void {
        $store = new BookingStore(resetDatabase());
        $store->initialize();
        $email = $store->reserve(input(['date' => '2026-10-29', 'emailConsent' => true, 'email' => 'client@example.com']), false, $now, true)['booking'];
        $claim = ['_operation' => 'email-claim', 'id' => $email['id'], 'starts' => $email['starts']];
        check(race([$claim, $claim, $claim]) === [200, 409, 409]);
        check($store->get($email['id'])['email_notification'] === 'sending');
        expect(fn () => $store->cancel($email['id']), 409);
        $wa = $store->reserve(input(['whatsappConsent' => true]), true, $now)['booking'];
        $claim = ['_operation' => 'notify-claim', 'id' => $wa['id']];
        check(race([$claim, $claim]) === [200, 409]);
    });

    run('transaction rollback and database uniqueness leave prior data intact', function () use ($pdo, $now): void {
        $sql = resetDatabase();
        $store = new BookingStore($sql);
        $store->initialize();
        $booking = $store->reserve(input(), false, $now)['booking'];
        $before = $sql->read(static fn ($data) => $data);
        expect(fn () => $sql->write(static function (&$data): void {
            $data['bookings'][0]['name'] = 'Changed';
            throw new RuntimeException('Injected rollback');
        }));
        check($sql->read(static fn ($data) => $data) === $before);
        expect(fn () => $sql->write(static function (&$data): void { $data['bookings'][0]['ends']++; }));
        check($sql->read(static fn ($data) => $data) === $before);
        $statement = $pdo->prepare('INSERT INTO misseye_bookings (id,request_id,position,specialist_id,starts,ends,status,payload)
            SELECT ?,request_id,position,specialist_id,starts,ends,status,payload FROM misseye_bookings WHERE id=?');
        expect(fn () => $statement->execute([\Misseye\uuid(), $booking['id']]));
        check($store->get($booking['id']) === $booking);
    });

    run('missing or inconsistent database never falls back to empty JSON', function () use ($pdo, $now): void {
        $sql = resetDatabase();
        $store = new BookingStore($sql);
        expect(fn () => $store->availability('2026-10-30', 'smp', $now));
        $store->initialize();
        $store->reserve(input(), false, $now);
        $pdo->exec('UPDATE misseye_bookings SET starts = starts + 1');
        expect(fn () => $store->availability('2026-10-30', 'smp', $now));
        $pdo->exec('UPDATE misseye_bookings SET starts = starts - 1');
        $pdo->exec('DROP TABLE misseye_blocks');
        expect(fn () => $sql->targetStatus());
        expect(fn () => $store->catalog(), 503);
    });

    run('rate limits use MySQL with exact concurrent threshold and expiry', function () use ($pdo): void {
        resetDatabase();
        $key = hash('sha256', 'test-client');
        for ($i = 0; $i < 119; $i++) {
            \Misseye\mysqlRateLimit($pdo, $key, 1000);
        }
        $operation = ['_operation' => 'rate', 'key' => $key, 'now' => 1000];
        check(race([$operation, $operation, $operation]) === [200, 429, 429]);
        \Misseye\mysqlRateLimit($pdo, $key, 1060);
        check((int) $pdo->query('SELECT attempts FROM misseye_rates')->fetchColumn() === 1);
    });

    run('SQL write failure rolls back changes already executed in other tables', function () use ($pdo, $now): void {
        $sql = resetDatabase();
        $store = new BookingStore($sql);
        $store->initialize();
        $booking = $store->reserve(input(), false, $now)['booking'];
        $block = [
            'id' => \Misseye\uuid(), 'specialist_id' => 'pitstop-1', 'status' => 'active', 'reason' => 'break',
            'date' => '2026-10-30', 'time' => '10:00', 'end_date' => '2026-10-30', 'end_time' => '11:00',
            'starts' => \Misseye\instant('2026-10-30', '10:00'), 'ends' => \Misseye\instant('2026-10-30', '11:00'),
        ];
        $pdo->exec("CREATE TRIGGER misseye_test_failure BEFORE INSERT ON misseye_blocks FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected transaction failure'");
        try {
            expect(fn () => $sql->write(static function (&$data) use ($block): void {
                $data['bookings'][0]['name'] = 'Should roll back';
                $data['blocks'] = [$block];
            }), 503);
            check($store->get($booking['id']) === $booking);
            check($store->calendar('2026-10-30', '2026-10-30')['blocks'] === []);
        } finally {
            $pdo->exec('DROP TRIGGER misseye_test_failure');
        }
    });

    run('lock timeout is explicit and never writes an unlocked reservation', function () use ($now, $config, $pdo): void {
        $store = new BookingStore(resetDatabase());
        $store->initialize();
        $second = \Misseye\connectMysql($config['database']);
        $second->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other = new BookingStore(new MysqlStore($second));
        $pdo->beginTransaction();
        try {
            $pdo->query('SELECT id FROM misseye_state WHERE id=1 FOR UPDATE')->fetchColumn();
            expect(fn () => $other->reserve(input(), false, $now), 503);
        } finally {
            $pdo->rollBack();
        }
        check($store->list('2026-10-30') === []);
        check($other->reserve(input(), false, $now)['created']);
    });

    run('API uses MySQL for config availability authentication and booking lifecycle', function () use ($config): void {
        $store = new BookingStore(resetDatabase());
        $store->initialize();
        $day = new DateTimeImmutable('tomorrow', new DateTimeZone(\Misseye\ZONE));
        if ($day->format('w') === '0') {
            $day = $day->modify('+1 day');
        }
        $date = $day->format('Y-m-d');
        [$status, $catalog] = \Misseye\handleApi('GET', '/api/config', [], [], '', $config, '127.0.0.1');
        check($status === 200 && count($catalog['services']) === 16);
        check(!isset($catalog['database'], $catalog['admin_token']));
        expect(fn () => \Misseye\handleApi('GET', '/api/admin/bookings', ['date' => $date], [], '', $config, '127.0.0.1'), 401);
        $payload = input(['date' => $date]);
        $headers = ['origin' => $config['origin'], 'content-type' => 'application/json'];
        [$status, $result] = \Misseye\handleApi('POST', '/api/bookings', [], $headers, json_encode($payload), $config, '127.0.0.1');
        check($status === 201);
        $id = $result['booking']['id'];
        [$status, $slots] = \Misseye\handleApi('GET', '/api/availability', ['date' => $date, 'service' => 'smp'], [], '', $config, '127.0.0.1');
        check($status === 200 && !in_array('10:00', $slots['slots'], true));
        $headers['authorization'] = 'Bearer ' . $config['admin_token'];
        [$status] = \Misseye\handleApi('POST', '/api/admin/bookings/' . $id . '/cancel', [], $headers, '{}', $config, '127.0.0.1');
        check($status === 200);
        check(in_array('10:00', $store->availability($date, 'smp'), true));
    });

    run('real HTTP front controller uses private MySQL config without filesystem data storage', function () use ($config, $configFile, $temporary, $root): void {
        (new BookingStore(resetDatabase()))->initialize();
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        check($socket !== false, $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $base = 'http://' . $address;
        $fixture = $temporary . DIRECTORY_SEPARATOR . 'http-config.php';
        $raw = require $configFile;
        file_put_contents($fixture, '<?php return ' . var_export([...$raw, 'origin' => $base], true) . ';');
        chmod($fixture, 0600);
        $previous = getenv('MISSEYE_CONFIG');
        putenv('MISSEYE_CONFIG=' . $fixture);
        $process = proc_open([PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_mysql',
            '-S', $address, '-t', $root . DIRECTORY_SEPARATOR . 'www', $root . DIRECTORY_SEPARATOR . 'router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $temporary . DIRECTORY_SEPARATOR . 'http.log', 'a'],
                2 => ['file', $temporary . DIRECTORY_SEPARATOR . 'http.log', 'a']], $pipes);
        putenv($previous === false ? 'MISSEYE_CONFIG' : 'MISSEYE_CONFIG=' . $previous);
        check(is_resource($process), 'Cannot start HTTP MySQL fixture.');
        fclose($pipes[0]);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    $ready = true;
                    break;
                }
                usleep(100000);
            }
            check($ready, 'HTTP MySQL fixture did not become ready.');
            $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
            $body = file_get_contents($base . '/api/config', false, $context);
            check($body !== false && str_contains($http_response_header[0], '200'),
                'HTTP config failed: ' . ($body ?: 'empty response') . "\n" . file_get_contents($temporary . DIRECTORY_SEPARATOR . 'http.log'));
            $public = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            check(count($public['services']) === 16 && !isset($public['database']));
            check(!str_contains($body, $config['database']['password']) && !str_contains($body, $config['admin_token']));
            file_get_contents($base . '/api/admin/calendar?date=2026-10-30&end=2026-10-30', false, $context);
            check(str_contains($http_response_header[0], '401'));
            $headers = ['http' => ['ignore_errors' => true, 'timeout' => 10,
                'header' => 'Authorization: Bearer ' . $config['admin_token']]];
            $body = file_get_contents($base . '/api/admin/calendar?date=2026-10-30&end=2026-10-30', false, stream_context_create($headers));
            check(str_contains($http_response_header[0], '200'));
            check(json_decode($body, true, 512, JSON_THROW_ON_ERROR)['bookings'] === []);
            check(!is_file($temporary . DIRECTORY_SEPARATOR . 'rate-limits.json'));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    });
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}
echo "$count MySQL tests, $failures failures\n";
exit($failures ? 1 : 0);
