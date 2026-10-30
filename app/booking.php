<?php
declare(strict_types=1);

namespace Misseye;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'json.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'offer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'email.php';

const ZONE = 'Europe/Warsaw';
const WHATSAPP_NUMBER = '48665638033';
const SERVICE_AREA = 'Września lub Poznań — lokalizacja ustalana z managerem';
const NOTIFICATIONS = ['pending', 'sending', 'accepted', 'failed', 'unknown', 'not_requested', 'unconfigured'];

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

function specialists(): array
{
    return [
        [
            'id' => 'pitstop-1', 'name' => 'Specjalistka do_nails (@do_nails.beauty)', 'brand' => 'PitSTOP',
            'location' => SERVICE_AREA,
        ],
        ['id' => 'misseye-1', 'name' => 'Specjalistka MissEye', 'brand' => 'MissEye', 'location' => SERVICE_AREA],
    ];
}

function services(): array
{
    $catalog = [
        ['id' => 'smp', 'name' => 'Konsultacja SMP', 'minutes' => 30, 'specialistId' => 'misseye-1'],
        ['id' => 'brwi', 'name' => 'Konsultacja PM — makijaż permanentny', 'minutes' => 30, 'specialistId' => 'misseye-1'],
        ['id' => 'nails', 'name' => 'Konsultacja Nails art — dłonie', 'minutes' => 30, 'specialistId' => 'pitstop-1'],
        ['id' => 'podologia', 'name' => 'Konsultacja podologiczna — stopy', 'minutes' => 30, 'specialistId' => 'pitstop-1'],
        ['id' => 'hybryda', 'name' => 'Manicure hybrydowy', 'minutes' => 90, 'specialistId' => 'pitstop-1'],
        ['id' => 'przedluzanie', 'name' => 'Przedłużanie artystyczne', 'minutes' => 240, 'specialistId' => 'pitstop-1'],
        ['id' => 'laminacja', 'name' => 'Laminacja i bieżąca pielęgnacja', 'minutes' => 60, 'specialistId' => 'pitstop-1'],
        ['id' => 'stopy-podstawa', 'name' => 'Stopy — podstawa', 'minutes' => 90, 'specialistId' => 'pitstop-1'],
        ['id' => 'stopy-1', 'name' => 'Stopy — 1 etap', 'minutes' => 150, 'specialistId' => 'pitstop-1'],
        ['id' => 'stopy-2', 'name' => 'Stopy — 2 etap', 'minutes' => 220, 'specialistId' => 'pitstop-1'],
        ['id' => 'stopy-3', 'name' => 'Stopy — 3 etap', 'minutes' => 280, 'specialistId' => 'pitstop-1'],
        ['id' => 'smp-1', 'name' => 'Mikropigmentacja skóry głowy — 1 etap', 'minutes' => 200, 'specialistId' => 'misseye-1'],
        ['id' => 'smp-2', 'name' => 'Mikropigmentacja skóry głowy — 2 etap', 'minutes' => 200, 'specialistId' => 'misseye-1'],
        ['id' => 'smp-3', 'name' => 'Mikropigmentacja skóry głowy — 3 etap', 'minutes' => 220, 'specialistId' => 'misseye-1'],
        ['id' => 'henna', 'name' => 'Brwi — regulacja i henna', 'minutes' => 60, 'specialistId' => 'misseye-1'],
        ['id' => 'pm-zabieg', 'name' => 'Makijaż permanentny', 'minutes' => 220, 'specialistId' => 'misseye-1'],
    ];
    $people = array_column(specialists(), null, 'id');
    return array_map(static function (array $service) use ($people): array {
        $person = $people[$service['specialistId']];
        return [
            ...$service, 'brand' => $person['brand'], 'specialist' => $person['name'],
            'location' => $person['location'], 'reservedMinutes' => roundedMinutes($service['minutes']),
            'firstConsultationFree' => in_array($service['id'], ['smp', 'brwi', 'nails', 'podologia'], true),
        ];
    }, $catalog);
}

function serviceFor(mixed $id): array
{
    foreach (services() as $service) {
        if ($service['id'] === $id) {
            return $service;
        }
    }
    throw new HttpError(400, 'Wybierz usługę.');
}

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function validId(mixed $id): bool
{
    return is_string($id) && preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $id) === 1;
}

function parseDate(mixed $date): DateTimeImmutable
{
    if (!is_string($date) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)) {
        throw new HttpError(400, 'Wybierz prawidłową datę.');
    }
    $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(ZONE));
    if ($value === false || $value->format('Y-m-d') !== $date) {
        throw new HttpError(400, 'Nieprawidłowa data.');
    }
    return $value;
}

function localDate(DateTimeImmutable $now): string
{
    return $now->setTimezone(new DateTimeZone(ZONE))->format('Y-m-d');
}

function lastDate(DateTimeImmutable $now): string
{
    return parseDate(localDate($now))->modify('+90 days')->format('Y-m-d');
}

function milliseconds(DateTimeImmutable $date): int
{
    return (int) $date->format('Uv');
}

function instant(string $date, string $time): int
{
    $day = parseDate($date);
    if (!preg_match('/\A([01]\d|2[0-3]):([0-5]\d)\z/', $time, $parts)) {
        throw new HttpError(400, 'Wybierz prawidłową godzinę.');
    }
    return milliseconds($day->setTime((int) $parts[1], (int) $parts[2]));
}

