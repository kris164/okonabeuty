<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'booking.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$services = \Misseye\services();
$serviceIds = ['smp', 'brwi', 'smp-1', 'smp-2', 'smp-3', 'henna', 'pm-zabieg'];
check(array_column($services, 'id') === $serviceIds, 'MissEye service catalog is incomplete or contains unrelated services.');
check(array_column(\Misseye\specialists(), 'id') === ['misseye-1'], 'The fork must contain only the MissEye calendar.');
check(count(array_filter($services, static fn (array $service): bool => $service['specialistId'] !== 'misseye-1')) === 0,
    'Every service must belong to MissEye.');
check(array_column(array_filter($services, static fn (array $service): bool => $service['firstConsultationFree']), 'id') === ['smp', 'brwi'],
    'Only MissEye consultations are free.');

$offer = \Misseye\defaultOffer();
check(array_keys($offer['prices']) === $serviceIds, 'Offer prices must match the MissEye catalog.');
\Misseye\validateOffer($offer);
check(array_keys($offer) === ['revision', 'prices'], 'The MissEye offer must not include unrelated weekly location settings.');
check(\Misseye\visitLocation('smp')['label'] === \Misseye\SERVICE_AREA,
    'MissEye appointments must keep their manager-confirmed location.');

try {
    \Misseye\serviceFor('nails');
    throw new RuntimeException('An unrelated service was accepted.');
} catch (\Misseye\HttpError $error) {
    check($error->status === 400, 'Unknown services must be rejected as invalid input.');
}

echo "OK: isolated MissEye catalog and offer.\n";
