<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Сутність не знайдена → HTTP 404.
 */
final class NotFoundException extends DomainException
{
    public function httpStatus(): int
    {
        return 404;
    }

    public function title(): string
    {
        return 'Обʼєкт не знайдено';
    }

    public static function store(string $storeId): self
    {
        return new self(\sprintf('Магазин %s не знайдено', $storeId), 'STORE_NOT_FOUND');
    }

    public static function configuration(string $id): self
    {
        return new self(\sprintf('Конфігурацію %s не знайдено', $id), 'CONFIG_NOT_FOUND');
    }

    /**
     * Магазин існує, але сітки слотів для нього існувати не повинно: неактивний
     * ymsStatus або відсутня/неповна чинна конфігурація. Для booking-service це
     * така сама відсутність магазину, як і STORE_NOT_FOUND, — 404, а не порожня сітка.
     */
    public static function storeNotConfigured(string $storeId, string $reason): self
    {
        return new self(
            \sprintf('Магазин %s не має чинної конфігурації для бронювання: %s', $storeId, $reason),
            'STORE_NOT_CONFIGURED',
        );
    }

    public static function reservedSlotRule(string $id): self
    {
        return new self(\sprintf('Правило резерву %s не знайдено', $id), 'RESERVED_RULE_NOT_FOUND');
    }

    public static function slotBlock(string $id): self
    {
        return new self(\sprintf('Блокування %s не знайдено', $id), 'SLOT_BLOCK_NOT_FOUND');
    }
}
