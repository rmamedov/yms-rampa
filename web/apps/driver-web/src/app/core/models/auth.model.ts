/** Моделі автентифікації водія (розділ 3 SRS, DRV-06..DRV-10). */

export type PartnerRole = 'driver' | 'supplier_admin' | 'supplier_operator';

export interface DriverProfile {
  readonly driverId: string;
  readonly fullName: string;
  /** Логін у форматі E.164. */
  readonly phone: string;
  readonly supplierId: string;
  readonly supplierName: string;
  readonly role: PartnerRole;
}

export interface LoginRequest {
  /** Телефон, нормалізований до E.164 (+380XXXXXXXXX). */
  readonly phone: string;
  readonly password: string;
  /** «Запамʼятати мене» — 90 днів замість 30 (AUTH-27). */
  readonly rememberMe: boolean;
}

export interface AuthTokens {
  readonly accessToken: string;
  readonly refreshToken: string;
  /** Момент закінчення access-токена, epoch ms. */
  readonly accessExpiresAt: number;
}

export interface LoginResponse extends AuthTokens {
  readonly profile: DriverProfile;
}

export interface RefreshRequest {
  readonly refreshToken: string;
}

export interface StoredSession extends AuthTokens {
  readonly profile: DriverProfile;
}
