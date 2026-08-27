<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Сховище облікових записів співробітників (колекція `staff_users`, 10.5).
 *
 * Домен знає лише інтерфейс; реалізації — Infrastructure\Mongo (прод)
 * та Infrastructure\InMemory (тести й локальна розробка без MongoDB).
 */
interface StaffUserRepository
{
    public function findById(string $id): ?StaffUser;

    /**
     * Вузьке читання для перевірки токена шлюзом (`/internal/v1/auth/verify`).
     *
     * Викликається на КОЖЕН запит до API, тому реалізація зобовʼязана:
     *  - шукати за первинним ключем `_id` (індекс існує завжди, 10.5);
     *  - повертати лише role/storeIds/active, без хеша пароля та його історії.
     */
    public function findIdentityById(string $id): ?IdentitySnapshot;

    /**
     * Пошук за унікальним індексом `{email:1}` (10.5).
     */
    public function findByEmail(Email $email): ?StaffUser;

    public function save(StaffUser $user): void;

    /**
     * RBAC-25: у системі має лишатись щонайменше один активний super_admin.
     */
    public function countActiveByRole(Role $role): int;

    /**
     * Скоуп-фільтрація на рівні запиту до БД (RBAC-17).
     *
     * @param list<string>|null $storeIds null — без фільтра (скоуп «вся мережа», RBAC-16);
     *                                    порожній масив — гарантовано порожня вибірка (RBAC-13)
     *
     * @return list<StaffUser>
     */
    public function findByStoreScope(?array $storeIds): array;

    /**
     * Список для розділу «Користувачі» адмін-панелі (4.7): серверні фільтри,
     * пошук і пагінація. Фільтри — обовʼязковий предикат ЗАПИТУ, а не
     * пост-фільтрація в памʼяті (RBAC-17).
     */
    public function search(StaffUserCriteria $criteria): StaffUserPage;
}
