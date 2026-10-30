<?php
declare(strict_types=1);

namespace Misseye;

use RuntimeException;

function defaultOffer(): array
{
    return [
        'revision' => 0,
        'prices' => array_fill_keys(array_column(services(), 'id'), ['amount' => null, 'mode' => 'fixed']),
        'week' => array_fill(0, 7, 'manager'),
        'addresses' => ['wrzesnia' => '', 'poznan' => ''],
    ];
}

function validateOffer(mixed $offer): void
{
    if (!is_array($offer) || count($offer) !== 4 || !is_int($offer['revision'] ?? null) || $offer['revision'] < 0
        || !is_array($offer['prices'] ?? null) || !is_array($offer['week'] ?? null)
        || !array_is_list($offer['week']) || count($offer['week']) !== 7
        || !is_array($offer['addresses'] ?? null) || count($offer['addresses']) !== 2) {
        throw new RuntimeException('Invalid offer settings.');
    }
    $ids = array_column(services(), 'id');
    if (count($offer['prices']) !== count($ids)) {
        throw new RuntimeException('Incomplete service prices.');
    }
    foreach ($ids as $id) {
        validatePrice($offer['prices'][$id] ?? null);
    }
    foreach ($offer['week'] as $location) {
        if (!in_array($location, ['manager', 'wrzesnia', 'poznan', 'closed'], true)) {
            throw new RuntimeException('Invalid weekly location.');
        }
    }
    foreach (['wrzesnia', 'poznan'] as $city) {
        $address = $offer['addresses'][$city] ?? null;
        if (!is_string($address) || strlen($address) > 400 || preg_match('/[\p{C}]/u', $address) !== 0) {
            throw new RuntimeException('Invalid business address.');
        }
    }
}

function validatePrice(mixed $price): void
{
    if (!is_array($price) || count($price) !== 2 || !array_key_exists('amount', $price)
        || ($price['amount'] !== null && (!is_int($price['amount']) || $price['amount'] < 0 || $price['amount'] > 10000000))
        || !in_array($price['mode'] ?? null, ['fixed', 'from'], true)) {
        throw new RuntimeException('Invalid service price.');
    }
}

function priceLabel(array $price): string
{
    return $price['amount'] === null ? 'Cena do ustalenia'
        : ($price['mode'] === 'from' ? 'od ' : '') . number_format($price['amount'] / 100, 2, ',', ' ') . ' zł';
}

function offerCatalog(array $data): array
{
    $offer = $data['offer'] ?? defaultOffer();
    return [
        'services' => array_map(static fn ($service) => [
            ...$service, 'price' => $offer['prices'][$service['id']],
            'priceLabel' => priceLabel($offer['prices'][$service['id']]),
        ], services()),
        'specialists' => specialists(), 'offer' => $offer,
    ];
}

function visitLocation(array $data, string $service, string $date): array
{
    $details = serviceFor($service);
    if ($details['specialistId'] !== 'pitstop-1') {
        return ['key' => 'misseye', 'label' => $details['location']];
    }
    $offer = $data['offer'] ?? defaultOffer();
    $key = $offer['week'][(int) parseDate($date)->format('N') - 1];
    $label = match ($key) {
        'wrzesnia' => 'Września',
        'poznan' => 'Poznań',
        'closed' => 'Brak przyjęć PitSTOP w tym dniu',
        default => $details['location'],
    };
    if (in_array($key, ['wrzesnia', 'poznan'], true) && $offer['addresses'][$key] !== '') {
        $label .= ' — ' . $offer['addresses'][$key];
    }
    return ['key' => $key, 'label' => $label];
}
