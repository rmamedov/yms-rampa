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

    /**
     * Чи активний обліковий запис — «легке» читання для перевірки токена на
     * кожен запит (`GET /internal/v1/auth/verify`, AUTH-12, AUTH-28).
     *
     * Реалізація не має гідратувати весь документ (зокрема passwordHash):
     * достатньо проєкції поля `active`, покритої індексом `{_id:1, active:1}`.
     *
     * @return bool|null null, якщо акаунта не існує
     */
    public function isActive(string $id): ?bool;

    /** Збереження (upsert) — унікальний індекс по `login` (DATA-35). */
    public function save(PartnerAccount $account): void;
}
