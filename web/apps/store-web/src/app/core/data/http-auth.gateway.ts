import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import { ApiClient } from '../api/api-client.service';
import { toLoginResponse } from '../api/wire.mapper';
import { WireAuthTokenResponse, WireLoginRequest } from '../api/wire.model';
import { LoginRequest, LoginResponse } from '../models/auth.model';
import { AuthGateway } from './gateways';

/**
 * identity-staff-service через api-gateway.
 *
 *   POST /api/store/v1/auth/login   { email, password } → плоска пара токенів + user
 *   POST /api/store/v1/auth/refresh { refreshToken }    → та сама структура
 *   POST /api/store/v1/auth/logout  { refreshToken }    → 204 No Content
 */
@Injectable()
export class HttpAuthGateway extends AuthGateway {
  private readonly api = inject(ApiClient);

  override login(request: LoginRequest): Observable<LoginResponse> {
    const body: WireLoginRequest = {
      email: request.email,
      password: request.password,
    };
    return this.api
      .post<WireAuthTokenResponse>('/auth/login', body)
      .pipe(map(toLoginResponse));
  }

  override refresh(refreshToken: string): Observable<LoginResponse> {
    return this.api
      .post<WireAuthTokenResponse>('/auth/refresh', { refreshToken })
      .pipe(map(toLoginResponse));
  }

  /** Бекенд вимагає непорожній refreshToken; без нього виклик не має сенсу. */
  override logout(refreshToken: string | null): Observable<void> {
    return this.api.post<void>('/auth/logout', { refreshToken });
  }
}
