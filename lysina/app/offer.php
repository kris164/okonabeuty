<?php
declare(strict_types=1);

namespace Misseye;

use RuntimeException;

function defaultOffer(): array
{
    return [
        'revision' => 0,
        'prices' => array_fill_keys(array_column(services(), 'id'), ['amount' => null, 'mode' => 'fixed']),
    ];
}

function validateOffer(mixed $offer): void
{
    if (!is_array($offer) || count($offer) !== 2 || !is_int($offer['revision'] ?? null) || $offer['revision'] < 0
        || !is_array($offer['prices'] ?? null)) {
        throw new RuntimeException('Invalid offer settings.');
    }
    $ids = array_column(services(), 'id');
    if (count($offer['prices']) !== count($ids)) {
        throw new RuntimeException('Incomplete service prices.');
    }
    foreach ($ids as $id) {
        validatePrice($offer['prices'][$id] ?? null);
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

function visitLocation(string $service): array
{
    $details = serviceFor($service);
    return ['key' => 'misseye', 'label' => $details['location']];
}
