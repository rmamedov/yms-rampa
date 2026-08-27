<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Identity\Email;
use App\Domain\Identity\IdentitySnapshot;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Domain\Identity\StaffUserCriteria;
use App\Domain\Identity\StaffUserPage;
use App\Domain\Identity\StaffUserRepository;

/**
 * Колекція `staff_users` БД `identity_staff` (10.5).
 *
 * Поля: _id (UUID), email (unique, lowercase), passwordHash (argon2id),
 * role (рівно одна роль), storeIds (array), active, lastLoginAt,
 * плюс наскрізні поля 10.1 (schemaVersion, createdAt, updatedAt, archivedAt).
 */
final readonly class MongoStaffUserRepository implements StaffUserRepository
{
    private const string COLLECTION = 'staff_users';

    public function __construct(private MongoConnection $connection)
    {
    }

    public function findById(string $id): ?StaffUser
    {
        $document = $this->connection->findOne(self::COLLECTION, ['_id' => $id]);

        return null === $document ? null : self::hydrate($document);
    }

    /**
     * Гарячий шлях api-gateway: виклик на КОЖЕН запит до API.
     *
     * Пошук за `_id` — первинний індекс колекції, який MongoDB створює
     * автоматично, тому додаткового індексу не потребує (10.5). Проєкція
     * обмежує документ чотирма полями: `passwordHash` і `passwordHistory`
     * (пʼять argon2id-хешів) по мережі не передаються (AUTH-61).
     */
    public function findIdentityById(string $id): ?IdentitySnapshot
    {
        $document = $this->connection->findOne(
            self::COLLECTION,
            ['_id' => $id],
            ['projection' => ['role' => 1, 'storeIds' => 1, 'active' => 1, 'archivedAt' => 1]],
        );

        if (null === $document) {
            return null;
        }

        return new IdentitySnapshot(
            userId: (string) $document['_id'],
            role: Role::from((string) $document['role']),
            storeIds: array_values(array_map(strval(...), (array) ($document['storeIds'] ?? []))),
            // DATA-03: архівований запис так само неактивний
            active: (bool) ($document['active'] ?? true)
                && null === MongoConnection::toDateTimeImmutable($document['archivedAt'] ?? null),
        );
    }

    public function findByEmail(Email $email): ?StaffUser
    {
        $document = $this->connection->findOne(self::COLLECTION, ['email' => $email->value]);

        return null === $document ? null : self::hydrate($document);
    }

    public function save(StaffUser $user): void
    {
        $this->connection->upsert(self::COLLECTION, ['_id' => $user->id()], self::toDocument($user));
    }

    public function countActiveByRole(Role $role): int
    {
        return $this->connection->count(self::COLLECTION, [
            'role' => $role->value,
            'active' => true,
            'archivedAt' => null,
        ]);
    }

    public function findByStoreScope(?array $storeIds): array
    {
        // RBAC-17: скоуп-фільтр — обовʼязковий предикат ЗАПИТУ, а не пост-фільтрація в памʼяті.
        if (null === $storeIds) {
            $filter = [];
        } elseif ([] === $storeIds) {
            // RBAC-13: порожній масив = нуль доступу; предикат гарантує порожню вибірку
            $filter = ['storeIds' => ['$in' => []]];
        } else {
            $filter = ['storeIds' => ['$in' => array_values($storeIds)]];
        }

        return array_map(self::hydrate(...), $this->connection->find(self::COLLECTION, $filter));
    }

    /**
     * Список розділу «Користувачі» (4.7).
     *
     * Фільтри йдуть предикатом запиту, пагінація — skip/limit драйвера:
     * вибирати всю колекцію в памʼять і різати її там не можна навіть на
     * кількох сотнях акаунтів.
     */
    public function search(StaffUserCriteria $criteria): StaffUserPage
    {
        $filter = self::filterOf($criteria);

        return new StaffUserPage(
            items: array_map(self::hydrate(...), $this->connection->find(
                self::COLLECTION,
                $filter,
                [
                    // Той самий порядок, що й у InMemory-реалізації:
                    // активні першими, далі за email.
                    'sort' => ['active' => -1, 'email' => 1],
                    'skip' => $criteria->offset(),
                    'limit' => $criteria->perPage,
                ],
            )),
            total: $this->connection->count(self::COLLECTION, $filter),
            page: $criteria->page,
            perPage: $criteria->perPage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function filterOf(StaffUserCriteria $criteria): array
    {
        // RBAC-23: порожній перелік ролей дає `$in: []`, тобто гарантовано
        // порожню вибірку, а не відсутність фільтра.
        $filter = [
            'role' => ['$in' => array_map(
                static fn (Role $role): string => $role->value,
                $criteria->effectiveRoles(),
            )],
        ];

        if (null !== $criteria->active) {
            // DATA-03: архівований запис так само неактивний.
            $filter['active'] = $criteria->active;

            if ($criteria->active) {
                $filter['archivedAt'] = null;
            }
        }

        $query = trim((string) $criteria->query);

        if ('' !== $query) {
            $escaped = preg_quote($query, '/');
            $filter['$or'] = [
                ['email' => ['$regex' => $escaped, '$options' => 'i']],
                ['fullName' => ['$regex' => $escaped, '$options' => 'i']],
            ];
        }

        return $filter;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toDocument(StaffUser $user): array
    {
        return [
            '_id' => $user->id(),
            'email' => $user->email()->value,
            'fullName' => $user->fullName(),
            'passwordHash' => $user->passwordHash(),
            // RBAC-04: рядок, а не масив
            'role' => $user->role()->value,
            'storeIds' => $user->storeIds(),
            'active' => $user->isActive(),
            'twoFactorEnabled' => $user->isTwoFactorEnabled(),
            'totpSecret' => $user->totpSecret(),
            'passwordHistory' => $user->passwordHistory(),
            'lastLoginAt' => null === $user->lastLoginAt()
                ? null
                : MongoConnection::toUtcDateTime($user->lastLoginAt()),
            'createdAt' => MongoConnection::toUtcDateTime($user->createdAt()),
            'updatedAt' => MongoConnection::toUtcDateTime($user->updatedAt()),
            'archivedAt' => null === $user->archivedAt()
                ? null
                : MongoConnection::toUtcDateTime($user->archivedAt()),
            'schemaVersion' => $user->schemaVersion(),
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function hydrate(array $document): StaffUser
    {
        $role = Role::from((string) $document['role']);

        return StaffUser::restore(
            id: (string) $document['_id'],
            email: Email::fromString((string) $document['email']),
            passwordHash: (string) $document['passwordHash'],
            role: $role,
            storeIds: array_values(array_map(strval(...), (array) ($document['storeIds'] ?? []))),
            active: (bool) ($document['active'] ?? true),
            twoFactorEnabled: (bool) ($document['twoFactorEnabled'] ?? false),
            totpSecret: isset($document['totpSecret']) ? (string) $document['totpSecret'] : null,
            lastLoginAt: MongoConnection::toDateTimeImmutable($document['lastLoginAt'] ?? null),
            createdAt: MongoConnection::toDateTimeImmutable($document['createdAt'] ?? null)
                ?? new \DateTimeImmutable('@0'),
            updatedAt: MongoConnection::toDateTimeImmutable($document['updatedAt'] ?? null)
                ?? new \DateTimeImmutable('@0'),
            passwordHistory: array_values(array_map(strval(...), (array) ($document['passwordHistory'] ?? []))),
            archivedAt: MongoConnection::toDateTimeImmutable($document['archivedAt'] ?? null),
            fullName: (string) ($document['fullName'] ?? ''),
            schemaVersion: (int) ($document['schemaVersion'] ?? StaffUser::SCHEMA_VERSION),
        );
    }
}