function schedule(mixed $date, array $service, DateTimeImmutable $now): array
{
    $day = parseDate($date);
    if ($date < localDate($now) || $date > lastDate($now)) {
        throw new HttpError(400, 'Rezerwacje są dostępne na najbliższe 90 dni.');
    }
    $weekday = (int) $day->format('w');
    if ($weekday === 0) {
        return [];
    }
    $slots = [];
    $duration = $service['reservedMinutes'];
    for ($minute = ($weekday === 6 ? 600 : 480); $minute + $duration <= 1200; $minute += 30) {
        $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
        $start = instant($date, $time);
        if ($start > milliseconds($now)) {
            $slots[] = ['time' => $time, 'start' => $start, 'end' => $start + $duration * 60000];
        }
    }
    return $slots;
}

function normalize(mixed $input): array
{
    if (!is_array($input) || array_is_list($input)) {
        throw new HttpError(400, 'Nieprawidłowy formularz.');
    }
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $phone = is_string($input['phone'] ?? null) ? preg_replace('/[\s()-]/', '', $input['phone']) : '';
    if (preg_match('/\A[^\p{C}]{2,80}\z/u', $name) !== 1) {
        throw new HttpError(400, 'Podaj imię (2–80 znaków).');
    }
    if (!preg_match('/\A\+[1-9]\d{7,14}\z/', $phone)) {
        throw new HttpError(400, 'Podaj telefon z kodem kraju, np. +48 i 9 cyfr.');
    }
    if (($input['consent'] ?? null) !== true) {
        throw new HttpError(400, 'Potwierdź zapoznanie się z informacją o danych.');
    }
    if (!is_bool($input['whatsappConsent'] ?? null)) {
        throw new HttpError(400, 'Nieprawidłowa zgoda WhatsApp.');
    }
    $emailConsent = $input['emailConsent'] ?? false;
    $email = is_string($input['email'] ?? '') ? trim($input['email'] ?? '') : null;
    if (!is_bool($emailConsent) || $email === null || strlen($email) > 254
        || ($emailConsent ? !filter_var($email, FILTER_VALIDATE_EMAIL) : $email !== '')) {
        throw new HttpError(400, 'Przypomnienie wymaga poprawnego adresu e-mail i osobnej zgody. Bez zgody pozostaw adres pusty.');
    }
    if (isset($input['offerRevision']) && (!is_int($input['offerRevision']) || $input['offerRevision'] < 0)) {
        throw new HttpError(400, 'Nieprawidłowa wersja oferty.');
    }
    if (!validId($input['requestId'] ?? null)) {
        throw new HttpError(400, 'Nieprawidłowy identyfikator zgłoszenia. Odśwież stronę.');
    }
    serviceFor($input['service'] ?? null);
    parseDate($input['date'] ?? null);
    if (!is_string($input['time'] ?? null)) {
        throw new HttpError(400, 'Wybierz godzinę.');
    }
    instant($input['date'], $input['time']);
    return [
        'name' => $name, 'phone' => $phone, 'date' => $input['date'], 'time' => $input['time'],
        'service' => $input['service'], 'requestId' => $input['requestId'],
        'whatsappConsent' => $input['whatsappConsent'],
        'emailConsent' => $emailConsent, 'email' => $email, 'offerRevision' => $input['offerRevision'] ?? null,
    ];
}

function bookingSpecialist(array $booking): string
{
    $id = serviceFor($booking['service'])['specialistId'];
    if (array_key_exists('specialist_id', $booking) && $booking['specialist_id'] !== $id) {
        throw new RuntimeException('Booking specialist does not match the service.');
    }
    return $id;
}

function bookingLocation(array $booking): string
{
    // Before location snapshots, MissEye appointments were offered only in Września.
    return $booking['location'] ?? (bookingSpecialist($booking) === 'misseye-1' ? 'Września' : SERVICE_AREA);
}

function bookingMinutes(array $booking): int
{
    $minutes = array_key_exists('minutes', $booking) ? $booking['minutes'] : serviceFor($booking['service'])['minutes'];
    if (!is_int($minutes) || $minutes < 1 || $minutes > 720) {
        throw new RuntimeException('Invalid stored booking duration.');
    }
    return $minutes;
}

function roundedMinutes(int $minutes): int
{
    return intdiv($minutes + 59, 60) * 60;
}

function bookingReservedMinutes(array $booking): int
{
    $minutes = bookingMinutes($booking);
    if (!array_key_exists('reserved_minutes', $booking)) {
        return $minutes;
    }
    if (!is_int($booking['reserved_minutes']) || $booking['reserved_minutes'] !== roundedMinutes($minutes)) {
        throw new RuntimeException('Invalid reserved calendar duration.');
    }
    return $booking['reserved_minutes'];
}

function specialistFor(mixed $id): array
{
    foreach (specialists() as $person) {
        if ($person['id'] === $id) {
            return $person;
        }
    }
    throw new HttpError(400, 'Wybierz specjalistę.');
}

