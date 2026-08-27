/** Автентифікація staff-контуру (розділ 3 SRS) і RBAC (розділ 4). */

export type StaffRole =
  | 'store_manager'
  | 'store_operator'
  | 'admin'
  | 'supplier_manager'
  | 'driver';

/** Ролі, яким дозволено вхід у store-web (STW-01). */
export const STORE_ROLES: readonly StaffRole[] = [
  'store_manager',
  'store_operator',
];

export interface StoreScope {
  readonly storeId: string;
  readonly externalId: string;
  readonly displayName: string;
  readonly city: string;
  readonly address: string;
}

export interface StaffProfile {
  readonly userId: string;
  readonly fullName: string;
  readonly email: string;
  readonly role: StaffRole;
  /** Магазини, закріплені за користувачем (STW-02). */
  readonly stores: readonly StoreScope[];
}

export interface AuthTokens {
  readonly accessToken: string;
  readonly refreshToken: string;
  /** epoch ms */
  readonly expiresAt: number;
}

export interface LoginRequest {
  readonly email: string;
  readonly password: string;
}

export interface LoginResponse {
  readonly tokens: AuthTokens;
  readonly profile: StaffProfile;
}
