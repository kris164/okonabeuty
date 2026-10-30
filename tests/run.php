<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'http.php';
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'media.php';

use Misseye\BookingStore;
use Misseye\HttpError;
use Misseye\JsonFile;

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'misseye-php-test-' . bin2hex(random_bytes(8));
if (!mkdir($temporary, 0700)) {
    throw new RuntimeException('Cannot create test directory.');
}
$failures = 0;
$count = 0;
$now = new DateTimeImmutable('2026-10-29T06:00:00Z');
$wa = [
    'access_token' => 'fake-test-token', 'phone_number_id' => '123', 'graph_version' => 'v99.0',
    'template_name' => 'reservation', 'template_language' => 'pl',
];

function check(bool $value, string $message = 'Assertion failed'): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function offerAndReminderTests(DateTimeImmutable $now, string $temporary): void
{
    run('offer defaults, exact grosz prices and immutable booking price snapshots', function () use ($now): void {
        $store = store('prices');
        $catalog = $store->catalog();
        check(count($catalog['services']) === 16 && $catalog['services'][0]['priceLabel'] === 'Cena do ustalenia');
        $offer = $catalog['offer'];
        $offer['prices']['smp'] = ['amount' => 12345, 'mode' => 'from'];
        $offer['prices']['nails'] = ['amount' => 0, 'mode' => 'fixed'];
        $saved = $store->saveOffer($offer, $now);
        check($saved['revision'] === 1 && $store->saveOffer($offer, $now) === $saved);
        check($store->catalog()['services'][0]['priceLabel'] === 'od 123,45 zł');
        $booking = $store->reserve(input(['offerRevision' => 1]), false, $now)['booking'];
        check($booking['price'] === ['amount' => 12345, 'mode' => 'from']);
        $corrupt = $booking;
        $corrupt['price_label'] = 'incorrect snapshot';
        expect(fn () => \Misseye\validateBookings(['version' => 1, 'bookings' => [$corrupt]]), null, RuntimeException::class);
        $saved['prices']['smp']['amount'] = 25000;
        $store->saveOffer($saved, $now);
        check(\Misseye\publicBooking($store->get($booking['id']))['priceLabel'] === 'od 123,45 zł');
        expect(fn () => $store->reserve(input(['time' => '12:00', 'offerRevision' => 1]), false, $now), 409);
        expect(fn () => $store->saveOffer($offer, $now), 409);
        foreach ([-1, 1.5, '100', 10000001] as $amount) {
            $bad = $store->catalog()['offer'];
            $bad['prices']['smp']['amount'] = $amount;
            expect(fn () => $store->saveOffer($bad, $now), 400);
        }
        check(\Misseye\priceLabel(['amount' => 0, 'mode' => 'fixed']) === '0,00 zł');
    });

    run('weekly cities control availability and cannot relocate existing future appointments', function () use ($now): void {
        $store = store('cities');
        $offer = $store->catalog()['offer'];
        $offer['week'][4] = 'poznan';
        $offer['week'][5] = 'closed';
        $offer['addresses']['poznan'] = 'Adres testowy 1';
        $offer = $store->saveOffer($offer, $now);
        $booking = $store->reserve(input(['service' => 'nails']), false, $now)['booking'];
        check($booking['location'] === 'Poznań — Adres testowy 1');
        check(\Misseye\publicBooking($booking)['location'] === $booking['location']);
        check(str_contains(rawurldecode(\Misseye\publicBooking($booking)['whatsappUrl']), $booking['location']));
        check($store->availability('2026-10-31', 'nails', $now) === []);
        check($store->availability('2026-10-31', 'smp', $now) !== []);
        check($store->availableDays('2026-10-31', 'nails', $now)[0]['count'] === 0);
        check($store->nextAvailable('2026-10-31', 'nails', $now)['date'] === '2026-11-02');
        foreach (['closed', 'wrzesnia', 'manager'] as $location) {
            $bad = $offer;
            $bad['week'][4] = $location;
            expect(fn () => $store->saveOffer($bad, $now), 409);
        }
        $bad = $offer;
        $bad['addresses']['poznan'] = 'Inny adres 2';
        expect(fn () => $store->saveOffer($bad, $now), 409);
        check($store->catalog()['offer'] === $offer);
        $moved = $store->reschedule($booking['id'], ['revision' => 0, 'date' => '2026-11-02', 'time' => '10:00'], $now);
        check($moved['location'] === 'Września lub Poznań — lokalizacja ustalana z managerem');
        $offer['week'][4] = 'wrzesnia';
        check($store->saveOffer($offer, $now)['revision'] === 2);
    });

    run('settings changes and reservation creation share one atomic lock', function () use ($now, $temporary): void {
        $store = store('offer-race');
        $offer = $store->catalog()['offer'];
        $offer['week'][4] = 'closed';
        $statuses = race($temporary . DIRECTORY_SEPARATOR . 'offer-race.json', [
            [...$offer, '_operation' => 'offer'], input(['service' => 'nails']),
        ]);
        check(in_array($statuses, [[200, 409], [409, 201]], true));
        $booked = count($store->list('2026-10-30')) === 1;
        check($booked === ($store->catalog()['offer']['week'][4] !== 'closed'));
    });

    run('email opt-in, disabled configuration, header safety and public privacy', function () use ($now): void {
        check(\Misseye\emailConfig(null) === null);
        check(\Misseye\emailConfig(['enabled' => false]) === null);
        expect(fn () => \Misseye\emailConfig(['enabled' => true, 'from' => '', 'reply_to' => 'a@example.com']), null, RuntimeException::class);
        expect(fn () => \Misseye\emailConfig(['enabled' => true, 'from' => "a@example.com\r\nBcc:b@example.com", 'reply_to' => 'a@example.com']), null, RuntimeException::class);
        $store = store('email-consent');
        $request = input(['emailConsent' => true, 'email' => 'test@example.com']);
        expect(fn () => $store->reserve($request, false, $now), 400);
        foreach ([
            ['emailConsent' => 'yes'], ['emailConsent' => true, 'email' => 'bad'],
            ['emailConsent' => false, 'email' => 'a@example.com'],
            ['emailConsent' => true, 'email' => "a@example.com\nBcc:b@example.com"],
        ] as $invalid) {
            expect(fn () => $store->reserve(input($invalid), false, $now, true), 400);
        }
        $booking = $store->reserve($request, false, $now, true)['booking'];
        check($booking['email_notification'] === 'pending');
        check(!$store->reserve($request, false, $now, false)['created']);
        $public = \Misseye\publicBooking($booking);
        check($public['emailReminder'] === 'pending' && !str_contains(json_encode($public), 'test@example.com'));
        $mail = \Misseye\reminderMessage(['from' => 'salon@example.com', 'reply_to' => 'salon@example.com'], $booking);
        check($mail['to'] === 'test@example.com' && $mail['headers']['Content-Type'] === 'text/plain; charset=UTF-8');
        check(str_contains($mail['body'], '2026-10-30, 10:00') && str_contains($mail['body'], 'Europe/Warsaw'));
        $store->stopEmailReminder($booking['id']);
        check($store->get($booking['id'])['email'] === '' && !$store->get($booking['id'])['email_consent']);
        check($store->get($booking['id'])['status'] === 'confirmed');
    });

    run('email reminders send once in the exact 24-hour window and freeze concurrent edits', function () use ($now): void {
        $store = store('email-window');
        $booking = $store->reserve(input(['emailConsent' => true, 'email' => 'test@example.com']), false, $now, true)['booking'];
        $due = new DateTimeImmutable('2026-10-29T09:00:00Z');
        check($store->dueEmailReminders($due->modify('-1 second')) === []);
        check(count($store->dueEmailReminders($due)) === 1);
        $count = 0;
        $sender = function ($config, $sent) use ($store, $booking, $due, &$count): bool {
            $count++;
            check($sent['id'] === $booking['id']);
            expect(fn () => $store->cancel($booking['id']), 409);
            expect(fn () => $store->stopEmailReminder($booking['id']), 409);
            expect(fn () => $store->reschedule($booking['id'], ['revision' => 0, 'date' => '2026-10-30', 'time' => '12:00'], $due), 409);
            return true;
        };
        check(\Misseye\sendDueReminders($store, [], $sender, $due) === ['accepted' => 1, 'failed' => 0, 'unknown' => 0]);
        check(\Misseye\sendDueReminders($store, [], $sender, $due)['accepted'] === 0 && $count === 1);
        $moved = $store->reschedule($booking['id'], ['revision' => 0, 'date' => '2026-10-30', 'time' => '12:00'], $due);
        check($moved['email_notification'] === 'pending' && $moved['email_for'] === $moved['starts']);
        check($store->claimEmailReminder($booking['id'], $booking['starts'], $due) === null);
        check($store->dueEmailReminders($due) === []);
        $store->cancel($booking['id']);
        check($store->dueEmailReminders($due->modify('+3 hours')) === []);
    });

    run('mail failures, interrupted claims and concurrent cron runs never cause automatic duplicates', function () use ($now, $temporary): void {
        foreach (['failed', 'unknown'] as $outcome) {
            $store = store('email-' . $outcome);
            $store->reserve(input(['date' => '2026-10-29', 'time' => '12:00', 'emailConsent' => true, 'email' => 'test@example.com']), false, $now, true);
            $sender = $outcome === 'failed' ? static fn () => false : static function (): bool { throw new RuntimeException('simulated mail uncertainty'); };
            check(\Misseye\sendDueReminders($store, [], $sender, $now)[$outcome] === 1);
            check(\Misseye\sendDueReminders($store, [], $sender, $now)[$outcome] === 0);
        }
        $store = store('email-race');
        $booking = $store->reserve(input(['date' => '2026-10-29', 'time' => '12:00', 'emailConsent' => true, 'email' => 'test@example.com']), false, $now, true)['booking'];
        $claim = ['_operation' => 'email-claim', 'id' => $booking['id'], 'starts' => $booking['starts']];
        $statuses = race($temporary . DIRECTORY_SEPARATOR . 'email-race.json', [$claim, $claim]);
        sort($statuses);
        check($statuses === [200, 409]);
        check($store->recoverEmailReminders($now->modify('+5 minutes')) === 0);
        check($store->recoverEmailReminders($now->modify('+5 minutes 1 second')) === 1);
        check($store->get($booking['id'])['email_notification'] === 'unknown');
        check($store->dueEmailReminders($now->modify('+1 day')) === []);
    });

}

