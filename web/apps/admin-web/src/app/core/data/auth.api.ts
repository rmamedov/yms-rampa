import { Observable } from 'rxjs';
import { AuthSession, AuthTokens } from '../models';

/** Контракт identity-staff-service (контур staff). */
export abstract class AuthApi {
  abstract login(email: string, password: string): Observable<AuthSession>;
  abstract refresh(refreshToken: string): Observable<AuthTokens>;
}
