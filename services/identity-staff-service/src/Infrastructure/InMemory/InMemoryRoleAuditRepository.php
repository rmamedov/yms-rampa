<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\UserManagement\RoleAuditEntry;
use App\Domain\UserManagement\RoleAuditRepository;

/**
 * Журнал аудиту змін ролей у памʼяті (RBAC-29).
 *
 * RBAC-30: записи немодифіковні — інтерфейс не має методів update/delete.
 */
final class InMemoryRoleAuditRepository implements RoleAuditRepository
{
    /** @var list<RoleAuditEntry> */
    private array $entries = [];

    public function append(RoleAuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function findByTarget(string $targetUserId): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (RoleAuditEntry $entry): bool => $entry->targetUserId === $targetUserId,
        ));
    }

    public function all(): array
    {
        return $this->entries;
    }
}