function validateBookings(mixed $data): void
{
    if (!is_array($data) || ($data['version'] ?? null) !== 1
        || !isset($data['bookings']) || !is_array($data['bookings']) || !array_is_list($data['bookings'])) {
        throw new RuntimeException('Invalid booking storage schema. Restore a verified backup.');
    }
    if (array_key_exists('offer', $data)) {
        validateOffer($data['offer']);
    }
    $ids = $requests = $intervals = [];
    foreach ($data['bookings'] as $booking) {
        if (!is_array($booking) || !validId($booking['id'] ?? null)
            || !is_bool($booking['whatsapp_consent'] ?? null)
            || !in_array($booking['status'] ?? null, ['confirmed', 'cancelled'], true)
            || !in_array($booking['notification'] ?? null, NOTIFICATIONS, true)
            || !is_int($booking['starts'] ?? null) || !is_int($booking['ends'] ?? null)
            || !is_string($booking['created_at'] ?? null) || !is_string($booking['consent_at'] ?? null)
            || !array_key_exists('message_id', $booking) || ($booking['message_id'] !== null && !is_string($booking['message_id']))
            || !array_key_exists('notification_started_at', $booking)
            || ($booking['notification_started_at'] !== null && !is_int($booking['notification_started_at']))) {
            throw new RuntimeException('Invalid booking record in storage.');
        }
        try {
            $normalized = normalize([
                'name' => $booking['name'] ?? null, 'phone' => $booking['phone'] ?? null,
                'date' => $booking['date'] ?? null, 'time' => $booking['time'] ?? null,
                'service' => $booking['service'] ?? null, 'requestId' => $booking['request_id'] ?? null,
                'consent' => true, 'whatsappConsent' => $booking['whatsapp_consent'],
                'email' => $booking['email'] ?? '', 'emailConsent' => $booking['email_consent'] ?? false,
            ]);
        } catch (HttpError $error) {
            throw new RuntimeException('Invalid booking fields in storage.', 0, $error);
        }
        if ($normalized['name'] !== $booking['name'] || $normalized['phone'] !== $booking['phone']
            || instant($booking['date'], $booking['time']) !== $booking['starts']
            || $booking['ends'] !== $booking['starts'] + bookingReservedMinutes($booking) * 60000
            || (array_key_exists('revision', $booking) && (!is_int($booking['revision']) || $booking['revision'] < 0))
            || isset($ids[$booking['id']]) || isset($requests[$booking['request_id']])) {
            throw new RuntimeException('Inconsistent booking records in storage.');
        }
        $ids[$booking['id']] = $requests[$booking['request_id']] = true;
        if (array_key_exists('email_consent', $booking)
            && (!is_bool($booking['email_consent']) || !is_string($booking['email'] ?? null)
                || !in_array($booking['email_notification'] ?? null, EMAIL_STATES, true)
                || ($booking['email_consent'] === false && $booking['email_notification'] !== 'not_requested')
                || ($booking['email_consent'] && $booking['email_notification'] === 'not_requested')
                || ($booking['email_for'] ?? null) !== $booking['starts']
                || !array_key_exists('email_started_at', $booking)
                || ($booking['email_notification'] === 'sending' && !is_int($booking['email_started_at']))
                || ($booking['email_started_at'] !== null && !is_int($booking['email_started_at'])))) {
            throw new RuntimeException('Invalid email reminder record.');
        }
        if (array_key_exists('price', $booking) || array_key_exists('price_label', $booking)) {
            validatePrice($booking['price'] ?? null);
            if (($booking['price_label'] ?? null) !== priceLabel($booking['price'])) {
                throw new RuntimeException('Invalid stored appointment price.');
            }
        }
        if (array_key_exists('location', $booking) && (!is_string($booking['location']) || $booking['location'] === '' || strlen($booking['location']) > 600
            || preg_match('/[\p{C}]/u', $booking['location']) !== 0)) {
            throw new RuntimeException('Invalid appointment location.');
        }
        $specialist = bookingSpecialist($booking);
        if ($booking['status'] === 'confirmed') {
            $intervals[$specialist][] = [$booking['starts'], $booking['ends']];
        }
    }
    if (array_key_exists('blocks', $data) && (!is_array($data['blocks']) || !array_is_list($data['blocks']))) {
        throw new RuntimeException('Invalid calendar blocks.');
    }
    $blockIds = [];
    foreach ($data['blocks'] ?? [] as $block) {
        if (!is_array($block) || !validId($block['id'] ?? null) || isset($blockIds[$block['id']])
            || !in_array($block['status'] ?? null, ['active', 'cancelled'], true)
            || !in_array($block['reason'] ?? null, ['break', 'leave', 'other'], true)
            || !is_string($block['date'] ?? null) || !is_string($block['time'] ?? null)
            || !is_string($block['end_date'] ?? null) || !is_string($block['end_time'] ?? null)
            || !is_int($block['starts'] ?? null) || !is_int($block['ends'] ?? null)
            || $block['starts'] >= $block['ends']) {
            throw new RuntimeException('Invalid calendar block record.');
        }
        try {
            specialistFor($block['specialist_id'] ?? null);
            if (instant($block['date'] ?? '', $block['time'] ?? '') !== $block['starts']
                || instant($block['end_date'] ?? '', $block['end_time'] ?? '') !== $block['ends']) {
                throw new RuntimeException('Inconsistent calendar block.');
            }
        } catch (HttpError $error) {
            throw new RuntimeException('Invalid block fields.', 0, $error);
        }
        $blockIds[$block['id']] = true;
        if ($block['status'] === 'active') {
            $intervals[$block['specialist_id']][] = [$block['starts'], $block['ends']];
        }
    }
    foreach ($intervals as $calendar) {
        usort($calendar, static fn ($a, $b) => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($calendar); $i++) {
            if ($calendar[$i][0] < $calendar[$i - 1][1]) {
                throw new RuntimeException('Overlapping bookings in a specialist calendar.');
            }
        }
    }
}

final class BookingStore
{
    private JsonFile|MysqlStore $file;

    public function __construct(string|MysqlStore $storage)
    {
        $this->file = is_string($storage) ? new JsonFile($storage, validateBookings(...)) : $storage;
    }

    public function initialize(): void
    {
        $this->file->initialize(['version' => 1, 'bookings' => []]);
    }

    public function import(array $bookings): void
    {
        $this->file->write(static function (array &$data) use ($bookings): void {
            if ($data['bookings'] !== []) {
                throw new RuntimeException('Booking storage is not empty. Import refused.');
            }
            $data['bookings'] = $bookings;
        });
    }

    public function catalog(): array
    {
        return $this->file->read(offerCatalog(...));
    }

