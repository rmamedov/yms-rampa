<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\UserManagement\RoleAuditAction;
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

    public function recent(
        int $limit,
        int $offset = 0,
        ?string $targetUserId = null,
        ?RoleAuditAction $action = null,
    ): array {
        return array_values(\array_slice(
            $this->sorted($targetUserId, $action),
            max(0, $offset),
            max(1, $limit),
        ));
    }

    public function count(?string $targetUserId = null, ?RoleAuditAction $action = null): int
    {
        return \count($this->sorted($targetUserId, $action));
    }

    /**
     * Від новіших до старіших. Кілька дій в один запит мають однакову
     * позначку часу, тому за рівних timestamp порядок задає послідовність
     * запису — новіший запис іде першим (сортування в PHP стабільне).
     *
     * @return list<RoleAuditEntry>
     */
    private function sorted(?string $targetUserId, ?RoleAuditAction $action): array
    {
        $found = array_values(array_filter(
            array_reverse($this->entries),
            static fn (RoleAuditEntry $entry): bool => (null === $targetUserId || $entry->targetUserId === $targetUserId)
                && (null === $action || $entry->action === $action),
        ));

        usort($found, static fn (RoleAuditEntry $a, RoleAuditEntry $b): int => $b->timestamp <=> $a->timestamp);

        return $found;
    }
}
