<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Обставини скасування бронювання (розділ 10.3.1).
 */
final readonly class Cancellation
{
    public function __construct(
        public CancelledBy $by,
        public string $userId,
        public ?string $reason = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'by' => $this->by->value,
            'userId' => $this->userId,
            'reason' => $this->reason,
        ];
    }
}
