<?php

declare(strict_types=1);

namespace App\Domain\PartnerUser;

/**
 * Порт сховища профілів партнерського контуру.
 *
 * DATA-17: unique partial `{phone:1}` з фільтром `{type:"driver", archivedAt:null}` —
 * телефон водія унікальний ГЛОБАЛЬНО (він же логін), на відміну від держномера
 * авто, який унікальний лише в межах постачальника (DATA-18).
 */
interface PartnerUserRepository
{
    public function save(PartnerUser $user): void;

    public function findById(string $id): ?PartnerUser;

    public function findByAccountId(string $accountId): ?PartnerUser;

    /**
     * Пошук водія за нормалізованим телефоном серед НЕархівованих профілів,
     * без обмеження постачальником — саме це реалізує глобальну унікальність.
     */
    public function findDriverByPhone(string $phone): ?PartnerUser;

    /**
     * @return list<PartnerUser>
     */
    public function listBySupplier(
        string $supplierId,
        ?PartnerUserType $type = null,
        bool $includeInactive = true,
    ): array;

    public function remove(string $id): void;
}
