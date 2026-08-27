<?php

declare(strict_types=1);

namespace App\Domain\Projection;

/**
 * Результат обробки однієї події проєктором.
 */
enum ProjectionOutcome: string
{
    /** Подію застосовано до факту. */
    case Applied = 'applied';
    /** Повторна доставка вже застосованої події — факт не змінено (ідемпотентність). */
    case Duplicate = 'duplicate';
    /** Подія не стосується read-моделі бронювань. */
    case Ignored = 'ignored';
    /** Подія бронювання, для якого ще не отримано BookingCreated. */
    case Orphan = 'orphan';
}