function expect(callable $operation, ?int $status = null, ?string $class = null): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($status !== null) {
            check($error instanceof HttpError && $error->status === $status, 'Unexpected HTTP error: ' . $error->getMessage());
        }
        if ($class !== null) {
            check($error instanceof $class, 'Unexpected exception type: ' . get_class($error));
        }
        return;
    }
    throw new RuntimeException('Expected an exception.');
}

function run(string $name, callable $test): void
{
    global $failures, $count;
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
        'name' => 'Test', 'phone' => '+48123456789', 'consent' => true, 'whatsappConsent' => false,
    ], $changes);
}

function store(string $name): BookingStore
{
    global $temporary;
    $store = new BookingStore($temporary . DIRECTORY_SEPARATOR . $name . '.json');
    $store->initialize();
    return $store;
}

function request(string $base, string $path, string $method = 'GET', array $headers = [], string $body = ''): array
{
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 5,
        'header' => implode("\r\n", $headers), 'content' => $body,
    ]]);
    $response = file_get_contents($base . $path, false, $context);
    if ($response === false) {
        throw new RuntimeException('HTTP test request failed.');
    }
    check(isset($http_response_header[0]) && preg_match('~HTTP/\S+ (\d+)~', $http_response_header[0], $match) === 1);
    return [(int) $match[1], $response, $http_response_header];
}

