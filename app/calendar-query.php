<?php
declare(strict_types=1);

namespace Misseye;

use InvalidArgumentException;

final readonly class CalendarQuery
{
    private function __construct(
        public string $kind,
        public ?int $from = null,
        public ?int $until = null,
        public ?string $specialistId = null,
        public ?string $id = null,
        public ?string $requestId = null,
        public bool $activeOnly = true,
        public ?string $channel = null,
        public ?string $state = null,
    ) {
        if ($from !== null && $until !== null && $from >= $until) {
            throw new InvalidArgumentException('Calendar query requires a positive interval.');
        }
    }

    public static function catalog(): self
    {
        return new self('catalog');
    }

    public static function range(int $from, int $until, ?string $specialistId = null, bool $activeOnly = true): self
    {
        return new self('range', $from, $until, $specialistId, activeOnly: $activeOnly);
    }

    public static function day(mixed $date, mixed $service): self
    {
        $day = parseDate($date);
        return self::range(milliseconds($day), milliseconds($day->modify('+1 day')), serviceFor($service)['specialistId']);
    }

    public static function reservation(array $request): self
    {
        $day = self::day($request['date'], $request['service']);
        return new self('reservation', $day->from, $day->until, $day->specialistId, requestId: $request['requestId']);
    }

    public static function booking(string $id, ?string $date = null): self
    {
        $day = $date === null ? null : parseDate($date);
        return new self('booking', $day === null ? null : milliseconds($day),
            $day === null ? null : milliseconds($day->modify('+1 day')), id: $id);
    }

    public static function block(string $id, ?array $block = null): self
    {
        return new self('block', $block['starts'] ?? null, $block['ends'] ?? null, $block['specialist_id'] ?? null, $id);
    }

    public static function offer(int $now): self
    {
        return new self('offer', from: $now, specialistId: 'pitstop-1');
    }

    public static function notifications(string $channel, string $state, ?int $from = null, ?int $until = null, bool $activeOnly = true): self
    {
        if (!in_array($channel, ['email', 'whatsapp'], true) || !in_array($state, ['pending', 'sending'], true)) {
            throw new InvalidArgumentException('Unsupported notification query.');
        }
        if ($state === 'sending' && $until === null) {
            throw new InvalidArgumentException('Recovery queries require an explicit timeout cutoff.');
        }
        return new self('notifications', $from, $until, activeOnly: $activeOnly, channel: $channel, state: $state);
    }
}
