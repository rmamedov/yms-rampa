<?php

declare(strict_types=1);

namespace App\Domain\UserManagement;

/**
 * Журнал аудиту змін ролей (RBAC-29, RBAC-31).
 */
interface RoleAuditRepository
{
    public function append(RoleAuditEntry $entry): void;

    /**
     * @return list<RoleAuditEntry>
     */
    public function findByTarget(string $targetUserId): array;

    /**
     * @return list<RoleAuditEntry>
     */
    public function all(): array;
}
