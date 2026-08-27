<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Запис журналу статусів (DATA-14): масив statusHistory тільки append-only,
 * будь-яка зміна status без відповідного запису вважається дефектом.
 */
final readonly class StatusChange
{
    public DateTimeImmutable $at;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public ?BookingStatus $from,
        public BookingStatus $to,
        DateTimeImmutable $at,
        public string $by,
        public array $meta = [],
    ) {
        $this->at = $at->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'from' => $this->from?->value,
            'to' => $this->to->value,
            'at' => $this->at->format('Y-m-d\TH:i:s\Z'),
            'by' => $this->by,
        ];

        if ([] !== $this->meta) {
            $payload['meta'] = $this->meta;
        }

        return $payload;
    }
}