function race(string $file, array $inputs): array
{
    global $temporary, $root;
    $barrier = $temporary . DIRECTORY_SEPARATOR . 'barrier-' . bin2hex(random_bytes(6));
    $children = [];
    foreach ($inputs as $input) {
        $process = proc_open(
            [PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'worker.php',
                $file, $barrier, json_encode($input, JSON_THROW_ON_ERROR)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start concurrency test worker.');
        }
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
        check(proc_close($process) === 0, 'Concurrency worker failed: ' . $errors);
        $statuses[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['status'];
    }
    unlink($barrier);
    return $statuses;
}

try {
    offerAndReminderTests($now, $temporary);
    run('Warsaw time, DST, local midnight and 90 calendar days', function () use ($now): void {
        check(\Misseye\instant('2026-10-23', '08:00') === 1000 * strtotime('2026-10-23T06:00:00Z'));
        check(\Misseye\instant('2026-10-30', '08:00') === 1000 * strtotime('2026-10-30T07:00:00Z'));
        check(\Misseye\instant('2027-03-29', '08:00') === 1000 * strtotime('2027-03-29T06:00:00Z'));
        check(\Misseye\localDate(new DateTimeImmutable('2026-10-29T23:30:00Z')) === '2026-10-30');
        check(\Misseye\lastDate($now) === '2027-01-27');
        check(\Misseye\lastDate(new DateTimeImmutable('2026-09-01T22:30:00Z')) === '2026-12-01');
    });

    run('consultations and twelve treatments have exact durations and specialist assignments', function () use ($now): void {
        $services = \Misseye\services();
        $durations = [
            'smp' => 30, 'brwi' => 30, 'nails' => 30, 'podologia' => 30,
            'hybryda' => 90, 'przedluzanie' => 240, 'laminacja' => 60,
            'stopy-podstawa' => 90, 'stopy-1' => 150, 'stopy-2' => 220, 'stopy-3' => 280,
            'smp-1' => 200, 'smp-2' => 200, 'smp-3' => 220, 'henna' => 60, 'pm-zabieg' => 220,
        ];
        check(array_column($services, 'minutes', 'id') === $durations);
        check(count(\Misseye\specialists()) === 2);
        check(array_column(\Misseye\specialists(), 'id') === ['pitstop-1', 'misseye-1']);
        foreach ($services as $service) {
            $reserved = [30 => 60, 60 => 60, 90 => 120, 150 => 180, 200 => 240, 220 => 240, 240 => 240, 280 => 300][$service['minutes']];
            check($service['reservedMinutes'] === $reserved);
            check($service['specialistId'] === (in_array($service['id'], ['smp', 'brwi', 'smp-1', 'smp-2', 'smp-3', 'henna', 'pm-zabieg'], true) ? 'misseye-1' : 'pitstop-1'));
            if ($service['specialistId'] === 'pitstop-1') {
                check(str_contains($service['specialist'], '@do_nails.beauty'));
                check($service['location'] === 'Września lub Poznań — lokalizacja ustalana z managerem');
            } else {
                check($service['location'] === 'Września');
            }
            $calendar = store('hours-' . $service['id']);
            check(count($calendar->availability('2026-10-30', $service['id'], $now)) === intdiv(720 - $reserved, 30) + 1);
            check(count($calendar->availability('2026-10-31', $service['id'], $now)) === intdiv(600 - $reserved, 30) + 1);
            check($calendar->availability('2026-11-01', $service['id'], $now) === []);
            $booking = $calendar->reserve(input(['service' => $service['id'], 'time' => '10:30', 'reserved_minutes' => 1]), false, $now)['booking'];
            check($booking['minutes'] === $durations[$service['id']] && $booking['ends'] - $booking['starts'] === $reserved * 60000);
        }
    });

    run('half-hour starts round duration, not clock time, across reserve move and cancellation', function () use ($now, $temporary): void {
        $store = store('rounded-half-hour');
        $booking = $store->reserve(input(['service' => 'hybryda', 'time' => '10:30']), false, $now)['booking'];
        check($booking['ends'] === \Misseye\instant('2026-10-30', '12:30'));
        $slots = $store->availability('2026-10-30', 'nails', $now);
        check(in_array('09:30', $slots, true) && !in_array('10:00', $slots, true));
        check(!in_array('12:00', $slots, true) && in_array('12:30', $slots, true));
        $public = \Misseye\publicBooking($booking);
        check($public['minutes'] === 90 && $public['reservedMinutes'] === 120);
        check($store->calendar('2026-10-30', '2026-10-30')['bookings'][0]['end_time'] === '12:30');
        $moved = $store->reschedule($booking['id'], ['revision' => 0, 'date' => '2026-10-30', 'time' => '14:30'], $now);
        check($moved['ends'] === \Misseye\instant('2026-10-30', '16:30') && $moved['minutes'] === 90 && $moved['reserved_minutes'] === 120);
        check(in_array('10:30', $store->availability('2026-10-30', 'nails', $now), true));
        check(!in_array('16:00', $store->availability('2026-10-30', 'nails', $now), true));
        $store->cancel($booking['id']);
        check(in_array('16:00', $store->availability('2026-10-30', 'nails', $now), true));
        $store = store('rounded-race');
        $statuses = race($temporary . DIRECTORY_SEPARATOR . 'rounded-race.json', [input(), input(['time' => '10:30'])]);
        sort($statuses);
        check($statuses === [201, 409]);
        $invalid = $booking;
        $invalid['reserved_minutes'] = 90;
        expect(fn () => \Misseye\validateBookings(['version' => 1, 'bookings' => [$invalid]]), null, RuntimeException::class);
    });

    run('future rounding dry run is read-only and apply backs up atomically, preserving history', function () use ($now, $temporary): void {
        $source = store('rounding-source');
        $consultation = $source->reserve(input(), false, $now)['booking'];
        $treatment = $source->reserve(input(['service' => 'hybryda', 'time' => '10:30']), false, $now)['booking'];
        $hour = $source->reserve(input(['service' => 'laminacja', 'time' => '14:00']), false, $now)['booking'];
        $current = $source->reserve(input(['time' => '12:00']), false, $now)['booking'];
        $legacy = static function (array $booking): array {
            unset($booking['reserved_minutes'], $booking['revision']);
            $booking['ends'] = $booking['starts'] + $booking['minutes'] * 60000;
            return $booking;
        };
        $past = $legacy($consultation);
        $past['id'] = \Misseye\uuid();
        $past['request_id'] = \Misseye\uuid();
        $past['date'] = '2026-10-28';
        $past['starts'] = \Misseye\instant($past['date'], $past['time']);
        $past['email_for'] = $past['starts'];
        $past['ends'] = $past['starts'] + 30 * 60000;
        $canceled = $legacy($consultation);
        $canceled['id'] = \Misseye\uuid();
        $canceled['request_id'] = \Misseye\uuid();
        $canceled['status'] = 'cancelled';
        $store = store('rounding-target');
        $store->import([$legacy($consultation), $legacy($treatment), $legacy($hour), $current, $past, $canceled]);
        $path = $temporary . DIRECTORY_SEPARATOR . 'rounding-target.json';
        $before = file_get_contents($path);
        $report = $store->roundFutureBookings(false, $now);
        check($report === ['changed' => 3, 'conflicts' => [], 'applied' => false]);
        check(file_get_contents($path) === $before);
        expect(fn () => $store->roundFutureBookings(true, $now), null, RuntimeException::class);
        check(file_get_contents($path) === $before);
        $backup = $temporary . DIRECTORY_SEPARATOR . 'rounding-backup.json';
        check($store->roundFutureBookings(true, $now, $backup)['applied']);
        check(json_decode(file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR) === json_decode($before, true, 512, JSON_THROW_ON_ERROR));
        check($store->get($past['id']) === $past && $store->get($canceled['id']) === $canceled && $store->get($current['id']) === $current);
        $rounded = $store->get($consultation['id']);
        check($rounded['starts'] === $consultation['starts'] && $rounded['minutes'] === 30 && $rounded['reserved_minutes'] === 60);
        check($rounded['revision'] === 1 && !\Misseye\publicBooking($rounded)['rescheduled']);
        check($store->get($treatment['id'])['ends'] === \Misseye\instant('2026-10-30', '12:30'));
        check(!in_array('10:30', $store->availability('2026-10-30', 'smp', $now), true));
        expect(fn () => $store->reschedule($consultation['id'], ['revision' => 0, 'date' => '2026-10-30', 'time' => '14:30'], $now), 409);
        $again = file_get_contents($path);
        check($store->roundFutureBookings(false, $now)['changed'] === 0);
        check(!$store->roundFutureBookings(true, $now, $backup)['applied']);
        check(file_get_contents($path) === $again);
    });

    run('rounding refuses all changes on appointment, block or closing conflicts', function () use ($now, $temporary): void {
        $source = store('conflict-source');
        $first = $source->reserve(input(), false, $now)['booking'];
        unset($first['reserved_minutes']);
        $first['ends'] = $first['starts'] + 30 * 60000;
        foreach (['booking', 'block', 'closing'] as $kind) {
            $store = store('rounding-conflict-' . $kind);
            $old = $first;
            if ($kind === 'closing') {
                $old['time'] = '19:30';
                $old['starts'] = \Misseye\instant($old['date'], $old['time']);
                $old['email_for'] = $old['starts'];
                $old['ends'] = $old['starts'] + 30 * 60000;
            }
            $bookings = [$old];
            if ($kind === 'booking') {
                $next = $first;
                $next['id'] = \Misseye\uuid();
                $next['request_id'] = \Misseye\uuid();
                $next['time'] = '10:30';
                $next['starts'] = \Misseye\instant($next['date'], $next['time']);
                $next['email_for'] = $next['starts'];
                $next['ends'] = $next['starts'] + 30 * 60000;
                $bookings[] = $next;
            }
            $store->import($bookings);
            if ($kind === 'block') {
                $store->addBlock([
                    'id' => \Misseye\uuid(), 'specialistId' => 'misseye-1', 'reason' => 'break',
                    'date' => '2026-10-30', 'time' => '10:30', 'endDate' => '2026-10-30', 'endTime' => '11:00',
                ], $now);
            }
            $path = $temporary . DIRECTORY_SEPARATOR . 'rounding-conflict-' . $kind . '.json';
            $before = file_get_contents($path);
            $backup = $path . '.backup.json';
            $report = $store->roundFutureBookings(false, $now);
            check($report['conflicts'][0]['type'] === $kind && !$report['applied']);
            check($store->roundFutureBookings(true, $now, $backup) === $report);
            check(file_get_contents($path) === $before && !file_exists($backup));
        }
    });

    run('weekday and Saturday hours; Sunday, closing, past and invalid dates', function () use ($now): void {
        $store = store('hours');
        $slots = $store->availability('2026-10-30', 'smp', $now);
        check(count($slots) === 23 && $slots[0] === '08:00' && end($slots) === '19:00');
        $saturday = $store->availability('2026-10-31', 'brwi', $now);
        check(count($saturday) === 19 && $saturday[0] === '10:00' && end($saturday) === '19:00');
        check($store->availability('2026-11-01', 'smp', $now) === []);
        foreach (['2026-10-28', '2027-01-28', '2026-02-30'] as $date) {
            expect(fn () => $store->availability($date, 'smp', $now), 400);
        }
        expect(fn () => $store->availability('2026-10-30', 'unknown', $now), 400);
        check(!in_array('08:00', $store->availability('2026-10-29', 'smp', new DateTimeImmutable('2026-10-29T07:00:00Z')), true));
    });

    run('reservation, cross-service collision, idempotency and cancellation', function () use ($now): void {
        $store = store('booking');
        $data = input();
        $saved = $store->reserve($data, false, $now);
        check($saved['created'] && $saved['booking']['notification'] === 'not_requested');
        check(!in_array('10:00', $store->availability($data['date'], 'brwi', $now), true));
        expect(fn () => $store->reserve(input(['service' => 'brwi']), false, $now), 409);
        $retry = $store->reserve($data, false, $now);
        check(!$retry['created'] && $retry['booking']['id'] === $saved['booking']['id']);
        expect(fn () => $store->reserve(array_replace($data, ['time' => '11:00']), false, $now), 409);
        $store->cancel($saved['booking']['id']);
        check(in_array('10:00', $store->availability($data['date'], 'brwi', $now), true));
        expect(fn () => $store->reserve($data, false, $now), 409);
        check($store->reserve(input(['service' => 'brwi']), false, $now)['created']);
        expect(fn () => $store->cancel(\Misseye\uuid()), 404);
    });

    run('treatments reserve rounded intervals and cannot extend past closing', function () use ($now): void {
        $store = store('long-visits');
        $booking = $store->reserve(input(['service' => 'stopy-2', 'minutes' => 1]), false, $now)['booking'];
        check($booking['minutes'] === 220 && $booking['reserved_minutes'] === 240 && $booking['ends'] - $booking['starts'] === 240 * 60000);
        check(!in_array('13:30', $store->availability('2026-10-30', 'nails', $now), true));
        check(in_array('14:00', $store->availability('2026-10-30', 'nails', $now), true));
        check(in_array('10:00', $store->availability('2026-10-30', 'smp-1', $now), true));
        expect(fn () => $store->reserve(input(['service' => 'hybryda', 'time' => '09:00']), false, $now), 409);
        $late = store('late');
        check(in_array('15:00', $late->availability('2026-10-30', 'stopy-3', $now), true));
        check(!in_array('15:30', $late->availability('2026-10-30', 'stopy-3', $now), true));
        expect(fn () => $late->reserve(input(['service' => 'stopy-3', 'time' => '15:30']), false, $now), 409);
        $touch = $late->reserve(input(['service' => 'hybryda', 'time' => '08:00']), false, $now)['booking'];
        expect(fn () => $late->reserve(input(['service' => 'nails', 'time' => '09:30']), false, $now), 409);
        check($late->reserve(input(['service' => 'nails', 'time' => '10:00']), false, $now)['created']);
        check(\Misseye\publicBooking($touch)['minutes'] === 90);
    });

    run('blocks persist, exclude only their specialist, reject conflicts and cancel idempotently', function () use ($now, $temporary): void {
        $store = store('blocks');
        $block = [
            'id' => \Misseye\uuid(), 'specialistId' => 'pitstop-1', 'reason' => 'break',
            'date' => '2026-10-30', 'time' => '10:00', 'endDate' => '2026-10-30', 'endTime' => '12:00',
        ];
        $saved = $store->addBlock($block, $now);
        check($store->addBlock($block, $now) === $saved);
        check(!in_array('09:00', $store->availability('2026-10-30', 'hybryda', $now), true));
        check(in_array('12:00', $store->availability('2026-10-30', 'hybryda', $now), true));
        check(in_array('10:00', $store->availability('2026-10-30', 'smp', $now), true));
        expect(fn () => $store->reserve(input(['service' => 'nails']), false, $now), 409);
        $reopened = new BookingStore($temporary . DIRECTORY_SEPARATOR . 'blocks.json');
        check(count($reopened->calendar('2026-10-29', '2026-11-04')['blocks']) === 1);
        expect(fn () => $store->addBlock(array_replace($block, ['id' => \Misseye\uuid()]), $now), 409);
        $store->cancelBlock($saved['id']);
        $store->cancelBlock($saved['id']);
        check(in_array('10:00', $store->availability('2026-10-30', 'nails', $now), true));
        check($store->reserve(input(['service' => 'nails']), false, $now)['created']);
        expect(fn () => $store->addBlock(array_replace($block, ['id' => \Misseye\uuid()]), $now), 409);
        expect(fn () => $store->cancelBlock(\Misseye\uuid()), 404);
        expect(fn () => $store->addBlock(array_replace($block, ['specialistId' => 'unknown']), $now), 400);
        expect(fn () => $store->addBlock(array_replace($block, ['endTime' => '09:00']), $now), 400);
        expect(fn () => $store->addBlock(array_replace($block, ['endDate' => '2027-01-28']), $now), 400);
    });

    run('multi-day absence handles Warsaw DST and nearest slots never expose private data', function () use ($now): void {
        $store = store('absence');
        $block = $store->addBlock([
            'id' => \Misseye\uuid(), 'specialistId' => 'pitstop-1', 'reason' => 'leave',
            'date' => '2026-10-30', 'time' => '00:00', 'endDate' => '2026-11-02', 'endTime' => '12:00',
        ], $now);
        check($store->availableDays('2026-10-30', 'hybryda', $now)[0] === ['date' => '2026-10-30', 'count' => 0]);
        check($store->nextAvailable('2026-10-30', 'hybryda', $now) === ['date' => '2026-11-02', 'time' => '12:00']);
        check($store->nextAvailable('2026-10-30', 'smp', $now) === ['date' => '2026-10-30', 'time' => '08:00']);
        check(count($store->calendar('2026-10-31', '2026-10-31')['blocks']) === 1);
        check(count($store->availableDays('2027-01-27', 'hybryda', $now)) === 1);
        expect(fn () => $store->calendar('2026-10-30', '2026-11-06'), 400);
        expect(fn () => $store->calendar('2026-10-30', '2026-10-29'), 400);
        $store->addBlock([
            'id' => \Misseye\uuid(), 'specialistId' => 'misseye-1', 'reason' => 'leave',
            'date' => '2026-10-29', 'time' => '00:00', 'endDate' => '2027-01-27', 'endTime' => '23:59',
        ], $now);
        check($store->nextAvailable('2026-10-30', 'smp', $now) === null);
        $dstNow = new DateTimeImmutable('2026-10-23T06:00:00Z');
        $dst = store('block-dst')->addBlock([
            'id' => \Misseye\uuid(), 'specialistId' => 'pitstop-1', 'reason' => 'leave',
            'date' => '2026-10-24', 'time' => '00:00', 'endDate' => '2026-10-26', 'endTime' => '00:00',
        ], $dstNow);
        check($dst['ends'] - $dst['starts'] === 49 * 3600000);
    });

    run('rescheduling is atomic, versioned, retry safe and keeps stored duration', function () use ($now, $temporary): void {
        $store = store('moves');
        $old = $store->reserve(input(['service' => 'hybryda']), false, $now)['booking'];
        $other = $store->reserve(input(['service' => 'nails', 'time' => '12:00']), false, $now)['booking'];
        $path = $temporary . DIRECTORY_SEPARATOR . 'moves.json';
        $before = file_get_contents($path);
        expect(fn () => $store->reschedule($old['id'], ['revision' => 0, 'date' => '2026-10-30', 'time' => '11:00'], $now), 409);
        check(file_get_contents($path) === $before);
        $move = ['revision' => 0, 'date' => '2026-10-30', 'time' => '14:00'];
        $moved = $store->reschedule($old['id'], $move, $now);
        check($moved['id'] === $old['id'] && $moved['revision'] === 1 && $moved['minutes'] === 90);
        check($store->reschedule($old['id'], $move, $now) === $moved);
        check(in_array('10:00', $store->availability('2026-10-30', 'nails', $now), true));
        check(!in_array('15:00', $store->availability('2026-10-30', 'nails', $now), true));
        expect(fn () => $store->reschedule($old['id'], array_replace($move, ['time' => '16:00']), $now), 409);
        $store->cancel($other['id']);
        expect(fn () => $store->reschedule($other['id'], $move, $now), 409);
        check(\Misseye\publicBooking($moved)['rescheduled']);
        $snapshot = $moved;
        $snapshot['minutes'] = 100;
        unset($snapshot['reserved_minutes']);
        $snapshot['ends'] = $snapshot['starts'] + 100 * 60000;
        $snapshotStore = store('snapshot');
        $snapshotStore->import([$snapshot]);
        $changed = $snapshotStore->reschedule($snapshot['id'], ['revision' => 1, 'date' => '2026-10-30', 'time' => '16:00'], $now);
        check($changed['ends'] - $changed['starts'] === 100 * 60000);
        check(\Misseye\publicBooking($changed)['minutes'] === 100);
        $pending = $store->reserve(input(['service' => 'smp', 'whatsappConsent' => true]), true, $now)['booking'];
        expect(fn () => $store->reschedule($pending['id'], $move, $now), 409);
    });

    run('blocks and long appointments cannot race; concurrent moves cannot overwrite', function () use ($now, $temporary): void {
        $store = store('block-race');
        $block = [
            '_operation' => 'block', 'id' => \Misseye\uuid(), 'specialistId' => 'pitstop-1', 'reason' => 'break',
            'date' => '2026-10-30', 'time' => '10:00', 'endDate' => '2026-10-30', 'endTime' => '12:00',
        ];
        $statuses = race($temporary . DIRECTORY_SEPARATOR . 'block-race.json', [$block, input(['service' => 'hybryda'])]);
        sort($statuses);
        check($statuses === [201, 409]);
        $store = store('move-race');
        $booking = $store->reserve(input(['service' => 'hybryda']), false, $now)['booking'];
        $move = ['_operation' => 'reschedule', 'id' => $booking['id'], 'revision' => 0, 'date' => '2026-10-30', 'time' => '14:00'];
        $statuses = race($temporary . DIRECTORY_SEPARATOR . 'move-race.json', [$move, array_replace($move, ['time' => '16:00'])]);
        sort($statuses);
        check($statuses === [200, 409]);
        check($store->get($booking['id'])['revision'] === 1);
    });

    run('input validation, off-grid hours, Sunday and no partial writes', function () use ($now): void {
        $store = store('invalid');
        foreach ([
            ['name' => ''], ['phone' => '123'], ['consent' => false], ['whatsappConsent' => 'yes'],
            ['requestId' => 'x'], ['date' => 'bad'], ['time' => '25:30'], ['service' => 'unknown'],
        ] as $change) {
            expect(fn () => $store->reserve(input($change), false, $now), 400);
        }
        foreach ([['time' => '10:15'], ['time' => '20:00'], ['time' => '07:30'],
            ['date' => '2026-11-01'], ['date' => '2026-10-31', 'time' => '08:00']] as $change) {
            expect(fn () => $store->reserve(input($change), false, $now), 409);
        }
        check($store->list('2026-10-30') === []);
    });

    run('parallel brands, same-person conflicts, cancellation and public confirmations', function () use ($now, $temporary): void {
        $store = store('two-calendars');
        $misseye = $store->reserve(input(), false, $now)['booking'];
        $request = input(['service' => 'nails', 'specialistId' => 'misseye-1', 'specialist_id' => 'misseye-1']);
        $pitstop = $store->reserve($request, false, $now)['booking'];
        check($misseye['specialist_id'] === 'misseye-1' && $pitstop['specialist_id'] === 'pitstop-1');
        check($misseye['starts'] === $pitstop['starts']);
        expect(fn () => $store->reserve(input(['service' => 'brwi']), false, $now), 409);
        expect(fn () => $store->reserve(input(['service' => 'podologia']), false, $now), 409);
        check(!$store->reserve($request, false, $now)['created']);
        expect(fn () => $store->reserve(array_replace($request, ['service' => 'podologia']), false, $now), 409);
        $public = \Misseye\publicBooking($pitstop);
        check($public['brand'] === 'PitSTOP' && $public['specialistId'] === 'pitstop-1');
        check(str_contains($public['specialist'], '@do_nails.beauty'));
        check($public['location'] === 'Września lub Poznań — lokalizacja ustalana z managerem');
        check(str_contains(rawurldecode($public['whatsappUrl']), 'PitSTOP'));
        check(str_contains(rawurldecode($public['whatsappUrl']), $public['location']));
        check(\Misseye\publicBooking($misseye)['location'] === 'Września');
        check(!str_contains(rawurldecode($public['whatsappUrl']), 'MISSEYE'));
        $store->cancel($misseye['id']);
        check(in_array('10:00', $store->availability('2026-10-30', 'brwi', $now), true));
        check(!in_array('10:00', $store->availability('2026-10-30', 'podologia', $now), true));
        check($store->reserve(input(['service' => 'brwi']), false, $now)['created']);
        expect(fn () => $store->reserve(input(['service' => 'podologia', 'time' => '10:30']), false, $now), 409);
        check($store->reserve(input(['service' => 'podologia', 'time' => '11:00']), false, $now)['created']);
        $reopened = new BookingStore($temporary . DIRECTORY_SEPARATOR . 'two-calendars.json');
        check(count($reopened->list('2026-10-30')) === 4);
    });

    run('legacy JSON needs no rewrite and inconsistent specialist assignments fail explicitly', function () use ($now, $temporary): void {
        $source = store('legacy-source');
        $old = $source->reserve(input(['service' => 'brwi']), false, $now)['booking'];
        unset($old['specialist_id'], $old['minutes'], $old['revision'], $old['reserved_minutes']);
        $old['ends'] = $old['starts'] + 30 * 60000;
        $store = store('legacy-target');
        $store->import([$old]);
        $path = $temporary . DIRECTORY_SEPARATOR . 'legacy-target.json';
        $before = file_get_contents($path);
        $store->initialize();
        check(!in_array('10:00', $store->availability('2026-10-30', 'smp', $now), true));
        check(in_array('10:00', $store->availability('2026-10-30', 'nails', $now), true));
        check(\Misseye\publicBooking($store->get($old['id']))['specialistId'] === 'misseye-1');
        check(file_get_contents($path) === $before);
        $store->reserve(input(['service' => 'nails']), false, $now);
        check($store->get($old['id']) === $old);
        $invalid = $old;
        $invalid['specialist_id'] = 'pitstop-1';
        expect(fn () => \Misseye\validateBookings(['version' => 1, 'bookings' => [$invalid]]), null, RuntimeException::class);
        $invalid['status'] = 'cancelled';
        expect(fn () => \Misseye\validateBookings(['version' => 1, 'bookings' => [$invalid]]), null, RuntimeException::class);
        $overlap = $old;
        $overlap['id'] = \Misseye\uuid();
        $overlap['request_id'] = \Misseye\uuid();
        $overlap['service'] = 'smp';
        expect(fn () => \Misseye\validateBookings(['version' => 1, 'bookings' => [$old, $overlap]]), null, RuntimeException::class);
        $overlap['service'] = 'nails';
        \Misseye\validateBookings(['version' => 1, 'bookings' => [$old, $overlap]]);
    });

    run('JSON survives reopening, preserves records and hides private fields', function () use ($now, $temporary): void {
        $store = store('persist');
        $booking = $store->reserve(input(['name' => 'Żaneta']), false, $now)['booking'];
        $reopened = new BookingStore($temporary . DIRECTORY_SEPARATOR . 'persist.json');
        check($reopened->get($booking['id']) === $booking);
        $public = \Misseye\publicBooking($booking);
        check(array_intersect_key($public, array_flip(['phone', 'name', 'request_id'])) === []);
        check(str_starts_with($public['whatsappUrl'], 'https://wa.me/48665638033?text='));
        check(str_contains(rawurldecode($public['whatsappUrl']), $booking['id']));
        $document = json_decode(file_get_contents($temporary . DIRECTORY_SEPARATOR . 'persist.json'), true, 512, JSON_THROW_ON_ERROR);
        check($document['version'] === 1 && count($document['bookings']) === 1);
    });

    run('corrupt, malformed and missing storage fail explicitly without replacement', function () use ($now, $temporary): void {
        store('corrupt');
        $path = $temporary . DIRECTORY_SEPARATOR . 'corrupt.json';
        file_put_contents($path, '{broken');
        $store = new BookingStore($path);
        expect(fn () => $store->availability('2026-10-30', 'smp', $now), null, JsonException::class);
        expect(fn () => $store->reserve(input(), false, $now), null, JsonException::class);
        check(file_get_contents($path) === '{broken');
        file_put_contents($path, '{"version":99,"bookings":[]}');
        expect(fn () => $store->initialize(), null, RuntimeException::class);
        check(file_get_contents($path) === '{"version":99,"bookings":[]}');
        unlink($path);
        expect(fn () => $store->availability('2026-10-30', 'smp', $now), null, RuntimeException::class);
        check(!is_file($path));
    });

    run('failed transaction preserves JSON bytes; lock is released after failure', function () use ($now, $temporary): void {
        $store = store('rollback');
        $store->reserve(input(), false, $now);
        $path = $temporary . DIRECTORY_SEPARATOR . 'rollback.json';
        $before = file_get_contents($path);
        $file = new JsonFile($path, \Misseye\validateBookings(...));
        expect(fn () => $file->write(static function (array &$data): void {
            $data['bookings'] = [];
            throw new RuntimeException('Simulated failure before commit');
        }), null, RuntimeException::class);
        check(file_get_contents($path) === $before);
        check($store->reserve(input(['time' => '11:00']), false, $now)['created']);
        check(glob($path . '.*.tmp') === []);
    });

    run('separate PHP processes cannot double-book or lose independent writes', function () use ($temporary): void {
        $store = store('race');
        $path = $temporary . DIRECTORY_SEPARATOR . 'race.json';
        $inputs = [];
        for ($i = 0; $i < 8; $i++) {
            $inputs[] = input(['service' => $i % 2 ? 'brwi' : 'smp']);
        }
        $statuses = race($path, $inputs);
        check(count(array_filter($statuses, static fn ($s) => $s === 201)) === 1);
        check(count(array_filter($statuses, static fn ($s) => $s === 409)) === 7);
        $inputs = [];
        foreach (['11:00', '12:00', '13:00', '14:00'] as $time) {
            $inputs[] = input(['time' => $time]);
        }
        check(race($path, $inputs) === [201, 201, 201, 201]);
        check(count($store->list('2026-10-30')) === 5);
        $same = input(['time' => '15:00']);
        $retries = race($path, array_fill(0, 4, $same));
        check(count(array_filter($retries, static fn ($s) => $s === 201)) === 1);
        check(count(array_filter($retries, static fn ($s) => $s === 200)) === 3);
        check(count($store->list('2026-10-30')) === 6);
    });

    run('WhatsApp stays disabled only for explicitly empty configuration', function () use ($wa): void {
        $empty = array_replace($wa, [
            'access_token' => '', 'phone_number_id' => '', 'graph_version' => '', 'template_name' => '',
        ]);
        check(\Misseye\whatsappConfig($empty) === null);
        expect(fn () => \Misseye\whatsappConfig(array_replace($empty, ['access_token' => 'fake-token'])), null, RuntimeException::class);
        expect(fn () => \Misseye\whatsappConfig(array_replace($wa, ['phone_number_id' => '../invalid'])), null, RuntimeException::class);
        expect(fn () => \Misseye\whatsappConfig([]), null, RuntimeException::class);
        check(\Misseye\whatsappConfig($wa) === $wa);
    });

    run('concurrent requests reserve exactly one slot per specialist', function () use ($temporary): void {
        $store = store('dual-race');
        $inputs = [];
        foreach (['smp', 'brwi', 'nails', 'podologia', 'smp', 'brwi', 'nails', 'podologia'] as $service) {
            $inputs[] = input(['service' => $service]);
        }
        $statuses = race($temporary . DIRECTORY_SEPARATOR . 'dual-race.json', $inputs);
        check(count(array_filter($statuses, static fn ($s) => $s === 201)) === 2);
        check(count(array_filter($statuses, static fn ($s) => $s === 409)) === 6);
        $bookings = $store->list('2026-10-30');
        check(count($bookings) === 2);
        check(count(array_unique(array_column($bookings, 'specialist_id'))) === 2);
    });

    run('WhatsApp template, opt-in and one claim per notification', function () use ($now, $wa): void {
        $store = store('notify');
        $booking = $store->reserve(input(['whatsappConsent' => true]), true, $now)['booking'];
        $calls = 0;
        $sender = static function ($config, $payload) use (&$calls): array {
            $calls++;
            check($payload['to'] === '48123456789' && $payload['type'] === 'template');
            $parameters = $payload['template']['components'][0]['parameters'];
            check(count($parameters) === 4);
            check(str_starts_with($parameters[0]['text'], 'MissEye — '));
            return ['status' => 200, 'body' => '{"messages":[{"id":"wamid.test"}]}'];
        };
        \Misseye\notify($store, $booking['id'], $wa, $sender);
        \Misseye\notify($store, $booking['id'], $wa, $sender);
        check($calls === 1 && $store->get($booking['id'])['notification'] === 'accepted');
        $other = $store->reserve(input(['time' => '11:00']), true, $now)['booking'];
        \Misseye\notify($store, $other['id'], $wa, $sender);
        check($calls === 1);
        $unconfigured = $store->reserve(input(['time' => '12:00', 'whatsappConsent' => true]), false, $now)['booking'];
        check($unconfigured['notification'] === 'unconfigured');
        $pitstop = $store->reserve(input(['service' => 'podologia', 'whatsappConsent' => true]), true, $now)['booking'];
        \Misseye\notify($store, $pitstop['id'], $wa, static function ($config, $payload): array {
            check(str_starts_with($payload['template']['components'][0]['parameters'][0]['text'], 'PitSTOP — '));
            check(str_contains($payload['template']['components'][0]['parameters'][0]['text'], 'Września lub Poznań — lokalizacja ustalana z managerem'));
            return ['status' => 200, 'body' => '{"messages":[{"id":"wamid.pitstop.test"}]}'];
        });
        check($store->get($pitstop['id'])['notification'] === 'accepted');
    });

    run('provider failures keep reservations and interrupted messages recover safely', function () use ($now, $wa): void {
        $store = store('notify-errors');
        $rejected = $store->reserve(input(['whatsappConsent' => true]), true, $now)['booking'];
        \Misseye\notify($store, $rejected['id'], $wa, static fn () => ['status' => 400, 'body' => '{}']);
        check($store->get($rejected['id'])['notification'] === 'failed');
        $uncertain = $store->reserve(input(['time' => '11:00', 'whatsappConsent' => true]), true, $now)['booking'];
        \Misseye\notify($store, $uncertain['id'], $wa, static function (): array {
            throw new RuntimeException('Simulated transport timeout');
        });
        check($store->get($uncertain['id'])['notification'] === 'unknown');
        check($store->get($uncertain['id'])['status'] === 'confirmed');
        $interrupted = $store->reserve(input(['time' => '12:00', 'whatsappConsent' => true]), true, $now)['booking'];
        $store->claimNotification($interrupted['id']);
        $store->recoverNotifications(true);
        check($store->get($interrupted['id'])['notification'] === 'sending');
        $store->recoverNotifications(true, \Misseye\milliseconds(new DateTimeImmutable()) + 121000);
        check($store->get($interrupted['id'])['notification'] === 'unknown');
        $pending = $store->reserve(input(['time' => '13:00', 'whatsappConsent' => true]), true, $now)['booking'];
        $store->recoverNotifications(false);
        check($store->get($pending['id'])['notification'] === 'unconfigured');
    });

    run('import preserves IDs and refuses existing data or overlapping appointments', function () use ($now): void {
        $source = store('import-source');
        $booking = $source->reserve(input(), false, $now)['booking'];
        $target = store('import-target');
        $target->import([$booking]);
        check($target->get($booking['id']) === $booking);
        expect(fn () => $target->import([]), null, RuntimeException::class);
        $collision = array_replace($booking, ['id' => \Misseye\uuid(), 'request_id' => \Misseye\uuid()]);
        $empty = store('import-collision');
        expect(fn () => $empty->import([$booking, $collision]), null, RuntimeException::class);
        check($empty->list('2026-10-30') === []);
    });

    run('media byte ranges, including suffixes and invalid offsets', function (): void {
        check(\Misseye\mediaRange(null, 1000) === null);
        check(\Misseye\mediaRange('bytes=0-99', 1000) === [0, 99]);
        check(\Misseye\mediaRange('bytes=500-', 1000) === [500, 999]);
        check(\Misseye\mediaRange('bytes=-100', 1000) === [900, 999]);
        check(\Misseye\mediaRange('bytes=0-9999', 1000) === [0, 999]);
        check(\Misseye\mediaRange('bytes=-9999', 1000) === [0, 999]);
        check(\Misseye\mediaRange('bytes=0-10,30-40', 1000) === null);
        expect(fn () => \Misseye\mediaRange('bytes=1000-', 1000), 416);
        expect(fn () => \Misseye\mediaRange('bytes=10-5', 1000), 416);
        expect(fn () => \Misseye\mediaRange('bytes=-0', 1000), 416);
        expect(fn () => \Misseye\mediaRange('bytes=0-', 0), 416);
    });

    run('brand proposal links four real services and separates planned platform features', function () use ($root): void {
        $html = file_get_contents($root . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'index.html');
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $links = $xpath->query('//a[@data-service]');
        check($links->length === 5);
        $ids = [];
        foreach ($links as $link) {
            $ids[] = $link->getAttribute('data-service');
            check($link->getAttribute('href') === '#rezerwacja');
            \Misseye\serviceFor($link->getAttribute('data-service'));
        }
        check(count(array_unique($ids)) === 4);
        $elementIds = [];
        foreach ($xpath->query('//*[@id]') as $element) {
            $id = $element->getAttribute('id');
            check(!isset($elementIds[$id]), 'Duplicate element ID: ' . $id);
            $elementIds[$id] = true;
        }
        foreach (['misseye', 'pitstop', 'dla-specjalistow', 'booking-provider'] as $id) {
            check(isset($elementIds[$id]));
        }
        check(str_contains($html, 'Platforma ogólnopolska jest w planach'));
        check(str_contains($html, 'Portfolio MissEye'));
        foreach ([
            ['brand-preview', 'a', 'href', '#pitstop', '#misseye'],
            ['brand-services', 'article', 'id', 'pitstop', 'misseye'],
            ['portfolio-columns', 'article', 'id', 'portfolio-pitstop', 'portfolio-misseye'],
        ] as [$group, $tag, $attribute, $left, $right]) {
            $panels = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ' . $group . ' ")]/' . $tag);
            check($panels->length === 2 && $panels[0]->getAttribute($attribute) === $left && $panels[1]->getAttribute($attribute) === $right);
        }
        check($xpath->query('//*[@id="portfolio-pitstop"]//video')->length === 0);
        check($xpath->query('//*[@id="portfolio-misseye"]//video')->length === 5);
        check($xpath->query('//*[@id="mark-nail"]')->length === 1 && $xpath->query('//*[@id="mark-eye"]')->length === 1);
        $profileLinks = $xpath->query('//a[@href="https://instagram.com/do_nails.beauty"]');
        check($profileLinks->length === 3);
        foreach ($profileLinks as $link) {
            check($link->getAttribute('target') === '_blank');
            check(str_contains($link->getAttribute('rel'), 'noopener'));
        }
        check(str_contains($html, 'Rezerwacja online dotyczy terminu, nie wyboru adresu.'));
        check(!str_contains($html, '572 799 429'));
    });

    run('manager contact highlights the correct number and all contact links agree', function () use ($root): void {
        check(\Misseye\WHATSAPP_NUMBER === '48665638033');
        $html = file_get_contents($root . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'index.html');
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $phones = $xpath->query('//a[starts-with(@href, "tel:")]');
        check($phones->length === 2);
        foreach ($phones as $phone) {
            check($phone->getAttribute('href') === 'tel:+48665638033');
            check(str_contains($phone->textContent, '665 638 033'));
            check($phone->getAttribute('aria-label') === 'Zadzwoń do managera: +48 665 638 033');
        }
        $whatsapp = $xpath->query('//a[starts-with(@href, "https://wa.me/")]');
        check($whatsapp->length === 3);
        foreach ($whatsapp as $link) {
            check(preg_match('~\Ahttps://wa\.me/48665638033(?:\?|$)~', $link->getAttribute('href')) === 1);
        }
        check($xpath->query('//*[@id="kontakt"]//a[@class="manager-phone"]')->length === 1);
        check(!str_contains($html, '696 293 848') && !str_contains($html, '48696293848'));
    });

    run('PitSTOP photo portfolio has four optimized images with accessible full-view links', function () use ($root): void {
        $document = new DOMDocument();
        $document->loadHTML(file_get_contents($root . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'index.html'), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $images = $xpath->query('//*[@id="portfolio-pitstop"]//img');
        check($images->length === 4);
        $bytes = 0;
        $sources = [];
        foreach ($images as $image) {
            $src = $image->getAttribute('src');
            check(str_starts_with($src, 'media/pitstop-nails-'));
            $file = $root . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $src);
            $size = getimagesize($file);
            check($size !== false && $size['mime'] === 'image/webp');
            check($size[0] === 768 && $size[1] === (int) $image->getAttribute('height'));
            check((int) $image->getAttribute('width') === 768);
            check($image->getAttribute('loading') === 'lazy' && $image->getAttribute('decoding') === 'async');
            check(strlen($image->getAttribute('alt')) > 30);
            check($image->parentNode->getAttribute('href') === $src);
            check($image->parentNode->getAttribute('target') === '_blank');
            check(str_contains($image->parentNode->getAttribute('rel'), 'noopener'));
            $sources[] = $src;
            $bytes += filesize($file);
        }
        check(count(array_unique($sources)) === 4);
        check($bytes < 300 * 1024, 'Nails portfolio exceeds its 300 KiB budget');
    });

    run('five video cards have posters, controls and no automatic playback', function () use ($root): void {
        $document = new DOMDocument();
        $document->loadHTML(file_get_contents($root . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'index.html'), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $videos = $document->getElementsByTagName('video');
        check($videos->length === 5);
        foreach ($videos as $video) {
            check($video->hasAttribute('controls') && $video->hasAttribute('playsinline'));
            check(!$video->hasAttribute('autoplay') && $video->getAttribute('preload') === 'none');
            check($video->getAttribute('width') === '720' && $video->getAttribute('height') === '1280');
            $poster = $root . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $video->getAttribute('poster'));
            check(is_file($poster), 'Missing video poster');
            check($video->getAttribute('aria-labelledby') !== '');
            $sources = $video->getElementsByTagName('source');
            check($sources->length === 1 && $sources->item(0)->getAttribute('type') === 'video/mp4');
            $source = $root . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sources->item(0)->getAttribute('src'));
            check(is_file($source) && filesize($source) > 0 && filesize($source) < 15 * 1024 * 1024);
        }
        $bytes = 0;
        foreach (glob($root . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . '*') as $file) {
            $bytes += filesize($file);
        }
        check($bytes < 40 * 1024 * 1024, 'Video gallery exceeds its size budget');
    });

    run('HTTP API, auth, cancellation, media seeking and private paths', function () use ($root, $temporary): void {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        check($socket !== false, 'Cannot reserve test port');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $base = 'http://' . $address;
        $path = $temporary . DIRECTORY_SEPARATOR . 'http.json';
        (new BookingStore($path))->initialize();
        $configFile = $temporary . DIRECTORY_SEPARATOR . 'test-config.php';
        $config = [
            'origin' => $base, 'admin_token' => str_repeat('a', 48), 'data_file' => $path,
            'whatsapp' => ['access_token' => '', 'phone_number_id' => '', 'graph_version' => '', 'template_name' => '', 'template_language' => 'pl'],
            'email' => ['enabled' => true, 'from' => 'salon@example.invalid', 'reply_to' => 'salon@example.invalid'],
        ];
        file_put_contents($configFile, '<?php return ' . var_export($config, true) . ';');
        $environment = getenv();
        $environment['MISSEYE_CONFIG'] = $configFile;
        $log = $temporary . DIRECTORY_SEPARATOR . 'http.log';
        $process = proc_open(
            [PHP_BINARY, '-S', $address, '-t', $root . DIRECTORY_SEPARATOR . 'www', $root . DIRECTORY_SEPARATOR . 'router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes, $root, $environment
        );
        check(is_resource($process), 'Cannot start PHP HTTP test server');
        fclose($pipes[0]);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $probe = @file_get_contents($base . '/api/config');
                if ($probe !== false) {
                    $ready = true;
                    break;
                }
                usleep(100000);
            }
            check($ready, 'PHP server did not become ready: ' . file_get_contents($log));
            $catalog = json_decode($probe, true, 512, JSON_THROW_ON_ERROR);
            check(count($catalog['services']) === 16 && count($catalog['specialists']) === 2);
            check($catalog['emailReminders'] && $catalog['offer']['revision'] === 0);
            check($catalog['whatsappNumber'] === '48665638033');
            $day = \Misseye\parseDate(\Misseye\localDate(new DateTimeImmutable()))->modify('+1 day');
            if ($day->format('w') === '0') {
                $day = $day->modify('+1 day');
            }
            $date = $day->format('Y-m-d');
            $data = input(['date' => $date]);
            $jsonHeaders = ['Content-Type: application/json'];
            [$code, $body] = request($base, '/api/bookings', 'POST', $jsonHeaders, json_encode($data, JSON_THROW_ON_ERROR));
            check($code === 201, $body);
            $saved = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['booking'];
            check(array_intersect_key($saved, array_flip(['phone', 'name'])) === []);
            [$code, $body] = request($base, '/api/bookings', 'POST', $jsonHeaders, json_encode($data, JSON_THROW_ON_ERROR));
            check($code === 200 && json_decode($body, true)['booking']['id'] === $saved['id']);
            [$code] = request($base, '/api/bookings', 'POST', $jsonHeaders, json_encode(input(['date' => $date, 'service' => 'brwi']), JSON_THROW_ON_ERROR));
            check($code === 409);
            [$code, $body] = request($base, '/api/bookings', 'POST', $jsonHeaders, json_encode(input(['date' => $date, 'service' => 'nails']), JSON_THROW_ON_ERROR));
            check($code === 201 && json_decode($body, true)['booking']['brand'] === 'PitSTOP');
            [$code] = request($base, '/api/bookings', 'POST', $jsonHeaders, json_encode(input(['date' => $date, 'service' => 'podologia']), JSON_THROW_ON_ERROR));
            check($code === 409);
            check(request($base, '/api/admin/bookings?date=' . $date)[0] === 401);
            $auth = ['Authorization: Bearer ' . str_repeat('a', 48)];
            [$code, $body] = request($base, '/api/admin/bookings?date=' . $date, 'GET', $auth);
            $manager = json_decode($body, true);
            check($code === 200 && count($manager['bookings']) === 2);
            check(count($manager['services']) === 16 && count($manager['specialists']) === 2);
            foreach (['/api/admin/calendar?date=' . $date . '&end=' . $date, '/api/admin/blocks', '/api/admin/bookings', '/api/admin/offer'] as $privateApi) {
                check(request($base, $privateApi, str_contains($privateApi, '?') ? 'GET' : 'POST')[0] === 401);
            }
            $managerHeaders = [...$auth, ...$jsonHeaders];
            $offer = $catalog['offer'];
            $offer['prices']['nails'] = ['amount' => 12300, 'mode' => 'from'];
            [$code, $body] = request($base, '/api/admin/offer', 'POST', $managerHeaders, json_encode($offer, JSON_THROW_ON_ERROR));
            check($code === 200 && json_decode($body, true)['offer']['revision'] === 1);
            check(request($base, '/api/admin/offer', 'POST', $managerHeaders, json_encode($offer, JSON_THROW_ON_ERROR))[0] === 200);
            [$code, $body] = request($base, '/api/availability?date=' . $date . '&service=nails');
            $availability = json_decode($body, true);
            check($code === 200 && $availability['priceLabel'] === 'od 123,00 zł' && $availability['offerRevision'] === 1);
            $conflict = [...$offer, 'revision' => 1];
            $conflict['week'][(int) $day->format('N') - 1] = 'closed';
            check(request($base, '/api/admin/offer', 'POST', $managerHeaders, json_encode($conflict, JSON_THROW_ON_ERROR))[0] === 409);
            $manual = input(['date' => $date, 'time' => '11:00', 'service' => 'hybryda', 'emailConsent' => true, 'email' => 'test@example.invalid']);
            [$code, $body] = request($base, '/api/admin/bookings', 'POST', $managerHeaders, json_encode($manual, JSON_THROW_ON_ERROR));
            check($code === 201, $body);
            $manualBooking = json_decode($body, true)['booking'];
            check($manualBooking['minutes'] === 90 && $manualBooking['reservedMinutes'] === 120);
            check($manualBooking['emailReminder'] === 'pending' && !str_contains($body, 'test@example.invalid'));
            check(request($base, '/api/admin/bookings', 'POST', $managerHeaders, json_encode($manual, JSON_THROW_ON_ERROR))[0] === 200);
            $blockRequest = [
                'id' => \Misseye\uuid(), 'specialistId' => 'pitstop-1', 'reason' => 'break',
                'date' => $date, 'time' => '13:00', 'endDate' => $date, 'endTime' => '15:00',
            ];
            [$code, $body] = request($base, '/api/admin/blocks', 'POST', $managerHeaders, json_encode($blockRequest, JSON_THROW_ON_ERROR));
            check($code === 201, $body);
            $blockId = json_decode($body, true)['block']['id'];
            $movePath = '/api/admin/bookings/' . $manualBooking['id'] . '/reschedule';
            check(request($base, $movePath, 'POST', $managerHeaders, json_encode(['date' => $date, 'time' => '12:00', 'revision' => 0]))[0] === 409);
            [$code, $body] = request($base, $movePath, 'POST', $managerHeaders, json_encode(['date' => $date, 'time' => '16:00', 'revision' => 0]));
            check($code === 200 && json_decode($body, true)['booking']['revision'] === 1);
            check(request($base, $movePath, 'POST', $managerHeaders, json_encode(['date' => $date, 'time' => '18:00', 'revision' => 0]))[0] === 409);
            [$code, $body] = request($base, '/api/admin/calendar?date=' . $date . '&end=' . $date, 'GET', $auth);
            $snapshot = json_decode($body, true);
            check($code === 200 && count($snapshot['bookings']) === 3 && count($snapshot['blocks']) === 1);
            $long = array_values(array_filter($snapshot['bookings'], static fn ($b) => $b['id'] === $manualBooking['id']))[0];
            check($long['end_time'] === '18:00' && $long['minutes'] === 90 && $long['reserved_minutes'] === 120);
            check(request($base, '/api/admin/bookings/' . $manualBooking['id'] . '/email-stop', 'POST')[0] === 401);
            check(request($base, '/api/admin/bookings/' . $manualBooking['id'] . '/email-stop', 'POST', $auth)[0] === 200);
            [$code, $body] = request($base, '/api/admin/calendar?date=' . $date . '&end=' . $date, 'GET', $auth);
            $withoutEmail = array_values(array_filter(json_decode($body, true)['bookings'], static fn ($b) => $b['id'] === $manualBooking['id']))[0];
            check($withoutEmail['email'] === '' && !$withoutEmail['email_consent']);
            check(request($base, '/api/admin/blocks/' . $blockId . '/cancel', 'POST', $auth)[0] === 200);
            [$code, $body] = request($base, '/api/availability-days?date=' . $date . '&service=hybryda');
            $days = json_decode($body, true)['days'];
            check($code === 200 && count($days) === 7 && array_keys($days[0]) === ['date', 'count']);
            [$code, $body] = request($base, '/api/next-availability?date=' . $date . '&service=hybryda');
            $nearest = json_decode($body, true)['slot'];
            check($code === 200 && $nearest === ['date' => $date, 'time' => $day->format('w') === '6' ? '11:00' : '08:00']);
            check(request($base, '/api/admin/bookings/' . $saved['id'] . '/cancel', 'POST', $auth)[0] === 200);
            [$code, $body] = request($base, '/api/availability?date=' . $date . '&service=smp');
            check($code === 200 && in_array('10:00', json_decode($body, true)['slots'], true));
            [$code, $body] = request($base, '/api/availability?date=' . $date . '&service=podologia');
            check($code === 200 && !in_array('10:00', json_decode($body, true)['slots'], true));
            foreach (['/config.php', '/.env', '/data/bookings.json', '/app/booking.php', '/.git/config', '/.htaccess'] as $private) {
                check(request($base, $private)[0] === 404, 'Private file exposed: ' . $private);
            }
            foreach (['/', '/admin', '/booking.js', '/booking.css', '/admin.js', '/gallery.js'] as $asset) {
                check(request($base, $asset)[0] === 200, 'Asset missing: ' . $asset);
            }
            foreach (['dynamiczny', 'misseye-gabinet', 'perfect-love', 'przed-i-po', 'wizyta-w-gabinecie'] as $name) {
                $file = $root . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $name . '.mp4';
                [$code, $body, $headers] = request($base, '/media/' . $name . '.mp4', 'GET', ['Range: bytes=0-1023']);
                check($code === 206 && strlen($body) === 1024, 'Video byte range is not served');
                check($body === file_get_contents($file, false, null, 0, 1024));
                check(in_array('Content-Type: video/mp4', $headers, true));
                check(in_array('Content-Range: bytes 0-1023/' . filesize($file), $headers, true));
                [$code, $poster, $headers] = request($base, '/media/' . $name . '.webp');
                check($code === 200 && substr($poster, 0, 4) === 'RIFF' && substr($poster, 8, 4) === 'WEBP');
                check(in_array('Content-Type: image/webp', $headers, true));
            }
            [$code, $suffix] = request($base, '/media/perfect-love.mp4', 'GET', ['Range: bytes=-64']);
            check($code === 206 && strlen($suffix) === 64);
            [$code, $empty] = request($base, '/media/perfect-love.mp4', 'GET', ['Range: bytes=999999999-']);
            check($code === 416 && $empty === '');
            [$code, $empty, $headers] = request($base, '/media/perfect-love.mp4', 'HEAD', ['Range: bytes=0-63']);
            check($code === 200 && $empty === '');
            check(in_array('Content-Length: ' . filesize($root . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'perfect-love.mp4'), $headers, true));
            check(request($base, '/media/missing.mp4')[0] === 404);
            foreach (['braz-detal', 'braz', 'nude', 'nude-detal'] as $name) {
                [$code, $photo, $headers] = request($base, '/media/pitstop-nails-' . $name . '.webp');
                check($code === 200 && substr($photo, 0, 4) === 'RIFF' && substr($photo, 8, 4) === 'WEBP');
                check(in_array('Content-Type: image/webp', $headers, true));
            }
            check(request($base, '/media/bookings.json')[0] === 404);
            check(request($base, '/api/bookings', 'POST', [...$jsonHeaders, 'Origin: https://other.invalid'], '{}')[0] === 403);
            check(request($base, '/api/bookings', 'POST', $jsonHeaders, '{')[0] === 400);
            check(request($base, '/api/bookings', 'POST', $jsonHeaders, str_repeat('x', 5000))[0] === 413);
            check(request($base, '/api/bookings', 'POST', ['Content-Type: text/plain'], '{}')[0] === 415);
            check(request($base, '/api/availability?date[]=2026-10-30&service=smp')[0] === 400);
            $config['data_file'] = $root . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'bookings.json';
            file_put_contents($configFile, '<?php return ' . var_export($config, true) . ';');
            expect(fn () => \Misseye\loadConfig($configFile), null, RuntimeException::class);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    });
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($temporary);
}
echo "$count tests, $failures failures\n";
exit($failures === 0 ? 0 : 1);
