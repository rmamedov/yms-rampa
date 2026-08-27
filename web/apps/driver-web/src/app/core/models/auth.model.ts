/**
 * Моделі автентифікації водія.
 *
 * ДЖЕРЕЛО ІСТИНИ — identity-partner-service:
 *   POST /api/driver/v1/auth/login   {phone, password, rememberMe}
 *   POST /api/driver/v1/auth/refresh {refreshToken}
 *   POST /api/driver/v1/auth/logout  {refreshToken} → 204
 * Форма відповіді — AuthResult::toArray() + AccountProfile::toArray().
 */

/** Ролі партнерського контуру (PartnerRole у identity-partner-service). */
export type PartnerRole = 'driver' | 'supplier_admin' | 'supplier_operator';

/** Контур автентифікації; для цього застосунку завжди `partner`. */
export type AuthContour = 'staff' | 'partner';

/** Профіль облікового запису — рівно поля AccountProfile::toArray(). */
export interface DriverProfile {
  readonly accountId: string;
  /** Логін водія — телефон E.164 (AUTH-23). Імені бекенд не віддає. */
  readonly login: string;
  readonly role: PartnerRole;
  readonly contour: AuthContour;
  readonly supplierId: string;
  /** Профіль водія у partner-service; у постачальника — null. */
  readonly driverId: string | null;
  readonly mustChangePassword: boolean;
}

export interface LoginRequest {
  /**
   * Поле запиту називається саме `phone` (DriverAuthController).
   * Нормалізується до E.164 ще на клієнті, сервер нормалізує повторно.
   */
  readonly phone: string;
  readonly password: string;
  /** «Запамʼятати мене» — 90 днів замість 30 (AUTH-27). За замовчуванням true. */
  readonly rememberMe: boolean;
}

export interface AuthTokens {
  readonly accessToken: string;
  readonly refreshToken: string;
  /** Момент закінчення access-токена, epoch ms (розібраний з accessExpiresAt). */
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