    public function saveOffer(mixed $input, ?DateTimeImmutable $now = null): array
    {
        try {
            validateOffer($input);
        } catch (RuntimeException $error) {
            throw new HttpError(400, 'Nieprawidłowa oferta: sprawdź ceny, dni i adresy.');
        }
        $now ??= new DateTimeImmutable();
        return $this->file->write(static function (array &$data) use ($input, $now): array {
            $current = $data['offer'] ?? defaultOffer();
            $same = $input['prices'] === $current['prices'] && $input['week'] === $current['week']
                && $input['addresses'] === $current['addresses'];
            if ($same && in_array($current['revision'], [$input['revision'], $input['revision'] + 1], true)) {
                return $current;
            }
            if ($input['revision'] !== $current['revision']) {
                throw new HttpError(409, 'Oferta została zmieniona. Odśwież stronę panelu przed kolejną edycją.');
            }
            $next = [...$input, 'revision' => $current['revision'] + 1];
            foreach ($data['bookings'] as $booking) {
                if ($booking['status'] === 'confirmed' && $booking['starts'] > milliseconds($now)
                    && bookingSpecialist($booking) === 'pitstop-1'
                    && visitLocation($data, $booking['service'], $booking['date'])
                        !== visitLocation([...$data, 'offer' => $next], $booking['service'], $booking['date'])) {
                    throw new HttpError(409, 'Zmiana miejsca lub zamknięcie dnia koliduje z przyszłą wizytą PitSTOP (' . $booking['date'] . '). Najpierw uzgodnij i przełóż wizytę; nic nie zostało zapisane.');
                }
            }
            $data['offer'] = $next;
            return $next;
        });
    }

    private function available(array $data, mixed $date, mixed $service, DateTimeImmutable $now, ?string $exclude = null, ?int $reservedMinutes = null): array
    {
        $free = [];
        $details = serviceFor($service);
        $details['reservedMinutes'] = $reservedMinutes ?? $details['reservedMinutes'];
        $schedule = schedule($date, $details, $now);
        if (visitLocation($data, $details['id'], $date)['key'] === 'closed') {
            return [];
        }
        foreach ($schedule as $slot) {
            foreach ($data['bookings'] as $booking) {
                if ($booking['id'] !== $exclude && $booking['status'] === 'confirmed'
                    && bookingSpecialist($booking) === $details['specialistId']
                    && $slot['start'] < $booking['ends'] && $slot['end'] > $booking['starts']) {
                    continue 2;
                }
            }
            foreach ($data['blocks'] ?? [] as $block) {
                if ($block['status'] === 'active' && $block['specialist_id'] === $details['specialistId']
                    && $slot['start'] < $block['ends'] && $slot['end'] > $block['starts']) {
                    continue 2;
                }
            }
            $free[] = $slot['time'];
        }
        return $free;
    }

    public function availability(mixed $date, mixed $service, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        return $this->file->read(fn ($data) => $this->available($data, $date, $service, $now));
    }

    public function availabilityInfo(mixed $date, mixed $service): array
    {
        return $this->file->read(function ($data) use ($date, $service): array {
            $slots = $this->available($data, $date, $service, new DateTimeImmutable());
            $offer = $data['offer'] ?? defaultOffer();
            return [
                'slots' => $slots, 'offerRevision' => $offer['revision'],
                'priceLabel' => priceLabel($offer['prices'][$service]),
                'location' => visitLocation($data, $service, $date)['label'],
            ];
        });
    }

    public function reserve(mixed $input, bool $automated, ?DateTimeImmutable $now = null, bool $emailEnabled = false): array
    {
        $request = normalize($input);
        $now ??= new DateTimeImmutable();
        return $this->file->write(function (array &$data) use ($request, $automated, $now, $emailEnabled): array {
            foreach ($data['bookings'] as $previous) {
                if ($previous['request_id'] !== $request['requestId']) {
                    continue;
                }
                $same = $previous['whatsapp_consent'] === $request['whatsappConsent'];
                $same = $same && ($previous['email_consent'] ?? false) === $request['emailConsent']
                    && ($previous['email'] ?? '') === $request['email'];
                foreach (['name', 'phone', 'date', 'time', 'service'] as $key) {
                    $same = $same && $previous[$key] === $request[$key];
                }
                if (!$same || $previous['status'] !== 'confirmed') {
                    throw new HttpError(409, 'To zgłoszenie zostało już użyte. Odśwież formularz.');
                }
                return ['booking' => $previous, 'created' => false];
            }
            $offer = $data['offer'] ?? defaultOffer();
            if ($request['offerRevision'] !== null && $request['offerRevision'] !== $offer['revision']) {
                throw new HttpError(409, 'Oferta uległa zmianie. Sprawdź aktualną cenę i miejsce przed ponownym zapisem.');
            }
            if ($request['emailConsent'] && !$emailEnabled) {
                throw new HttpError(400, 'Przypomnienia e-mail są obecnie wyłączone. Zapisz wizytę bez tej zgody lub skontaktuj się z managerem.');
            }
            if (!in_array($request['time'], $this->available($data, $request['date'], $request['service'], $now), true)) {
                throw new HttpError(409, 'Ten termin nie jest już dostępny. Wybierz inny.');
            }
            $start = instant($request['date'], $request['time']);
            $stamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
            $booking = [
                'id' => uuid(), 'request_id' => $request['requestId'],
                'date' => $request['date'], 'time' => $request['time'], 'service' => $request['service'],
                'specialist_id' => serviceFor($request['service'])['specialistId'],
                'minutes' => serviceFor($request['service'])['minutes'], 'revision' => 0,
                'reserved_minutes' => serviceFor($request['service'])['reservedMinutes'],
                'starts' => $start, 'ends' => $start + serviceFor($request['service'])['reservedMinutes'] * 60000,
                'name' => $request['name'], 'phone' => $request['phone'],
                'whatsapp_consent' => $request['whatsappConsent'], 'consent_at' => $stamp,
                'status' => 'confirmed', 'created_at' => $stamp,
                'notification' => $request['whatsappConsent'] ? ($automated ? 'pending' : 'unconfigured') : 'not_requested',
                'message_id' => null, 'notification_started_at' => null,
                'price_label' => priceLabel($offer['prices'][$request['service']]),
                'price' => $offer['prices'][$request['service']],
                'location' => visitLocation($data, $request['service'], $request['date'])['label'],
                'email' => $request['email'], 'email_consent' => $request['emailConsent'],
                'email_notification' => $request['emailConsent'] ? 'pending' : 'not_requested',
                'email_for' => $start, 'email_started_at' => null,
            ];
            $data['bookings'][] = $booking;
            return ['booking' => $booking, 'created' => true];
        });
    }

