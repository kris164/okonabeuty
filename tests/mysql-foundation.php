<?php
declare(strict_types=1);

use Misseye\BookingStore;
use Misseye\CalendarQuery;
use Misseye\MysqlStore;

run('v1 schema upgrade is explicit, preserves snapshots and order, and records verified migrations', function () use ($pdo, $now, $temporary, $config): void {
    $legacy = new BookingStore($temporary . DIRECTORY_SEPARATOR . 'schema-v1.json');
    $legacy->initialize();
    $first = $legacy->reserve(input(), false, $now)['booking'];
    $second = $legacy->reserve(input(['service' => 'nails']), false, $now)['booking'];
    $legacy->cancel($first['id']);
    $legacy->addBlock(['id' => \Misseye\uuid(), 'specialistId' => 'pitstop-1', 'date' => '2026-10-30',
        'time' => '15:00', 'endDate' => '2026-10-31', 'endTime' => '12:00', 'reason' => 'leave'], $now);
    $document = json_decode(file_get_contents($temporary . DIRECTORY_SEPARATOR . 'schema-v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $document['bookings'][0]['legacy_extension'] = ['preserve' => 'exactly'];
    unset($document['bookings'][0]['location']);
    $sql = resetDatabase(1);
    foreach (['bookings', 'blocks'] as $collection) {
        foreach ($document[$collection] as $position => $record) {
            $columns = 'id,position,specialist_id,starts,ends,status,payload' . ($collection === 'bookings' ? ',request_id' : '');
            $values = [$record['id'], $position, $record['specialist_id'], $record['starts'], $record['ends'], $record['status'],
                json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
            if ($collection === 'bookings') {
                $values[] = $record['request_id'];
            }
            $pdo->prepare('INSERT INTO misseye_' . $collection . ' (' . $columns . ') VALUES ('
                . implode(',', array_fill(0, count($values), '?')) . ')')->execute($values);
        }
    }
    $metadata = $document;
    unset($metadata['bookings']);
    $metadata['blocks'] = [];
    $pdo->prepare('UPDATE misseye_state SET document = ? WHERE id = 1')->execute([json_encode($metadata, JSON_THROW_ON_ERROR)]);
    $before = $pdo->query('SELECT id,position,payload FROM misseye_bookings ORDER BY position')->fetchAll(PDO::FETCH_ASSOC);
    $report = \Misseye\migrateMysqlSchema($pdo, false);
    check($report['from'] === 1 && $report['pending'] === [2] && !$report['applied']);
    check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn() === 4);
    expect(fn () => \Misseye\handleApi('GET', '/api/config', [], [], '', $config, '127.0.0.1'), 503);
    [$code, $out, $error] = cli(['--apply'], script: 'upgrade-mysql.php');
    check($code === 0 && str_contains($out, '"applied": true'), $error);
    check($pdo->query('SELECT id,position,payload FROM misseye_bookings ORDER BY position')->fetchAll(PDO::FETCH_ASSOC) === $before);
    check($sql->read(static fn ($data) => $data) == $document);
    check((int) $pdo->query("SELECT COUNT(*) FROM misseye_migrations WHERE status='applied'")->fetchColumn() === 2);
    check(\Misseye\migrateMysqlSchema($pdo, true)['pending'] === []);
    $store = new BookingStore($sql);
    check($store->get($second['id']) === $second);
    $store->reserve(input(['time' => '12:00']), false, $now);
    check(array_column($store->list('2026-10-30'), 'id')[2] !== $first['id']);
    check((int) $pdo->query('SELECT MAX(position) FROM misseye_bookings')->fetchColumn() === 2);
});

run('schema dry run has no DDL and interrupted or tampered migrations refuse retries', function () use ($pdo): void {
    $sql = resetDatabase(0);
    [$code, $out, $error] = cli(['--dry-run'], script: 'upgrade-mysql.php');
    check($code === 0 && str_contains($out, '"applied": false'), $error);
    check($sql->targetStatus() === 'schema_missing');
    [$code, $out, $error] = cli(['--apply'], script: 'upgrade-mysql.php');
    check($code === 0, $error);
    check($sql->targetStatus() === 'uninitialized');
    $pdo->exec("UPDATE misseye_migrations SET status='applying' WHERE version=2");
    expect(fn () => \Misseye\migrateMysqlSchema($pdo, true));
    check($sql->targetStatus() === 'uninitialized');
    $pdo->exec("UPDATE misseye_migrations SET status='applied', checksum=REPEAT('0',64) WHERE version=2");
    expect(fn () => \Misseye\migrateMysqlSchema($pdo, false));
});

run('locked specialist cannot block another specialist or public calendar reads', function () use ($pdo, $config, $now): void {
    $store = new BookingStore(resetDatabase());
    $store->initialize();
    $second = \Misseye\connectMysql($config['database']);
    $second->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $other = new BookingStore(new MysqlStore($second));
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM misseye_state WHERE id=1 FOR SHARE')->fetchColumn();
        $pdo->query("SELECT specialist_id FROM misseye_calendars WHERE specialist_id='pitstop-1' FOR UPDATE")->fetchColumn();
        check(in_array('10:00', $other->availability('2026-10-30', 'nails', $now), true));
        check($other->reserve(input(), false, $now)['created']);
        expect(fn () => $other->reserve(input(['service' => 'nails']), false, $now), 503);
        $offer = $other->catalog()['offer'];
        $offer['prices']['smp'] = ['amount' => 10000, 'mode' => 'fixed'];
        expect(fn () => $other->saveOffer($offer, $now), 503);
    } finally {
        $pdo->rollBack();
    }
    check(count($store->list('2026-10-30')) === 1);
    check($other->reserve(input(['service' => 'nails']), false, $now)['created']);
    $other->saveOffer($offer, $now);
});

run('request and block identifiers remain globally unique across independent specialist locks', function (): void {
    $store = new BookingStore(resetDatabase());
    $store->initialize();
    $request = input();
    check(race([$request, [...$request, 'service' => 'nails']]) === [201, 409]);
    $block = ['_operation' => 'block', 'id' => \Misseye\uuid(), 'specialistId' => 'pitstop-1',
        'date' => '2026-10-30', 'time' => '14:00', 'endDate' => '2026-10-30', 'endTime' => '15:00', 'reason' => 'break'];
    check(race([$block, [...$block, 'specialistId' => 'misseye-1']]) === [201, 409]);
    check(count($store->calendar('2026-10-30', '2026-10-30')['blocks']) === 1);
});

run('offer closure and new reservation cannot both commit conflicting outcomes', function () use ($now): void {
    $store = new BookingStore(resetDatabase());
    $store->initialize();
    $offer = $store->catalog()['offer'];
    $offer['week'][4] = 'closed';
    $statuses = race([['_operation' => 'offer', ...$offer], input(['service' => 'nails'])]);
    check(in_array($statuses, [[200, 409], [201, 409]], true));
    if ($store->catalog()['offer']['week'][4] === 'closed') {
        check($store->list('2026-10-30') === []);
        check($store->availability('2026-10-30', 'nails', $now) === []);
    } else {
        check(count($store->list('2026-10-30')) === 1);
        expect(fn () => $store->saveOffer($offer, $now), 409);
    }
});

run('scoped SQL retains full-day, DST, long-block and moved-booking behavior', function () use ($now, $temporary): void {
    $legacy = new BookingStore($temporary . DIRECTORY_SEPARATOR . 'scoped-parity.json');
    $legacy->initialize();
    $booking = $legacy->reserve(input(['service' => 'smp-1']), false, $now)['booking'];
    $legacy->reserve(input(['service' => 'hybryda']), false, $now);
    $block = ['id' => \Misseye\uuid(), 'specialistId' => 'pitstop-1', 'date' => '2026-10-31',
        'time' => '10:00', 'endDate' => '2026-11-02', 'endTime' => '12:00', 'reason' => 'leave'];
    $legacy->addBlock($block, $now);
    $sql = resetDatabase();
    $sql->importDocument(json_decode(file_get_contents($temporary . DIRECTORY_SEPARATOR . 'scoped-parity.json'), true, 512, JSON_THROW_ON_ERROR));
    $store = new BookingStore($sql);
    foreach (['smp', 'smp-1', 'nails', 'przedluzanie'] as $service) {
        check($store->availableDays('2026-10-30', $service, $now) === $legacy->availableDays('2026-10-30', $service, $now));
        check($store->nextAvailable('2026-10-31', $service, $now) === $legacy->nextAvailable('2026-10-31', $service, $now));
    }
    $move = ['date' => '2026-11-02', 'time' => '10:30', 'revision' => 0];
    check($store->reschedule($booking['id'], $move, $now) === $legacy->reschedule($booking['id'], $move, $now));
    check($store->calendar('2026-10-30', '2026-11-04') === $legacy->calendar('2026-10-30', '2026-11-04'));
    check($store->cancelBlock($block['id']) === $legacy->cancelBlock($block['id']));
    check($store->cancel($booking['id']) === $legacy->cancel($booking['id']));
    check($store->list('2026-11-02') === $legacy->list('2026-11-02'));
    expect(fn () => $store->reserve(input(['requestId' => $booking['request_id'], 'date' => '2026-11-03']), false, $now), 409);

    $early = new DateTimeImmutable('2026-10-23T06:00:00Z');
    $dst = ['id' => \Misseye\uuid(), 'specialistId' => 'misseye-1', 'date' => '2026-10-24',
        'time' => '10:00', 'endDate' => '2026-10-26', 'endTime' => '12:00', 'reason' => 'leave'];
    $store->addBlock($dst, $early);
    $legacy->addBlock($dst, $early);
    check($store->availableDays('2026-10-24', 'smp', $early) === $legacy->availableDays('2026-10-24', 'smp', $early));
    check($store->calendar('2026-10-25', '2026-10-26') === $legacy->calendar('2026-10-25', '2026-10-26'));
});

run('API uses one connection and never recovers notification jobs; private maintenance does', function () use ($pdo, $config, $now): void {
    $sql = resetDatabase();
    $store = new BookingStore($sql);
    $store->initialize();
    $booking = $store->reserve(input(['whatsappConsent' => true, 'emailConsent' => true, 'email' => 'test@example.invalid']), true, $now, true)['booking'];
    $store->claimNotification($booking['id']);
    $store->claimEmailReminder($booking['id'], $booking['starts'], new DateTimeImmutable('2026-10-30T06:00:00Z'));
    $sql->write(static function (&$data): void {
        $data['bookings'][0]['notification_started_at'] = 1;
        $data['bookings'][0]['email_started_at'] = 1;
    });
    $before = $pdo->query("SHOW GLOBAL STATUS LIKE 'Connections'")->fetch(PDO::FETCH_NUM)[1];
    [$status] = \Misseye\handleApi('GET', '/api/config', [], [], '', $config, '127.0.0.17');
    $after = $pdo->query("SHOW GLOBAL STATUS LIKE 'Connections'")->fetch(PDO::FETCH_NUM)[1];
    check($status === 200 && (int) $after - (int) $before === 1, 'API must use exactly one MySQL connection');
    check($store->get($booking['id'])['notification'] === 'sending');
    check($store->get($booking['id'])['email_notification'] === 'sending');
    $pending = $store->reserve(input(['time' => '12:00', 'whatsappConsent' => true]), true, $now)['booking'];
    [$code, $out, $error] = cli([], script: 'maintain.php');
    check($code === 0, $error);
    $report = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    check($report['whatsappRecovered'] === 2 && $report['emailUnknown'] === 1);
    check($store->get($booking['id'])['notification'] === 'unknown');
    check($store->get($booking['id'])['email_notification'] === 'unknown');
    check($store->get($pending['id'])['notification'] === 'unconfigured');
    check($store->recoverNotifications(false) === 0);
    check($store->recoverEmailReminders(new DateTimeImmutable()) === 0);
});

run('active rate counters do not take the global admission lock and preserve the 2000-client bound', function () use ($pdo, $config): void {
    resetDatabase();
    $second = \Misseye\connectMysql($config['database']);
    $second->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $key = hash('sha256', 'active-rate-client');
    \Misseye\mysqlRateLimit($pdo, $key, 1000);
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM misseye_state WHERE id=2 FOR UPDATE')->fetchColumn();
        \Misseye\mysqlRateLimit($second, $key, 1000);
    } finally {
        $pdo->rollBack();
    }
    $insert = $pdo->prepare('INSERT INTO misseye_rates (client_key,expires,attempts) VALUES (?,1060,1)');
    $pdo->beginTransaction();
    for ($i = 1; $i < 2000; $i++) {
        $insert->execute([hash('sha256', 'rate-client-' . $i)]);
    }
    $pdo->commit();
    expect(fn () => \Misseye\mysqlRateLimit($pdo, hash('sha256', 'overflow'), 1000), 429);
    \Misseye\mysqlRateLimit($pdo, $key, 1000);
    \Misseye\mysqlRateLimit($pdo, hash('sha256', 'overflow'), 1060);
    check((int) $pdo->query('SELECT COUNT(*) FROM misseye_rates')->fetchColumn() === 1);
});

