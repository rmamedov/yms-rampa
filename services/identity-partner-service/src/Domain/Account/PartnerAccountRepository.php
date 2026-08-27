<?php

declare(strict_types=1);

namespace App\Domain\Account;

/**
 * Репозиторій креденшлів партнерського контуру (`partner_accounts`, 10.6).
 *
 * Інтерфейс живе в домені; реалізації — Infrastructure\Mongo (прод) та
 * Infrastructure\InMemory (тести й локальна розробка без MongoDB).
 */
interface PartnerAccountRepository
{
    public function findById(string $id): ?PartnerAccount;

    /**
     * Пошук за логіном. Логін приходить уже нормалізованим:
     * телефон E.164 `+380XXXXXXXXX` або email у нижньому регістрі (AUTH-23).
     */
    public function findByLogin(string $login): ?PartnerAccount;

    /**
     * Усі акаунти постачальника — для масової деактивації (AUTH-28).
     *
     * @return list<PartnerAccount>
     */
    public function findBySupplierId(string $supplierId): array;

    /** Збереження (upsert) — унікальний індекс по `login` (DATA-35). */
    public function save(PartnerAccount $account): void;
}