    public function roundFutureBookings(bool $apply, ?DateTimeImmutable $now = null, ?string $backupPath = null): array
    {
        $now ??= new DateTimeImmutable();
        $operation = static function (array &$data) use ($apply, $now, $backupPath): array {
            $candidate = $data;
            $changed = 0;
            $conflicts = [];
            foreach ($candidate['bookings'] as &$booking) {
                if ($booking['status'] !== 'confirmed' || $booking['starts'] <= milliseconds($now)) {
                    continue;
                }
                $minutes = bookingMinutes($booking);
                $reserved = roundedMinutes($minutes);
                if (($booking['reserved_minutes'] ?? null) === $reserved) {
                    continue;
                }
                $booking['minutes'] = $minutes;
                $booking['reserved_minutes'] = $reserved;
                $booking['ends'] = $booking['starts'] + $reserved * 60000;
                $booking['revision'] = ($booking['revision'] ?? 0) + 1;
                $changed++;
                if ($booking['ends'] > instant($booking['date'], '20:00')) {
                    $conflicts[] = ['type' => 'closing', 'date' => $booking['date'], 'time' => $booking['time'], 'specialist' => bookingSpecialist($booking)];
                }
            }
            unset($booking);
            $calendars = [];
            foreach ($candidate['bookings'] as $booking) {
                if ($booking['status'] === 'confirmed') {
                    $calendars[bookingSpecialist($booking)][] = $booking;
                }
            }
            foreach ($candidate['blocks'] ?? [] as $block) {
                if ($block['status'] === 'active') {
                    $calendars[$block['specialist_id']][] = $block;
                }
            }
            foreach ($calendars as $person => $entries) {
                usort($entries, static fn ($a, $b) => $a['starts'] <=> $b['starts']);
                for ($i = 0; $i < count($entries); $i++) {
                    for ($j = $i + 1; $j < count($entries) && $entries[$j]['starts'] < $entries[$i]['ends']; $j++) {
                        $conflicts[] = [
                            'type' => isset($entries[$i]['service'], $entries[$j]['service']) ? 'booking' : 'block',
                            'date' => $entries[$i]['date'], 'time' => $entries[$i]['time'],
                            'otherDate' => $entries[$j]['date'], 'otherTime' => $entries[$j]['time'], 'specialist' => $person,
                        ];
                    }
                }
            }
            $report = ['changed' => $changed, 'conflicts' => $conflicts, 'applied' => false];
            if (!$apply || $changed === 0 || $conflicts !== []) {
                return $report;
            }
            validateBookings($candidate);
            if ($backupPath === null || file_exists($backupPath)) {
                throw new RuntimeException('A new private backup path is required before rounding existing bookings.');
            }
            (new JsonFile($backupPath, validateBookings(...)))->initialize($data);
            $data = $candidate;
            return [...$report, 'applied' => true];
        };
        return $apply ? $this->file->write($operation) : $this->file->read($operation);
    }

    public function availableDays(mixed $date, mixed $service, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        schedule($date, serviceFor($service), $now);
        return $this->file->read(function ($data) use ($date, $service, $now): array {
            $days = [];
            $day = parseDate($date);
            for ($i = 0; $i < 7 && $day->format('Y-m-d') <= lastDate($now); $i++, $day = $day->modify('+1 day')) {
                $value = $day->format('Y-m-d');
                $times = $this->available($data, $value, $service, $now);
                $days[] = ['date' => $value, 'count' => count($times)];
            }
            return $days;
        });
    }

    public function nextAvailable(mixed $date, mixed $service, ?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable();
        schedule($date, serviceFor($service), $now);
        return $this->file->read(function ($data) use ($date, $service, $now): ?array {
            for ($day = parseDate($date); $day->format('Y-m-d') <= lastDate($now); $day = $day->modify('+1 day')) {
                $value = $day->format('Y-m-d');
                $times = $this->available($data, $value, $service, $now);
                if ($times !== []) {
                    return ['date' => $value, 'time' => $times[0]];
                }
            }
            return null;
        });
    }

