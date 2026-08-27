<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Identity\Role;
use App\Domain\UserManagement\RoleAuditAction;
use App\Domain\UserManagement\RoleAuditEntry;
use App\Domain\UserManagement\RoleAuditRepository;

/**
 * Колекція `role_audit` (RBAC-29).
 *
 * RBAC-30: лише вставка — операції update/delete над колекцією заборонені
 * на рівні прав БД-користувача застосунку.
 */
final readonly class MongoRoleAuditRepository implements RoleAuditRepository
{
    private const string COLLECTION = 'role_audit';

    public function __construct(private MongoConnection $connection)
    {
    }

    public function append(RoleAuditEntry $entry): void
    {
        $this->connection->insert(self::COLLECTION, [
            'actorUserId' => $entry->actorUserId,
            'actorRole' => $entry->actorRole->value,
            'targetUserId' => $entry->targetUserId,
            'action' => $entry->action->value,
            'before' => $entry->before,
            'after' => $entry->after,
            'timestamp' => MongoConnection::toUtcDateTime($entry->timestamp),
            'requestId' => $entry->requestId,
            'ip' => $entry->ip,
            'schemaVersion' => 1,
        ]);
    }

    public function findByTarget(string $targetUserId): array
    {
        return $this->map($this->connection->find(
            self::COLLECTION,
            ['targetUserId' => $targetUserId],
            ['sort' => ['timestamp' => -1]],
        ));
    }

    public function all(): array
    {
        return $this->map($this->connection->find(self::COLLECTION, [], ['sort' => ['timestamp' => -1]]));
    }

    /**
     * @param list<array<string, mixed>> $documents
     *
     * @return list<RoleAuditEntry>
     */
    private function map(array $documents): array
    {
        return array_map(
            static fn (array $document): RoleAuditEntry => new RoleAuditEntry(
                actorUserId: (string) $document['actorUserId'],
                actorRole: Role::from((string) $document['actorRole']),
                targetUserId: (string) $document['targetUserId'],
                action: RoleAuditAction::from((string) $document['action']),
                before: (array) ($document['before'] ?? []),
                after: (array) ($document['after'] ?? []),
                timestamp: MongoConnection::toDateTimeImmutable($document['timestamp'] ?? null)
                    ?? new \DateTimeImmutable('@0'),
                requestId: isset($document['requestId']) ? (string) $document['requestId'] : null,
                ip: isset($document['ip']) ? (string) $document['ip'] : null,
            ),
            $documents,
        );
    }
}
