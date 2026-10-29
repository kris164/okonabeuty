<?php
declare(strict_types=1);

namespace Misseye;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'json.php';

const ZONE = 'Europe/Warsaw';
const WHATSAPP_NUMBER = '48696293848';
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
        ['id' => 'misseye-1', 'name' => 'Specjalista MissEye', 'brand' => 'MissEye', 'location' => 'Września'],
        [
            'id' => 'pitstop-1', 'name' => 'Specjalista PitSTOP (@do_nails.beauty)', 'brand' => 'PitSTOP',
            'location' => 'Września lub Poznań — lokalizacja ustalana z managerem',
        ],
    ];
}

function services(): array
{
    $catalog = [
        ['id' => 'smp', 'name' => 'Konsultacja SMP', 'minutes' => 30, 'specialistId' => 'misseye-1'],
        ['id' => 'brwi', 'name' => 'Konsultacja PM — makijaż permanentny', 'minutes' => 30, 'specialistId' => 'misseye-1'],
        ['id' => 'nails', 'name' => 'Konsultacja Nails art — dłonie', 'minutes' => 30, 'specialistId' => 'pitstop-1'],
        ['id' => 'podologia', 'name' => 'Konsultacja podologiczna — stopy', 'minutes' => 30, 'specialistId' => 'pitstop-1'],
    ];
    $people = array_column(specialists(), null, 'id');
    return array_map(static function (array $service) use ($people): array {
        $person = $people[$service['specialistId']];
        return [
            ...$service, 'brand' => $person['brand'], 'specialist' => $person['name'],
            'location' => $person['location'],
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
    throw new HttpError(400, 'Wybierz rodzaj konsultacji.');
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
    for ($minute = ($weekday === 6 ? 600 : 480); $minute + $service['minutes'] <= 1200; $minute += 30) {
        $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
        $start = instant($date, $time);
        if ($start > milliseconds($now)) {
            $slots[] = ['time' => $time, 'start' => $start, 'end' => $start + $service['minutes'] * 60000];
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

function validateBookings(mixed $data): void
{
    if (!is_array($data) || ($data['version'] ?? null) !== 1
        || !isset($data['bookings']) || !is_array($data['bookings']) || !array_is_list($data['bookings'])) {
        throw new RuntimeException('Invalid booking JSON schema. Restore a verified backup.');
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
            throw new RuntimeException('Invalid booking record in JSON storage.');
        }
        try {
            $normalized = normalize([
                'name' => $booking['name'] ?? null, 'phone' => $booking['phone'] ?? null,
                'date' => $booking['date'] ?? null, 'time' => $booking['time'] ?? null,
                'service' => $booking['service'] ?? null, 'requestId' => $booking['request_id'] ?? null,
                'consent' => true, 'whatsappConsent' => $booking['whatsapp_consent'],
            ]);
        } catch (HttpError $error) {
            throw new RuntimeException('Invalid booking fields in JSON storage.', 0, $error);
        }
        if ($normalized['name'] !== $booking['name'] || $normalized['phone'] !== $booking['phone']
            || instant($booking['date'], $booking['time']) !== $booking['starts']
            || $booking['ends'] !== $booking['starts'] + serviceFor($booking['service'])['minutes'] * 60000
            || isset($ids[$booking['id']]) || isset($requests[$booking['request_id']])) {
            throw new RuntimeException('Inconsistent booking records in JSON storage.');
        }
        $ids[$booking['id']] = $requests[$booking['request_id']] = true;
        $specialist = bookingSpecialist($booking);
        if ($booking['status'] === 'confirmed') {
            $intervals[$specialist][] = [$booking['starts'], $booking['ends']];
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
    private JsonFile $file;

    public function __construct(string $path)
    {
        $this->file = new JsonFile($path, validateBookings(...));
    }

    public function initialize(): void
    {
        $this->file->initialize(['version' => 1, 'bookings' => []]);
    }

    public function import(array $bookings): void
    {
        $this->file->write(static function (array &$data) use ($bookings): void {
            if ($data['bookings'] !== []) {
                throw new RuntimeException('JSON storage is not empty. Import refused.');
            }
            $data['bookings'] = $bookings;
        });
    }

    private function available(array $data, mixed $date, mixed $service, DateTimeImmutable $now): array
    {
        $free = [];
        $details = serviceFor($service);
        foreach (schedule($date, $details, $now) as $slot) {
            foreach ($data['bookings'] as $booking) {
                if ($booking['status'] === 'confirmed'
                    && bookingSpecialist($booking) === $details['specialistId']
                    && $slot['start'] < $booking['ends'] && $slot['end'] > $booking['starts']) {
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

    public function reserve(mixed $input, bool $automated, ?DateTimeImmutable $now = null): array
    {
        $request = normalize($input);
        $now ??= new DateTimeImmutable();
        return $this->file->write(function (array &$data) use ($request, $automated, $now): array {
            foreach ($data['bookings'] as $previous) {
                if ($previous['request_id'] !== $request['requestId']) {
                    continue;
                }
                $same = $previous['whatsapp_consent'] === $request['whatsappConsent'];
                foreach (['name', 'phone', 'date', 'time', 'service'] as $key) {
                    $same = $same && $previous[$key] === $request[$key];
                }
                if (!$same || $previous['status'] !== 'confirmed') {
                    throw new HttpError(409, 'To zgłoszenie zostało już użyte. Odśwież formularz.');
                }
                return ['booking' => $previous, 'created' => false];
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
                'starts' => $start, 'ends' => $start + serviceFor($request['service'])['minutes'] * 60000,
                'name' => $request['name'], 'phone' => $request['phone'],
                'whatsapp_consent' => $request['whatsappConsent'], 'consent_at' => $stamp,
                'status' => 'confirmed', 'created_at' => $stamp,
                'notification' => $request['whatsappConsent'] ? ($automated ? 'pending' : 'unconfigured') : 'not_requested',
                'message_id' => null, 'notification_started_at' => null,
            ];
            $data['bookings'][] = $booking;
            return ['booking' => $booking, 'created' => true];
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
            return $bookings;
        });
    }

    public function cancel(string $id): array
    {
        return $this->file->write(static function (array &$data) use ($id): array {
            foreach ($data['bookings'] as &$booking) {
                if ($booking['id'] === $id) {
                    $booking['status'] = 'cancelled';
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
    $text = "Dzień dobry, mam rezerwację {$service['brand']}: {$service['name']}, {$booking['date']}, godz. {$booking['time']} (czas polski). Miejsce: {$service['location']}. Numer rezerwacji: {$booking['id']}.";
    return [
        'id' => $booking['id'], 'date' => $booking['date'], 'time' => $booking['time'],
        'service' => $service['name'], 'minutes' => $service['minutes'], 'status' => $booking['status'],
        'brand' => $service['brand'], 'specialist' => $service['specialist'],
        'location' => $service['location'],
        'specialistId' => bookingSpecialist($booking),
        'notification' => $booking['notification'],
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
                    [$service['brand'] . ' — ' . $service['name'] . ' (' . $service['location'] . ')',
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
