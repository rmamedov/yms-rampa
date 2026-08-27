<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Прапорець затримки з причиною та ETA (DLY-01).
 *
 * Бронювання з flag=true і ETA у майбутньому виключається з авто-no_show
 * до `ETA + noShowGraceMinutes` (NOSH-01).
 */
final readonly class DelayInfo
{
    public ?DateTimeImmutable $eta;

    public function __construct(
        public bool $flag = false,
        public ?string $reason = null,
        ?DateTimeImmutable $eta = null,
    ) {
        $this->eta = $eta?->setTimezone(new DateTimeZone('UTC'));
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'flag' => $this->flag,
            'reason' => $this->reason,
            'eta' => $this->eta?->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
