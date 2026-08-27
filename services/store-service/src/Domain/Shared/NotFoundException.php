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

    public static function reservedSlotRule(string $id): self
    {
        return new self(\sprintf('Правило резерву %s не знайдено', $id), 'RESERVED_RULE_NOT_FOUND');
    }

    public static function slotBlock(string $id): self
    {
        return new self(\sprintf('Блокування %s не знайдено', $id), 'SLOT_BLOCK_NOT_FOUND');
    }
}
