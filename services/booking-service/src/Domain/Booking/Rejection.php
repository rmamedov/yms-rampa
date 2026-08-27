<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Факт відмови в прийомі (DATA-32): документ зі status=rejected без
 * заповненого rejectedAt вважається невалідним.
 */
final readonly class Rejection
{
    public DateTimeImmutable $at;

    public function __construct(
        DateTimeImmutable $at,
        public string $by,
        public RejectionReason $reason,
        public ?string $comment = null,
    ) {
        $this->at = $at->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'at' => $this->at->format('Y-m-d\TH:i:s\Z'),
            'by' => $this->by,
            'reason' => $this->reason->value,
            'comment' => $this->comment,
        ];
    }
}
