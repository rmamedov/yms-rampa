<?php

declare(strict_types=1);

namespace App\Domain\Store;

use InvalidArgumentException;

/**
 * Налаштовувані параметри движка бронювань (зведення 6.11), які не входять
 * у геометрію сітки: дедлайни, грейс no-show, холди, анти-сквотинг.
 *
 * Джерело — store-service (рівень магазину) і адмін-панель (рівень мережі);
 * тут це незмінний знімок на час обробки запиту.
 */
final readonly class StorePolicy
{
    public function __construct(
        /** EDIT-02, рівень магазину, дефолт 2 год. */
        public int $editDeadlineHours = 2,
        /** NOSH-01, рівень магазину, дефолт 30 хв. */
        public int $noShowGraceMinutes = 30,
        /** HOLD-01, рівень мережі, дефолт 5 хв. */
        public int $holdTtlSeconds = 300,
        /** HOLD-02, рівень мережі, дефолт 15 хв. */
        public int $holdMaxMinutes = 15,
        /** BOOK-09, рівень мережі, дефолт 50. */
        public int $maxActiveBookingsPerSupplier = 50,
    ) {
        if ($editDeadlineHours < 0) {
            throw new InvalidArgumentException('editDeadlineHours не може бути відʼємним');
        }

        if ($noShowGraceMinutes < 0) {
            throw new InvalidArgumentException('noShowGraceMinutes не може бути відʼємним');
        }

        if ($holdTtlSeconds < 1) {
            throw new InvalidArgumentException('holdTtlSeconds має бути додатним');
        }

        if ($holdMaxMinutes < 1) {
            throw new InvalidArgumentException('holdMaxMinutes має бути додатним');
        }

        if ($maxActiveBookingsPerSupplier < 1) {
            throw new InvalidArgumentException('maxActiveBookingsPerSupplier має бути додатним');
        }
    }
}
