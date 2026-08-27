<?php

declare(strict_types=1);

namespace App\Domain\UserManagement;

use App\Domain\Identity\Role;

/**
 * Немодифіковний запис журналу змін ролей — колекція `role_audit` (RBAC-29).
 *
 * RBAC-30: записи зберігаються щонайменше 3 роки; update/delete заборонені
 * на рівні прав БД-користувача застосунку.
 */
final readonly class RoleAuditEntry
{
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function __construct(
        public string $actorUserId,
        public Role $actorRole,
        public string $targetUserId,
        public RoleAuditAction $action,
        public array $before,
        public array $after,
        public \DateTimeImmutable $timestamp,
        public ?string $requestId = null,
        public ?string $ip = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'actorUserId' => $this->actorUserId,
            'actorRole' => $this->actorRole->value,
            'targetUserId' => $this->targetUserId,
            'action' => $this->action->value,
            'before' => $this->before,
            'after' => $this->after,
            // DATA-01: UTC, ISO 8601
            'timestamp' => $this->timestamp->format(\DATE_ATOM),
            'requestId' => $this->requestId,
            'ip' => $this->ip,
        ];
    }
}
