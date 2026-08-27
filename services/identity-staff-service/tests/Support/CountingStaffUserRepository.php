<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Identity\Email;
use App\Domain\Identity\IdentitySnapshot;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Domain\Identity\StaffUserRepository;

/**
 * Декоратор сховища, який рахує звернення до «бази».
 *
 * Потрібен, щоб довести вимогу продуктивності `/internal/v1/auth/verify`:
 * невалідний токен НЕ має спричиняти читання з MongoDB.
 */
final class CountingStaffUserRepository implements StaffUserRepository
{
    public int $identityReads = 0;

    public function __construct(private readonly StaffUserRepository $inner)
    {
    }

    public function findById(string $id): ?StaffUser
    {
        return $this->inner->findById($id);
    }

    public function findIdentityById(string $id): ?IdentitySnapshot
    {
        ++$this->identityReads;

        return $this->inner->findIdentityById($id);
    }

    public function findByEmail(Email $email): ?StaffUser
    {
        return $this->inner->findByEmail($email);
    }

    public function save(StaffUser $user): void
    {
        $this->inner->save($user);
    }

    public function countActiveByRole(Role $role): int
    {
        return $this->inner->countActiveByRole($role);
    }

    public function findByStoreScope(?array $storeIds): array
    {
        return $this->inner->findByStoreScope($storeIds);
    }
}
