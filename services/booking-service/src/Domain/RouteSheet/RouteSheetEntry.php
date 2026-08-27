<?php

declare(strict_types=1);

namespace App\Domain\RouteSheet;

/**
 * Точка маршрутного листа (розділ 10.3.2). `driverId=null` означає, що водія
 * на це бронювання ще не призначено — у driver-web воно не видиме (RSHT-02).
 */
final readonly class RouteSheetEntry
{
    public function __construct(
        public string $bookingId,
        public ?string $driverId,
        public int $sortOrder,
    ) {
    }

    public function withDriver(?string $driverId): self
    {
        return new self($this->bookingId, $driverId, $this->sortOrder);
    }

    public function withSortOrder(int $sortOrder): self
    {
        return new self($this->bookingId, $this->driverId, $sortOrder);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bookingId' => $this->bookingId,
            'driverId' => $this->driverId,
            'sortOrder' => $this->sortOrder,
        ];
    }
}