    public function calendar(mixed $date, mixed $end): array
    {
        $startDay = parseDate($date);
        $endDay = parseDate($end);
        if ($end < $date || $startDay->diff($endDay)->days > 6) {
            throw new HttpError(400, 'Wybierz zakres od 1 do 7 dni.');
        }
        $start = milliseconds($startDay);
        $finish = milliseconds($endDay->modify('+1 day'));
        return $this->file->read(static function ($data) use ($date, $end, $start, $finish): array {
            return [
                'bookings' => array_values(array_map(static fn ($b) => [
                    ...$b, 'minutes' => bookingMinutes($b), 'revision' => $b['revision'] ?? 0,
                    'location' => bookingLocation($b),
                    'reserved_minutes' => bookingReservedMinutes($b),
                    'end_time' => (new DateTimeImmutable('@' . intdiv($b['ends'], 1000)))
                        ->setTimezone(new DateTimeZone(ZONE))->format('H:i'),
                ], array_filter($data['bookings'], static fn ($b) => $b['date'] >= $date && $b['date'] <= $end))),
                'blocks' => array_values(array_filter($data['blocks'] ?? [], static fn ($b) =>
                    $b['status'] === 'active' && $b['starts'] < $finish && $b['ends'] > $start)),
            ];
        });
    }

    public function reschedule(string $id, mixed $input, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        if (!is_array($input) || !is_int($input['revision'] ?? null) || $input['revision'] < 0
            || !is_string($input['date'] ?? null) || !is_string($input['time'] ?? null)) {
            throw new HttpError(400, 'Podaj nowy termin i wersję wizyty.');
        }
        parseDate($input['date']);
        instant($input['date'], $input['time']);
        return $this->file->write(function (array &$data) use ($id, $input, $now): array {
            foreach ($data['bookings'] as &$booking) {
                if ($booking['id'] !== $id) {
                    continue;
                }
                if ($booking['status'] !== 'confirmed' || $booking['starts'] <= milliseconds($now)) {
                    throw new HttpError(409, 'Nie można przełożyć anulowanej lub rozpoczętej wizyty.');
                }
                $revision = $booking['revision'] ?? 0;
                if ($booking['date'] === $input['date'] && $booking['time'] === $input['time']
                    && in_array($revision, [$input['revision'], $input['revision'] + 1], true)) {
                    return $booking;
                }
                if ($revision !== $input['revision']) {
                    throw new HttpError(409, 'Wizyta została zmieniona. Odśwież kalendarz.');
                }
                if (in_array($booking['notification'], ['pending', 'sending'], true)) {
                    throw new HttpError(409, 'Trwa obsługa potwierdzenia. Odśwież stan przed zmianą terminu.');
                }
                if (($booking['email_notification'] ?? '') === 'sending') {
                    throw new HttpError(409, 'Trwa wysyłka przypomnienia e-mail. Odśwież stan po zakończeniu zadania poczty.');
                }
                $minutes = bookingMinutes($booking);
                $reserved = bookingReservedMinutes($booking);
                if (!in_array($input['time'], $this->available($data, $input['date'], $booking['service'], $now, $id, $reserved), true)) {
                    throw new HttpError(409, 'Nowy termin koliduje z wizytą, blokadą lub godzinami pracy.');
                }
                $booking['date'] = $input['date'];
                $booking['time'] = $input['time'];
                $booking['starts'] = instant($input['date'], $input['time']);
                $booking['ends'] = $booking['starts'] + $reserved * 60000;
                $booking['minutes'] = $minutes;
                $booking['revision'] = $revision + 1;
                $booking['updated_at'] = $now->format(DATE_ATOM);
                $booking['location'] = visitLocation($data, $booking['service'], $input['date'])['label'];
                if (array_key_exists('email_consent', $booking)) {
                    $booking['email_for'] = $booking['starts'];
                    $booking['email_notification'] = $booking['email_consent'] ? 'pending' : 'not_requested';
                    $booking['email_started_at'] = null;
                }
                return $booking;
            }
            throw new HttpError(404, 'Nie znaleziono rezerwacji.');
        });
    }

    public function addBlock(mixed $input, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        if (!is_array($input) || !validId($input['id'] ?? null)
            || !in_array($input['reason'] ?? null, ['break', 'leave', 'other'], true)) {
            throw new HttpError(400, 'Nieprawidłowa blokada kalendarza.');
        }
        specialistFor($input['specialistId'] ?? null);
        foreach (['date', 'time', 'endDate', 'endTime'] as $key) {
            if (!is_string($input[$key] ?? null)) {
                throw new HttpError(400, 'Podaj początek i koniec blokady.');
            }
        }
        $start = instant($input['date'], $input['time']);
        $end = instant($input['endDate'], $input['endTime']);
        if ($start >= $end || $input['date'] < localDate($now) || $input['endDate'] > lastDate($now)) {
            throw new HttpError(400, 'Blokada musi mieć dodatni czas i mieścić się w najbliższych 90 dniach.');
        }
        $block = [
            'id' => $input['id'], 'specialist_id' => $input['specialistId'], 'status' => 'active',
            'reason' => $input['reason'], 'date' => $input['date'], 'time' => $input['time'],
            'end_date' => $input['endDate'], 'end_time' => $input['endTime'], 'starts' => $start, 'ends' => $end,
        ];
        return $this->file->write(static function (array &$data) use ($block): array {
            foreach ($data['blocks'] ?? [] as $previous) {
                if ($previous['id'] === $block['id']) {
                    if ($previous !== $block) {
                        throw new HttpError(409, 'To zgłoszenie blokady zostało już użyte.');
                    }
                    return $previous;
                }
            }
            foreach ([...$data['bookings'], ...($data['blocks'] ?? [])] as $entry) {
                $person = isset($entry['service']) ? bookingSpecialist($entry) : $entry['specialist_id'];
                if (in_array($entry['status'], ['confirmed', 'active'], true) && $person === $block['specialist_id']
                    && $entry['starts'] < $block['ends'] && $entry['ends'] > $block['starts']) {
                    throw new HttpError(409, 'Blokada koliduje z wizytą lub inną blokadą. Najpierw zmień istniejący wpis.');
                }
            }
            $data['blocks'][] = $block;
            return $block;
        });
    }

