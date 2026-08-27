import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient } from '../api/api-client.service';
import { AuthTokens, LoginRequest, LoginResponse } from '../models/auth.model';
import { AuthGateway } from './gateways';

/** Реальна реалізація: identity-staff-service через api-gateway. */
@Injectable()
export class HttpAuthGateway extends AuthGateway {
  private readonly api = inject(ApiClient);

  override login(request: LoginRequest): Observable<LoginResponse> {
    return this.api.post<LoginResponse>('/auth/login', request);
  }

  override refresh(refreshToken: string): Observable<AuthTokens> {
    return this.api.post<AuthTokens>('/auth/refresh', { refreshToken });
  }

  override logout(refreshToken: string | null): Observable<void> {
    return this.api.post<void>('/auth/logout', { refreshToken });
  }
}
