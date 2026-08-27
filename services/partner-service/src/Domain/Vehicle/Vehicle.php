<?php

declare(strict_types=1);

namespace App\Domain\Vehicle;

use App\Domain\Shared\ValidationException;

/**
 * Авто з довідника постачальника (SUP-VEH-01, розділ 10.4 `vehicles`).
 *
 * Машина належить рівно одному постачальнику. Держномер унікальний
 * у межах постачальника, але НЕ глобально (SUP-VEH-02, DATA-18).
 *
 * DATA-34: вантажопідйомність валідується лише глобальним діапазоном
 * 0.5–40.0 т; ліміт конкретного магазину (`maxVehicleWeightTons`)
 * перевіряється booking-service у момент бронювання.
 */
final class Vehicle
{
    public const MIN_WEIGHT_TONS = 0.5;
    public const MAX_WEIGHT_TONS = 40.0;
    public const MAX_BRAND_LENGTH = 100;

    private string $plateNumber;
    private ?string $brand;
    private float $weightTons;
    private bool $active = true;
    private ?\DateTimeImmutable $lastUsedAt = null;
    private ?\DateTimeImmutable $archivedAt = null;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        private readonly string $id,
        private readonly string $supplierId,
        string $plateNumber,
        float $weightTons,
        ?string $brand,
        private readonly \DateTimeImmutable $createdAt,
        private readonly int $schemaVersion = 1,
    ) {
        $this->plateNumber = PlateNumberNormalizer::normalize($plateNumber);
        $this->weightTons = self::assertWeight($weightTons);
        $this->brand = self::normalizeBrand($brand);
        $this->updatedAt = $createdAt;
    }

    /**
     * Відновлення агрегату зі сховища (DATA-02).
     */
    public static function reconstitute(
        string $id,
        string $supplierId,
        string $plateNumber,
        float $weightTons,
        ?string $brand,
        bool $active,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $lastUsedAt,
        ?\DateTimeImmutable $archivedAt,
        int $schemaVersion = 1,
    ): self {
        $vehicle = new self($id, $supplierId, $plateNumber, $weightTons, $brand, $createdAt, $schemaVersion);
        $vehicle->active = $active;
        $vehicle->updatedAt = $updatedAt;
        $vehicle->lastUsedAt = $lastUsedAt;
        $vehicle->archivedAt = $archivedAt;

        return $vehicle;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function supplierId(): string
    {
        return $this->supplierId;
    }

    public function plateNumber(): string
    {
        return $this->plateNumber;
    }

    public function brand(): ?string
    {
        return $this->brand;
    }

    public function weightTons(): float
    {
        return $this->weightTons;
    }

    public function isActive(): bool
    {
        return $this->active && null === $this->archivedAt;
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function changePlateNumber(string $plateNumber, \DateTimeImmutable $now): void
    {
        $this->plateNumber = PlateNumberNormalizer::normalize($plateNumber);
        $this->updatedAt = $now;
    }

    /**
     * SUP-VEH-03: зміна вантажопідйомності не зачіпає вже створені бронювання —
     * там зберігається снапшот параметрів авто на момент бронювання (DATA-06).
     */
    public function changeWeight(float $weightTons, \DateTimeImmutable $now): void
    {
        $this->weightTons = self::assertWeight($weightTons);
        $this->updatedAt = $now;
    }

    public function changeBrand(?string $brand, \DateTimeImmutable $now): void
    {
        $this->brand = self::normalizeBrand($brand);
        $this->updatedAt = $now;
    }

    /**
     * SUP-VEH-04: деактивація замість видалення — авто зникає з випадаючого
     * списку, але історія бронювань залишається цілою.
     *
     * @return bool true, якщо стан справді змінився
     */
    public function deactivate(\DateTimeImmutable $now): bool
    {
        if (!$this->active) {
            return false;
        }

        $this->active = false;
        $this->updatedAt = $now;

        return true;
    }

    public function activate(\DateTimeImmutable $now): bool
    {
        if ($this->active) {
            return false;
        }

        $this->active = true;
        $this->updatedAt = $now;

        return true;
    }

    /**
     * DATA-03: видалення бізнес-сутностей — лише soft delete через `archivedAt`.
     */
    public function archive(\DateTimeImmutable $now): void
    {
        $this->archivedAt = $now;
        $this->active = false;
        $this->updatedAt = $now;
    }

    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
        $this->updatedAt = $now;
    }

    private static function assertWeight(float $weightTons): float
    {
        if ($weightTons < self::MIN_WEIGHT_TONS || $weightTons > self::MAX_WEIGHT_TONS) {
            throw new ValidationException(
                \sprintf(
                    'Вантажопідйомність має бути в межах %.1f–%.1f т, отримано %s т.',
                    self::MIN_WEIGHT_TONS,
                    self::MAX_WEIGHT_TONS,
                    rtrim(rtrim(\sprintf('%.2f', $weightTons), '0'), '.'),
                ),
                'VEHICLE_WEIGHT_OUT_OF_RANGE',
            );
        }

        // Крок 0.1 т (SUP-BOOK-03): округлюємо, щоб уникнути «хвостів» double.
        return round($weightTons, 1);
    }

    private static function normalizeBrand(?string $brand): ?string
    {
        if (null === $brand) {
            return null;
        }

        $trimmed = trim($brand);

        if ('' === $trimmed) {
            return null;
        }

        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_BRAND_LENGTH) {
            throw new ValidationException(
                \sprintf('Марка авто не може бути довшою за %d символів.', self::MAX_BRAND_LENGTH),
                'VEHICLE_BRAND_TOO_LONG',
            );
        }

        return $trimmed;
    }
}
