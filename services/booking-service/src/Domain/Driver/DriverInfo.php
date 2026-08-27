<?php

declare(strict_types=1);

namespace App\Domain\Driver;

/**
 * Знімок профілю водія (partner_users) для картки прибуття.
 *
 * DRV: ідентифікатор тут — саме ПРОФІЛЬ водія, той самий, що зберігає
 * `booking.driverId`, а не обліковий запис із клейма `sub`.
 */
final readonly class DriverInfo
{
    public function __construct(
        public string $driverId,
        public string $fullName,
        public ?string $phone = null,
        public ?string $supplierId = null,
        /** Деактивований профіль лишається в історичних бронюваннях. */
        public bool $active = true,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driverId' => $this->driverId,
            'fullName' => $this->fullName,
            'phone' => $this->phone,
            'active' => $this->active,
        ];
    }
}
