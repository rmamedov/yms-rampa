<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

use App\Domain\Shared\ValidationException;

/**
 * Розмір слоту (STC-20, DATA-10): лише 15, 20, 30 або 60 хвилин.
 * Інші значення API відхиляє з кодом 422 CONFIG_VALIDATION_FAILED.
 */
enum SlotSize: int
{
    case Quarter = 15;
    case Third = 20;
    case Half = 30;
    case Hour = 60;

    public static function fromMinutes(int $minutes): self
    {
        $size = self::tryFrom($minutes);

        if (!$size instanceof self) {
            throw ValidationException::config(
                \sprintf('Розмір слоту %d хв не підтримується; допустимі значення: 15, 20, 30, 60', $minutes),
                ['slotSizeMinutes' => 'Допустимі значення: 15, 20, 30, 60'],
            );
        }

        return $size;
    }

    /** @return list<int> */
    public static function values(): array
    {
        return array_map(static fn (self $c): int => $c->value, self::cases());
    }
}
