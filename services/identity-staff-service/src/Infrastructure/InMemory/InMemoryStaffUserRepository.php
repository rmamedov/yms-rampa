<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Identity\Email;
use App\Domain\Identity\IdentitySnapshot;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Domain\Identity\StaffUserCriteria;
use App\Domain\Identity\StaffUserPage;
use App\Domain\Identity\StaffUserRepository;

/**
 * Реалізація сховища користувачів у памʼяті.
 *
 * Використовується юніт-тестами (працюють БЕЗ MongoDB) і локальною
 * розробкою, доки MongoDB не піднято.
 */
final class InMemoryStaffUserRepository implements StaffUserRepository
{
    /** @var array<string, StaffUser> */
    private array $byId = [];

    /**
     * @param list<StaffUser> $users
     */
    public function __construct(array $users = [])
    {
        foreach ($users as $user) {
            $this->save($user);
        }
    }

    public function findById(string $id): ?StaffUser
    {
        return $this->byId[$id] ?? null;
    }

    public function findIdentityById(string $id): ?IdentitySnapshot
    {
        $user = $this->byId[$id] ?? null;

        return null === $user ? null : IdentitySnapshot::fromUser($user);
    }

    public function findByEmail(Email $email): ?StaffUser
    {
        foreach ($this->byId as $user) {
            if ($user->email()->equals($email)) {
                return $user;
            }
        }

        return null;
    }

    public function save(StaffUser $user): void
    {
        // Унікальний індекс {email:1} (10.5) — емуляція перевіркою при записі
        foreach ($this->byId as $id => $existing) {
            if ($id !== $user->id() && $existing->email()->equals($user->email())) {
                throw new \RuntimeException(\sprintf(
                    'Порушення унікального індексу {email:1}: "%s" уже зайнято.',
                    $user->email()->value,
                ));
            }
        }

        $this->byId[$user->id()] = $user;
    }

    public function countActiveByRole(Role $role): int
    {
        $count = 0;
        foreach ($this->byId as $user) {
            if ($user->role() === $role && $user->isActive()) {
                ++$count;
            }
        }

        return $count;
    }

    public function findByStoreScope(?array $storeIds): array
    {
        // RBAC-16: null — скоуп «вся мережа», фільтр не застосовується
        if (null === $storeIds) {
            return array_values($this->byId);
        }

        // RBAC-13: порожній масив — нуль доступу, гарантовано порожня вибірка
        if ([] === $storeIds) {
            return [];
        }

        return array_values(array_filter(
            $this->byId,
            static function (StaffUser $user) use ($storeIds): bool {
                foreach ($user->storeIds() as $storeId) {
                    if (\in_array($storeId, $storeIds, true)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    public function search(StaffUserCriteria $criteria): StaffUserPage
    {
        $matched = array_values(array_filter(
            $this->byId,
            static fn (StaffUser $user): bool => $criteria->matches($user),
        ));

        // Стабільний порядок: спершу активні, далі за email — інакше
        // сторінки «перемішувалися» б між запитами.
        usort($matched, static function (StaffUser $a, StaffUser $b): int {
            if ($a->isActive() !== $b->isActive()) {
                return $a->isActive() ? -1 : 1;
            }

            return strcmp($a->email()->value, $b->email()->value);
        });

        return new StaffUserPage(
            items: \array_slice($matched, $criteria->offset(), $criteria->perPage),
            total: \count($matched),
            page: $criteria->page,
            perPage: $criteria->perPage,
        );
    }

    /**
     * @return list<StaffUser>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    public function clear(): void
    {
        $this->byId = [];
    }
}
