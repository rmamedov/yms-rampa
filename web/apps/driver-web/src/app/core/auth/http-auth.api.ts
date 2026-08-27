import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpContext } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { AuthApi } from './auth.api';
import type { LoginRequest, LoginResponse } from '../models/auth.model';
import { environment } from '../../../environments/environment';
import { SKIP_AUTH } from '../http/auth.context';

/** Тіло відповіді AuthResult::toArray() identity-partner-service. */
interface RawAuthResponse {
  accessToken: string;
  /** ISO 8601 з таймзоною (DATE_ATOM) — основне джерело моменту протермінування. */
  accessExpiresAt: string;
  /** Час життя access-токена в секундах; запасний варіант. */
  expiresIn: number;
  refreshToken: string;
  refreshExpiresAt: string;
  tokenType: 'Bearer';
  profile: LoginResponse['profile'];
}

const DEFAULT_ACCESS_TTL_SECONDS = 900;

@Injectable()
export class HttpAuthApi extends AuthApi {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiBase}/auth`;

  override login(request: LoginRequest): Observable<LoginResponse> {
    return this.http
      .post<RawAuthResponse>(`${this.base}/login`, request, {
        context: new HttpContext().set(SKIP_AUTH, true),
      })
      .pipe(map(toLoginResponse));
  }

  override refresh(refreshToken: string): Observable<LoginResponse> {
    return this.http
      .post<RawAuthResponse>(
        `${this.base}/refresh`,
        { refreshToken },
        { context: new HttpContext().set(SKIP_AUTH, true) },
      )
      .pipe(map(toLoginResponse));
  }

  /** Бекенд відповідає 204 без тіла. */
  override logout(refreshToken: string): Observable<void> {
    return this.http
      .post<void>(`${this.base}/logout`, { refreshToken })
      .pipe(map(() => undefined));
  }
}

function toLoginResponse(raw: RawAuthResponse): LoginResponse {
  const absolute = Date.parse(raw.accessExpiresAt ?? '');

  return {
    accessToken: raw.accessToken,
    refreshToken: raw.refreshToken,
    accessExpiresAt: Number.isNaN(absolute)
      ? Date.now() + (raw.expiresIn ?? DEFAULT_ACCESS_TTL_SECONDS) * 1000
      : absolute,
    profile: raw.profile,
  };
}
