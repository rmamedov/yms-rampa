<?php

declare(strict_types=1);

namespace App\Application\Outbox;

/**
 * Що сусід зробив з доставленим пакетом подій.
 *
 * Лічильники потрібні не для звітності, а щоб мовчазна деградація була видима:
 * пакет може доїхати цілком (HTTP 200), але всі події виявитися сиротами —
 * і без цих чисел така аварія виглядала б як успішний прогін релея.
 */
final readonly class SinkReport
{
    /**
     * @param list<array{eventId: string|null, reason: string}> $failed події, які сусід
     *                                                                  не зміг розібрати
     */
    public function __construct(
        public int $applied = 0,
        public int $duplicate = 0,
        public int $ignored = 0,
        public int $orphan = 0,
        public array $failed = [],
    ) {
    }

    public function plus(self $other): self
    {
        return new self(
            applied: $this->applied + $other->applied,
            duplicate: $this->duplicate + $other->duplicate,
            ignored: $this->ignored + $other->ignored,
            orphan: $this->orphan + $other->orphan,
            failed: array_merge($this->failed, $other->failed),
        );
    }

    /** Ознака, що пакет доїхав, але дані до read-моделей не дійшли. */
    public function hasProblems(): bool
    {
        return $this->orphan > 0 || [] !== $this->failed;
    }
}
