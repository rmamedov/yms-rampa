/**
 * Автентифікація staff-контуру (/api/store/v1/auth/...) і RBAC.
 *
 * Профіль повторює `LoginResult::profile()` identity-staff-service, ролі —
 * канонічний перелік `App\Domain\Identity\Role` (RBAC-06). Інших ролей немає.
 */

export type StaffRole =
  | 'super_admin'
  | 'network_manager'
  | 'store_manager'
  | 'store_operator'
  | 'analyst'
  | 'supplier_admin'
  | 'supplier_operator'
  | 'driver';

/**
 * Ролі, яким дозволено вхід у store-web.
 *
 * Перелік узгоджено з канонічною матрицею прав (PermissionMatrix у
 * identity-staff-service): мережеві ролі мають повноваження на дії магазину
 * (arrived, unloading, unloaded, no_show, delayed, reject, reassign_ramp,
 * create_walk_in, read.all), тому замикати їх на вході неправильно — бекенд
 * ці дії від них приймає, а інтерфейс не пускав.
 */
export const STORE_ROLES: readonly StaffRole[] = [
  'store_manager',
  'store_operator',
  'network_manager',
  'super_admin',
];

/** Ролі, чий доступ не обмежений переліком магазинів (RBAC-16). */
export const NETWORK_WIDE_ROLES: readonly StaffRole[] = ['super_admin', 'network_manager'];

/**
 * Магазин у скоупі користувача.
 *
 * Бекенд у профілі віддає лише `scope.storeIds`, тому `displayName` за
 * замовчуванням дорівнює ідентифікатору; описові поля заповнюються зі
 * снапшота філії в бронюванні (`booking.store`) або з конфігурації магазину
 * в мок-режимі.
 */
export interface StoreScope {
  readonly storeId: string;
  readonly displayName: string;
  readonly externalId: string | null;
  readonly city: string | null;
  readonly address: string | null;
}

export interface StaffProfile {
  readonly userId: string;
  readonly fullName: string;
  readonly email: string;
  /** RBAC-04: рівно одна роль. */
  readonly role: StaffRole;
  readonly roleLabel: string;
  /** RBAC-13: порожній перелік = нуль доступу, а не «усі магазини». */
  readonly storeIds: readonly string[];
  /** RBAC-16: доступ до всієї мережі без фільтра за storeIds. */
  readonly networkWide: boolean;
  readonly twoFactorEnabled: boolean;
  readonly permissions: readonly string[];
}

export interface AuthTokens {
  readonly tokenType: string;
  readonly accessToken: string;
  readonly refreshToken: string;
  readonly sessionId: string;
  /** ISO 8601 — момент протермінування access-токена. */
  readonly accessExpiresAt: string;
  readonly refreshExpiresAt: string;
  /** epoch ms, обчислюється з accessExpiresAt. */
  readonly expiresAt: number;
}

/** Тіло POST /api/store/v1/auth/login — поле саме `email`. */
export interface LoginRequest {
  readonly email: string;
  readonly password: string;
}

/** Розібрана відповідь логіну/refresh: бекенд віддає їх однією плоскою структурою. */
export interface LoginResponse {
  readonly tokens: AuthTokens;
  readonly profile: StaffProfile;
}
