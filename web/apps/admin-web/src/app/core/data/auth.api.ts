import { Observable } from 'rxjs';
import { AuthSession, AuthTokens } from '../models';

/**
 * identity-staff-service, staff-контур:
 *   POST /api/admin/v1/auth/login
 *   POST /api/admin/v1/auth/refresh
 *   POST /api/admin/v1/auth/logout
 */
export abstract class AuthApi {
  abstract login(email: string, password: string): Observable<AuthSession>;
  abstract refresh(refreshToken: string): Observable<AuthTokens>;
  /** AUTH-32: відкликає refresh поточної сесії (allDevices — усі сесії). */
  abstract logout(refreshToken: string, allDevices?: boolean): Observable<void>;
}
