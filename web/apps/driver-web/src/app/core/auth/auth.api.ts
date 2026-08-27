import { Observable } from 'rxjs';
import type { LoginRequest, LoginResponse, AuthTokens } from '../models/auth.model';

/**
 * Контракт автентифікації водія (identity-partner-service через api-gateway):
 *  POST /api/driver/v1/auth/login
 *  POST /api/driver/v1/auth/refresh
 *  POST /api/driver/v1/auth/logout
 */
export abstract class AuthApi {
  abstract login(request: LoginRequest): Observable<LoginResponse>;
  abstract refresh(refreshToken: string): Observable<LoginResponse>;
  abstract logout(refreshToken: string): Observable<void>;
}

export type { AuthTokens };
