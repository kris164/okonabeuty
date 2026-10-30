<?php
declare(strict_types=1);

namespace Misseye;

use DateTimeImmutable;
use RuntimeException;

const EMAIL_STATES = ['not_requested', 'pending', 'sending', 'accepted', 'failed', 'unknown'];

function emailConfig(mixed $settings): ?array
{
    if ($settings === null) {
        return null;
    }
    if (!is_array($settings) || !is_bool($settings['enabled'] ?? null)) {
        throw new RuntimeException('Invalid email configuration.');
    }
    if (!$settings['enabled']) {
        return null;
    }
    foreach (['from', 'reply_to'] as $key) {
        if (!is_string($settings[$key] ?? null) || strlen($settings[$key]) > 254
            || !filter_var($settings[$key], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Configure valid sender and reply-to email addresses.');
        }
    }
    return $settings;
}

function reminderMessage(array $config, array $booking): array
{
    $service = serviceFor($booking['service']);
    $location = bookingLocation($booking);
    $subject = '=?UTF-8?B?' . base64_encode('Przypomnienie o wizycie — ' . $service['brand']) . '?=';
    $body = "Przypominamy o wizycie {$service['brand']}.\n"
        . "Termin: {$booking['date']}, {$booking['time']} (Europe/Warsaw).\n"
        . "Miejsce: {$location}.\n"
        . "Jeśli miejsce jest ustalane z managerem, potwierdź je przed przyjazdem.\n"
        . "Zmiana terminu, odwołanie lub wycofanie zgody: https://wa.me/" . WHATSAPP_NUMBER . "\n"
        . "To przypomnienie organizacyjne, nie wiadomość reklamowa.\n";
    return [
        'to' => $booking['email'], 'subject' => $subject, 'body' => $body,
        'headers' => [
            'From' => $config['from'], 'Reply-To' => $config['reply_to'],
            'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
        ],
    ];
}

function sendReminderMail(array $config, array $booking): bool
{
    $message = reminderMessage($config, $booking);
    return mail($message['to'], $message['subject'], $message['body'], $message['headers']);
}

function sendDueReminders(BookingStore $store, array $config, ?callable $sender = null, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable();
    $report = ['accepted' => 0, 'failed' => 0, 'unknown' => 0];
    $report['unknown'] = $store->recoverEmailReminders($now);
    foreach ($store->dueEmailReminders($now) as $candidate) {
        $booking = $store->claimEmailReminder($candidate['id'], $candidate['starts'], $now);
        if ($booking === null) {
            continue;
        }
        try {
            $accepted = ($sender ?? sendReminderMail(...))($config, $booking);
            if (!is_bool($accepted)) {
                throw new RuntimeException('Invalid mail transport response.');
            }
            $outcome = $accepted ? 'accepted' : 'failed';
            if (!$accepted) {
                error_log('Email reminder rejected by the mail transport. Contact the client manually.');
            }
        } catch (\Throwable $error) {
            $outcome = 'unknown';
            error_log('Email reminder outcome unknown (' . get_class($error) . '). Verify manually; no automatic retry.');
        }
        $store->finishEmailReminder($booking['id'], $booking['starts'], $outcome);
        $report[$outcome]++;
    }
    return $report;
}
