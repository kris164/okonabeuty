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
    run('Warsaw time, DST, local midnight and 90 calendar days', function () use ($now): void {
        check(\Misseye\instant('2026-10-23', '08:00') === 1000 * strtotime('2026-10-23T06:00:00Z'));
        check(\Misseye\instant('2026-10-30', '08:00') === 1000 * strtotime('2026-10-30T07:00:00Z'));
        check(\Misseye\instant('2027-03-29', '08:00') === 1000 * strtotime('2027-03-29T06:00:00Z'));
        check(\Misseye\localDate(new DateTimeImmutable('2026-10-29T23:30:00Z')) === '2026-10-30');
        check(\Misseye\lastDate($now) === '2027-01-27');
        check(\Misseye\lastDate(new DateTimeImmutable('2026-09-01T22:30:00Z')) === '2026-12-01');
    });

    run('four consultation services belong to two independent specialists', function () use ($now): void {
        $services = \Misseye\services();
        check(array_column($services, 'id') === ['smp', 'brwi', 'nails', 'podologia']);
        check(count(\Misseye\specialists()) === 2);
        foreach ($services as $service) {
            check($service['minutes'] === 30);
            check($service['specialistId'] === (in_array($service['id'], ['smp', 'brwi'], true) ? 'misseye-1' : 'pitstop-1'));
            if ($service['specialistId'] === 'pitstop-1') {
                check(str_contains($service['specialist'], '@do_nails.beauty'));
                check($service['location'] === 'Września lub Poznań — lokalizacja ustalana z managerem');
            } else {
                check($service['location'] === 'Września');
            }
            $calendar = store('hours-' . $service['id']);
            check(count($calendar->availability('2026-10-30', $service['id'], $now)) === 24);
            check(count($calendar->availability('2026-10-31', $service['id'], $now)) === 20);
            check($calendar->availability('2026-11-01', $service['id'], $now) === []);
        }
    });

    run('weekday and Saturday hours; Sunday, closing, past and invalid dates', function () use ($now): void {
        $store = store('hours');
        $slots = $store->availability('2026-10-30', 'smp', $now);
        check(count($slots) === 24 && $slots[0] === '08:00' && end($slots) === '19:30');
        $saturday = $store->availability('2026-10-31', 'brwi', $now);
        check(count($saturday) === 20 && $saturday[0] === '10:00');
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
        check($store->reserve(input(['service' => 'podologia', 'time' => '10:30']), false, $now)['created']);
        $reopened = new BookingStore($temporary . DIRECTORY_SEPARATOR . 'two-calendars.json');
        check(count($reopened->list('2026-10-30')) === 4);
    });

    run('legacy JSON needs no rewrite and inconsistent specialist assignments fail explicitly', function () use ($now, $temporary): void {
        $source = store('legacy-source');
        $old = $source->reserve(input(['service' => 'brwi']), false, $now)['booking'];
        unset($old['specialist_id']);
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
        check(str_starts_with($public['whatsappUrl'], 'https://wa.me/48696293848?text='));
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
        foreach (['11:00', '11:30', '12:00', '12:30'] as $time) {
            $inputs[] = input(['time' => $time]);
        }
        check(race($path, $inputs) === [201, 201, 201, 201]);
        check(count($store->list('2026-10-30')) === 5);
        $same = input(['time' => '13:00']);
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
        check($links->length === 4);
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
        $profileLinks = $xpath->query('//a[@href="https://instagram.com/do_nails.beauty"]');
        check($profileLinks->length === 3);
        foreach ($profileLinks as $link) {
            check($link->getAttribute('target') === '_blank');
            check(str_contains($link->getAttribute('rel'), 'noopener'));
        }
        check(str_contains($html, 'Rezerwacja online dotyczy terminu, nie wyboru adresu.'));
        check(!str_contains($html, '572 799 429'));
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
            check(count($catalog['services']) === 4 && count($catalog['specialists']) === 2);
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
            check(count($manager['services']) === 4 && count($manager['specialists']) === 2);
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