run('scoped writes reject cross-specialist or shared-metadata mutations', function () use ($now): void {
    $sql = resetDatabase();
    $store = new BookingStore($sql);
    $store->initialize();
    $booking = $store->reserve(input(), false, $now)['booking'];
    expect(fn () => $sql->write(static function (&$data): void {
        $data['bookings'][0]['service'] = 'nails';
        $data['bookings'][0]['specialist_id'] = 'pitstop-1';
    }, CalendarQuery::booking($booking['id'])));
    expect(fn () => $sql->write(static function (&$data): void {
        $data['offer'] = \Misseye\defaultOffer();
    }, CalendarQuery::booking($booking['id'])));
    expect(fn () => $sql->write(static function (&$data): void {
        $data['bookings'][0]['date'] = '2026-10-31';
        $data['bookings'][0]['starts'] = \Misseye\instant('2026-10-31', '10:00');
        $data['bookings'][0]['ends'] = $data['bookings'][0]['starts'] + 3600000;
        $data['bookings'][0]['email_for'] = $data['bookings'][0]['starts'];
    }, CalendarQuery::booking($booking['id'])));
    check($store->get($booking['id']) === $booking);
});

run('12000 historical bookings and blocks do not increase day, catalog, ID or queue index scans', function () use ($pdo, $now): void {
    $store = new BookingStore(resetDatabase());
    $store->initialize();
    $booking = $store->reserve(input(), false, $now)['booking'];
    $insert = $pdo->prepare('INSERT INTO misseye_bookings (id,request_id,specialist_id,starts,ends,status,payload) VALUES (?,?,?,?,?,?,?)');
    $insertBlock = $pdo->prepare('INSERT INTO misseye_blocks (id,specialist_id,starts,ends,status,payload) VALUES (?,?,?,?,?,?)');
    $day = new DateTimeImmutable('2000-01-01', new DateTimeZone(\Misseye\ZONE));
    $pdo->beginTransaction();
    for ($i = 0; $i < 12000; $i++) {
        $person = $i % 2 === 0 ? 'misseye-1' : 'pitstop-1';
        $date = $day->format('Y-m-d');
        $start = \Misseye\instant($date, '10:00');
        $row = [...$booking, 'id' => \Misseye\uuid(), 'request_id' => \Misseye\uuid(), 'date' => $date,
            'specialist_id' => $person, 'service' => $person === 'misseye-1' ? 'smp' : 'nails', 'starts' => $start,
            'ends' => $start + 3600000, 'email_for' => $start];
        $insert->execute([$row['id'], $row['request_id'], $person, $start, $row['ends'], $row['status'], json_encode($row, JSON_THROW_ON_ERROR)]);
        $block = [
            'id' => \Misseye\uuid(), 'specialist_id' => $person, 'status' => 'active', 'reason' => 'break',
            'date' => $date, 'time' => '14:00', 'end_date' => $date, 'end_time' => '15:00',
            'starts' => \Misseye\instant($date, '14:00'), 'ends' => \Misseye\instant($date, '15:00'),
        ];
        $insertBlock->execute([$block['id'], $person, $block['starts'], $block['ends'], 'active', json_encode($block, JSON_THROW_ON_ERROR)]);
        if ($i % 2 === 1) {
            $day = $day->modify('+1 day');
        }
    }
    $pdo->commit();
    $pdo->query('ANALYZE TABLE misseye_bookings, misseye_blocks')->fetchAll();
    $measure = static function (callable $operation, int $maximum) use ($pdo): int {
        $before = $pdo->query("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $operation();
        $after = $pdo->query("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $reads = 0;
        foreach ($before as $key => $value) {
            $reads += (int) $after[$key] - (int) $value;
        }
        check($reads <= $maximum, "Expected <= $maximum index/table reads, got $reads");
        return $reads;
    };
    $catalog = $measure(fn () => $store->catalog(), 10);
    $availability = $measure(fn () => $store->availability('2026-10-30', 'smp', $now), 40);
    $single = $measure(fn () => $store->get($booking['id']), 15);
    $calendar = $measure(fn () => $store->calendar('2026-10-30', '2026-11-04'), 50);
    $queues = $measure(function () use ($store, $now): void {
        check($store->dueEmailReminders($now) === []);
        check($store->pending() === []);
    }, 30);
    check(!in_array('10:00', $store->availability('2026-10-30', 'smp', $now), true));
    check(count($store->list('2026-10-30')) === 1);
    $measure(fn () => $store->reserve(input(['time' => '12:00']), false, $now), 80);
    echo "  12000 bookings + 12000 blocks index reads: catalog=$catalog availability=$availability get=$single calendar=$calendar queues=$queues\n";
});
