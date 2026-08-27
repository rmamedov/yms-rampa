import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpContext } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { AuthApi } from './auth.api';
import type { LoginRequest, LoginResponse } from '../models/auth.model';
import { environment } from '../../../environments/environment';
import { SKIP_AUTH } from '../http/auth.context';

interface RawLoginResponse {
  accessToken: string;
  refreshToken: string;
  /** Час життя access-токена в секундах. */
  expiresIn: number;
  profile: LoginResponse['profile'];
}

@Injectable()
export class HttpAuthApi extends AuthApi {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiBase}/auth`;

  override login(request: LoginRequest): Observable<LoginResponse> {
    return this.http
      .post<RawLoginResponse>(`${this.base}/login`, request, {
        context: new HttpContext().set(SKIP_AUTH, true),
      })
      .pipe(map(toLoginResponse));
  }

  override refresh(refreshToken: string): Observable<LoginResponse> {
    return this.http
      .post<RawLoginResponse>(
        `${this.base}/refresh`,
        { refreshToken },
        { context: new HttpContext().set(SKIP_AUTH, true) },
      )
      .pipe(map(toLoginResponse));
  }

  override logout(refreshToken: string): Observable<void> {
    return this.http
      .post<void>(`${this.base}/logout`, { refreshToken })
      .pipe(map(() => undefined));
  }
}

function toLoginResponse(raw: RawLoginResponse): LoginResponse {
  return {
    accessToken: raw.accessToken,
    refreshToken: raw.refreshToken,
    accessExpiresAt: Date.now() + (raw.expiresIn ?? 900) * 1000,
    profile: raw.profile,
  };
}