    public function cancelBlock(string $id): array
    {
        return $this->file->write(static function (array &$data) use ($id): array {
            foreach ($data['blocks'] ?? [] as $index => $block) {
                if ($block['id'] === $id) {
                    $data['blocks'][$index]['status'] = 'cancelled';
                    return $data['blocks'][$index];
                }
            }
            throw new HttpError(404, 'Nie znaleziono blokady.');
        });
    }

    public function get(string $id): ?array
    {
        return $this->file->read(static function ($data) use ($id): ?array {
            foreach ($data['bookings'] as $booking) {
                if ($booking['id'] === $id) {
                    return $booking;
                }
            }
            return null;
        });
    }

    public function list(mixed $date): array
    {
        parseDate($date);
        return $this->file->read(static function ($data) use ($date): array {
            $bookings = array_values(array_filter($data['bookings'], static fn ($b) => $b['date'] === $date));
            usort($bookings, static fn ($a, $b) => [$a['time'], $a['created_at']] <=> [$b['time'], $b['created_at']]);
            return array_map(static fn ($booking) => [...$booking, 'location' => bookingLocation($booking)], $bookings);
        });
    }

    public function cancel(string $id): array
    {
        return $this->file->write(static function (array &$data) use ($id): array {
            foreach ($data['bookings'] as &$booking) {
                if ($booking['id'] === $id) {
                    if (($booking['email_notification'] ?? '') === 'sending') {
                        throw new HttpError(409, 'Trwa wysyłka przypomnienia e-mail. Odśwież stan po zakończeniu zadania poczty.');
                    }
                    $booking['status'] = 'cancelled';
                    return $booking;
                }
            }
            throw new HttpError(404, 'Nie znaleziono rezerwacji.');
        });
    }

    public function dueEmailReminders(?DateTimeImmutable $now = null): array
    {
        $stamp = milliseconds($now ?? new DateTimeImmutable());
        return $this->file->read(static fn ($data) => array_values(array_filter(
            $data['bookings'], static fn ($b) => $b['status'] === 'confirmed' && ($b['email_consent'] ?? false)
                && ($b['email_notification'] ?? '') === 'pending' && $b['starts'] > $stamp && $b['starts'] <= $stamp + 86400000
        )));
    }

    public function claimEmailReminder(string $id, int $starts, DateTimeImmutable $now): ?array
    {
        $stamp = milliseconds($now);
        return $this->file->write(static function (array &$data) use ($id, $starts, $stamp): ?array {
            foreach ($data['bookings'] as &$booking) {
                if ($booking['id'] === $id && $booking['starts'] === $starts && $booking['status'] === 'confirmed'
                    && ($booking['email_consent'] ?? false) && $booking['email_notification'] === 'pending'
                    && $starts > $stamp && $starts <= $stamp + 86400000) {
                    $booking['email_notification'] = 'sending';
                    $booking['email_started_at'] = $stamp;
                    return $booking;
                }
            }
            return null;
        });
    }

    public function finishEmailReminder(string $id, int $starts, string $status): void
    {
        if (!in_array($status, ['accepted', 'failed', 'unknown'], true)) {
            throw new RuntimeException('Invalid email outcome.');
        }
        $this->file->write(static function (array &$data) use ($id, $starts, $status): void {
            foreach ($data['bookings'] as &$booking) {
                if ($booking['id'] === $id && $booking['starts'] === $starts && $booking['email_notification'] === 'sending') {
                    $booking['email_notification'] = $status;
                    return;
                }
            }
            throw new RuntimeException('Email claim is no longer current. Verify the mail transport manually.');
        });
    }

    public function recoverEmailReminders(DateTimeImmutable $now): int
    {
        $stamp = milliseconds($now);
        return $this->file->write(static function (array &$data) use ($stamp): int {
            $recovered = 0;
            foreach ($data['bookings'] as &$booking) {
                if (($booking['email_notification'] ?? '') === 'sending' && $booking['email_started_at'] < $stamp - 300000) {
                    $booking['email_notification'] = 'unknown';
                    $recovered++;
                    error_log('Interrupted email reminder marked unknown; manual verification required.');
                }
            }
            return $recovered;
        });
    }

    public function stopEmailReminder(string $id): array
    {
        return $this->file->write(static function (array &$data) use ($id): array {
            foreach ($data['bookings'] as &$booking) {
                if ($booking['id'] === $id) {
                    if (($booking['email_notification'] ?? '') === 'sending') {
                        throw new HttpError(409, 'Trwa wysyłka przypomnienia. Odśwież stan po zakończeniu zadania poczty.');
                    }
                    $booking['email_consent'] = false;
                    $booking['email'] = '';
                    $booking['email_notification'] = 'not_requested';
                    $booking['email_for'] = $booking['starts'];
                    $booking['email_started_at'] = null;
                    return $booking;
                }
            }
            throw new HttpError(404, 'Nie znaleziono rezerwacji.');
        });
    }

    public function pending(): array
    {
        return $this->file->read(static fn ($data) => array_values(array_filter(
            $data['bookings'], static fn ($b) => $b['status'] === 'confirmed' && $b['notification'] === 'pending'
        )));
    }

    public function recoverNotifications(bool $configured, ?int $now = null): void
    {
        $now ??= milliseconds(new DateTimeImmutable());
        $this->file->write(static function (array &$data) use ($configured, $now): void {
            foreach ($data['bookings'] as &$booking) {
                if ($booking['notification'] === 'sending' && ($booking['notification_started_at'] ?? 0) < $now - 120000) {
                    $booking['notification'] = 'unknown';
                }
                if (!$configured && $booking['notification'] === 'pending') {
                    $booking['notification'] = 'unconfigured';
                }
            }
        });
    }

