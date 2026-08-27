<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

/**
 * Сховище разових блокувань слотів (10.2.3).
 */
interface SlotBlockRepository
{
    public function save(SlotBlock $block): void;

    public function find(string $id): ?SlotBlock;

    /** @return list<SlotBlock> */
    public function findForStore(string $storeId, ?bool $activeOnly = null): array;

    /**
     * Блокування, що перетинаються з діапазоном видачі слотів.
     *
     * @return list<SlotBlock>
     */
    public function findOverlapping(string $storeId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    public function delete(string $id): void;
}
