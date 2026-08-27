<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Identity\StaffUser;
use App\Domain\Identity\StaffUserPage;
use App\Domain\UserManagement\StaffUserCredentials;

/**
 * Подання облікових записів у відповідях `/api/admin/v1/users`.
 *
 * Форма профілю збігається з LoginResult::profile() — admin-web розбирає
 * користувача одним мапером незалежно від того, звідки він прийшов.
 *
 * AUTH-61: ані `passwordHash`, ані історія хешів, ані секрет TOTP у
 * відповідь не потрапляють — назовні віддається лише сам факт увімкненої 2FA.
 */
final class StaffUserView
{
    /**
     * Текст попередження про НУЛЬ доступу (RBAC-13). Формулюється тут,
     * а не в інтерфейсі: правило належить бекенду, і однакове для будь-якого
     * клієнта, який колись зʼявиться.
     */
    public const string ZERO_ACCESS_WARNING =
        'Магазини не привʼязані: користувач не матиме доступу до жодного магазину.';

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function user(StaffUser $user): array
    {
        return [
            'id' => $user->id(),
            'email' => $user->email()->value,
            'fullName' => $user->fullName(),
            // RBAC-04: роль в однині, масиву ролей у моделі не існує
            'role' => $user->role()->value,
            'roleLabel' => $user->role()->label(),
            'scope' => self::scope($user),
            'active' => $user->isActive(),
            'twoFactorEnabled' => $user->isTwoFactorEnabled(),
            'lastLoginAt' => self::iso($user->lastLoginAt()),
            'createdAt' => self::iso($user->createdAt()),
            'updatedAt' => self::iso($user->updatedAt()),
        ];
    }

    /**
     * Скоуп із ЯВНОЮ ознакою нульового доступу.
     *
     * RBAC-13: для store_manager і store_operator порожній `storeIds` —
     * це нуль доступу, а не «вся мережа». Інтерфейс не має здогадуватися
     * про це з поєднання двох інших полів, тому ознака окрема: `zeroAccess`
     * (+ готовий текст попередження).
     *
     * @return array<string, mixed>
     */
    public static function scope(StaffUser $user): array
    {
        $zeroAccess = $user->role()->isStoreScoped() && [] === $user->storeIds();

        return [
            'storeIds' => $user->storeIds(),
            // RBAC-16: скоуп «вся мережа» визначається роллю
            'networkWide' => $user->isNetworkWide(),
            // RBAC-13: роль обмежена переліком магазинів
            'storeScoped' => $user->role()->isStoreScoped(),
            'zeroAccess' => $zeroAccess,
            'warning' => $zeroAccess ? self::ZERO_ACCESS_WARNING : null,
        ];
    }

    /**
     * Одноразовий показ пароля (створення акаунта і скидання пароля).
     *
     * @return array<string, mixed>
     */
    public static function credentials(StaffUserCredentials $credentials, bool $generated): array
    {
        return self::user($credentials->user) + [
            'login' => $credentials->user->email()->value,
            'password' => $credentials->password,
            'passwordGenerated' => $generated,
            'passwordNotice' => StaffUserCredentials::NOTICE,
        ];
    }

    /**
     * Конверт списку — той самий, що й в інших адмінських списках
     * (items / total / page / perPage / pages / emptyMessage).
     *
     * @return array<string, mixed>
     */
    public static function page(StaffUserPage $page): array
    {
        return [
            'items' => array_map(self::user(...), $page->items),
            'total' => $page->total,
            'page' => $page->page,
            'perPage' => $page->perPage,
            'pages' => $page->pages(),
            'emptyMessage' => $page->isEmpty()
                ? 'Користувачів за заданими умовами не знайдено'
                : null,
        ];
    }

    private static function iso(?\DateTimeImmutable $value): ?string
    {
        // DATA-01: UTC, ISO 8601
        return $value?->format(\DATE_ATOM);
    }
}
