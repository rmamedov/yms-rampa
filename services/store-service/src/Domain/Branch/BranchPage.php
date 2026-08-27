<?php

declare(strict_types=1);

namespace App\Domain\Branch;

/**
 * Сторінка результатів серверної пагінації (UI-01, STL-05).
 */
final readonly class BranchPage
{
    /**
     * @param list<Branch> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    public function pages(): int
    {
        if ($this->perPage < 1) {
            return 0;
        }

        return (int) ceil($this->total / $this->perPage);
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }
}
