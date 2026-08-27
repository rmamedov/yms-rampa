<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Сторінка результатів серверної пагінації списку користувачів (UI-01).
 *
 * Форма навмисно збігається з BranchPage store-service: admin-web розбирає
 * усі адмінські списки одним мапером `toPage`.
 */
final readonly class StaffUserPage
{
    /**
     * @param list<StaffUser> $items
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
