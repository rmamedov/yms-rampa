<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Account\PartnerAccount;
use App\Domain\Account\PartnerAccountRepository;
use App\Domain\Account\PartnerRole;
use App\Domain\Exception\LoginAlreadyTakenException;

/**
 * Колекція `identity_partner.partner_accounts` (10.6, DATA-35).
 *
 * Індекси, які має створити `app:mongo:init-indexes`:
 *  - unique `{login:1}`     — телефон/email унікальні в межах усього контуру;
 *  - `{supplierId:1}`       — масові операції блокування постачальника (AUTH-28);
 *  - `{_id:1, active:1}`    — покриття перевірки активності на кожен запит
 *                             шлюзу (`GET /internal/v1/auth/verify`).
 */
final class MongoPartnerAccountRepository extends MongoSupport implements PartnerAccountRepository
{
    public function findById(string $id): ?PartnerAccount
    {
        $document = $this->findOne(['_id' => $id]);

        return null === $document ? null : self::hydrate($document);
    }

    public function findByLogin(string $login): ?PartnerAccount
    {
        $document = $this->findOne(['login' => $login]);

        return null === $document ? null : self::hydrate($document);
    }

    public function findBySupplierId(string $supplierId): array
    {
        return array_map(
            static fn (array $document): PartnerAccount => self::hydrate($document),
            $this->find(['supplierId' => $supplierId], ['sort' => ['login' => 1]]),
        );
    }

    /**
     * Перевірка активності для `GET /internal/v1/auth/verify` — найгарячіший
     * запит контуру (виконується на кожен виклик API).
     *
     * Проєкція `{active:1}` без `_id` робить запит покритим індексом
     * `{_id:1, active:1}`: документ із диска не піднімається взагалі, і
     * passwordHash не їде мережею.
     */
    public function isActive(string $id): ?bool
    {
        $document = $this->findOne(
            ['_id' => $id],
            ['projection' => ['_id' => 0, 'active' => 1]],
        );

        return null === $document ? null : (bool) ($document['active'] ?? true);
    }

    public function save(PartnerAccount $account): void
    {
        try {
            $this->upsert(['_id' => $account->id], ['$set' => self::dehydrate($account)]);
        } catch (\MongoDB\Driver\Exception\BulkWriteException $exception) {
            // 11000 — duplicate key на unique `{login:1}`.
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw new LoginAlreadyTakenException($account->login());
            }

            throw $exception;
        }
    }

    protected function collection(): string
    {
        return 'partner_accounts';
    }

    /** @return array<string, mixed> */
    private static function dehydrate(PartnerAccount $account): array
    {
        return [
            'login' => $account->login(),
            'passwordHash' => $account->passwordHash(),
            'role' => $account->role->value,
            'supplierId' => $account->supplierId,
            'driverProfileId' => $account->driverProfileId(),
            'active' => $account->isActive(),
            'mustChangePassword' => $account->mustChangePassword(),
            'lastLoginAt' => self::toBson($account->lastLoginAt()),
            'createdAt' => self::toBson($account->createdAt),
            'updatedAt' => self::toBson($account->updatedAt()),
            'archivedAt' => null,
            'schemaVersion' => $account->schemaVersion,
        ];
    }

    /** @param array<string, mixed> $document */
    private static function hydrate(array $document): PartnerAccount
    {
        $role = PartnerRole::tryFrom((string) ($document['role'] ?? ''));

        if (null === $role) {
            throw new \RuntimeException(\sprintf('Невідома роль «%s» у partner_accounts.', (string) ($document['role'] ?? '')));
        }

        $driverProfileId = $document['driverProfileId'] ?? null;

        return new PartnerAccount(
            id: (string) $document['_id'],
            login: (string) $document['login'],
            passwordHash: (string) $document['passwordHash'],
            role: $role,
            supplierId: (string) $document['supplierId'],
            driverProfileId: \is_string($driverProfileId) ? $driverProfileId : null,
            active: (bool) ($document['active'] ?? true),
            mustChangePassword: (bool) ($document['mustChangePassword'] ?? false),
            createdAt: self::fromBson($document['createdAt'] ?? null) ?? new \DateTimeImmutable('@0'),
            updatedAt: self::fromBson($document['updatedAt'] ?? null),
            lastLoginAt: self::fromBson($document['lastLoginAt'] ?? null),
            schemaVersion: (int) ($document['schemaVersion'] ?? PartnerAccount::SCHEMA_VERSION),
        );
    }
}