    public function claimNotification(string $id): ?array
    {
        return $this->file->write(static function (array &$data) use ($id): ?array {
            foreach ($data['bookings'] as &$booking) {
                if ($booking['id'] === $id && $booking['status'] === 'confirmed'
                    && $booking['whatsapp_consent'] && $booking['notification'] === 'pending') {
                    $booking['notification'] = 'sending';
                    $booking['notification_started_at'] = milliseconds(new DateTimeImmutable());
                    return $booking;
                }
            }
            return null;
        });
    }

    public function finishNotification(string $id, string $status, ?string $messageId = null): void
    {
        if (!in_array($status, ['accepted', 'failed', 'unknown'], true)) {
            throw new RuntimeException('Invalid notification outcome.');
        }
        $this->file->write(static function (array &$data) use ($id, $status, $messageId): void {
            foreach ($data['bookings'] as &$booking) {
                if ($booking['id'] === $id) {
                    $booking['notification'] = $status;
                    $booking['message_id'] = $messageId;
                    return;
                }
            }
            throw new RuntimeException('Notification booking is missing.');
        });
    }
}

function publicBooking(array $booking): array
{
    $service = serviceFor($booking['service']);
    $location = bookingLocation($booking);
    $text = "Dzień dobry, mam rezerwację {$service['brand']}: {$service['name']}, {$booking['date']}, godz. {$booking['time']} (czas polski). Miejsce: {$location}. Numer rezerwacji: {$booking['id']}.";
    return [
        'id' => $booking['id'], 'date' => $booking['date'], 'time' => $booking['time'],
        'service' => $service['name'], 'minutes' => bookingMinutes($booking), 'status' => $booking['status'],
        'reservedMinutes' => bookingReservedMinutes($booking),
        'brand' => $service['brand'], 'specialist' => $service['specialist'],
        'location' => $location, 'priceLabel' => $booking['price_label'] ?? 'Cena do ustalenia',
        'emailReminder' => $booking['email_notification'] ?? 'not_requested',
        'specialistId' => bookingSpecialist($booking),
        'notification' => $booking['notification'],
        'rescheduled' => isset($booking['updated_at']),
        'whatsappUrl' => 'https://wa.me/' . WHATSAPP_NUMBER . '?text=' . rawurlencode($text),
    ];
}

function whatsappConfig(array $settings): ?array
{
    $required = ['access_token', 'phone_number_id', 'graph_version', 'template_name'];
    foreach ([...$required, 'template_language'] as $key) {
        if (!is_string($settings[$key] ?? null)) {
            throw new RuntimeException('Invalid WhatsApp configuration fields.');
        }
    }
    $filled = array_filter($required, static fn ($key) => $settings[$key] !== '');
    if ($filled === []) {
        return null;
    }
    if (count($filled) !== count($required)) {
        throw new RuntimeException('Incomplete WhatsApp configuration.');
    }
    if (!preg_match('/\Av\d+\.\d+\z/', $settings['graph_version'])
        || !preg_match('/\A\d+\z/', $settings['phone_number_id'])
        || !preg_match('/\A[a-z0-9_]+\z/', $settings['template_name'])
        || !is_string($settings['template_language'] ?? null)
        || !preg_match('/\A[a-z]{2}(?:_[A-Z]{2})?\z/', $settings['template_language'])) {
        throw new RuntimeException('Invalid WhatsApp configuration.');
    }
    return $settings;
}

function sendWhatsApp(array $config, array $payload): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('PHP cURL extension is required for WhatsApp.');
    }
    $handle = curl_init("https://graph.facebook.com/{$config['graph_version']}/{$config['phone_number_id']}/messages");
    if ($handle === false) {
        throw new RuntimeException('Cannot initialize WhatsApp request.');
    }
    try {
        curl_setopt_array($handle, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['access_token'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($handle);
        if ($body === false) {
            throw new RuntimeException('WhatsApp transport failed (cURL ' . curl_errno($handle) . ').');
        }
        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body];
    } finally {
        curl_close($handle);
    }
}

function notify(BookingStore $store, string $id, ?array $config, ?callable $sender = null): void
{
    if ($config === null) {
        return;
    }
    $booking = $store->claimNotification($id);
    if ($booking === null) {
        return;
    }
    $service = serviceFor($booking['service']);
    $payload = [
        'messaging_product' => 'whatsapp', 'to' => substr($booking['phone'], 1), 'type' => 'template',
        'template' => [
            'name' => $config['template_name'], 'language' => ['code' => $config['template_language']],
            'components' => [[
                'type' => 'body', 'parameters' => array_map(
                    static fn ($text) => ['type' => 'text', 'text' => $text],
                    [$service['brand'] . ' — ' . $service['name'] . ' (' . bookingLocation($booking) . ')',
                        $booking['date'], $booking['time'], $booking['id']]
                ),
            ]],
        ],
    ];
    try {
        $response = ($sender ?? sendWhatsApp(...))($config, $payload);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            error_log('WhatsApp provider rejected notification (HTTP ' . $response['status'] . ').');
            $store->finishNotification($id, 'failed');
            return;
        }
        $body = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $messageId = $body['messages'][0]['id'] ?? null;
        if (!is_string($messageId) || $messageId === '') {
            throw new RuntimeException('WhatsApp response has no message ID.');
        }
    } catch (RuntimeException | \JsonException $error) {
        error_log('WhatsApp outcome unknown; manual verification required (' . get_class($error) . ').');
        $store->finishNotification($id, 'unknown');
        return;
    }
    // Meta acceptance is not a delivery receipt.
    $store->finishNotification($id, 'accepted', $messageId);
}
