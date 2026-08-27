import { Injectable } from '@angular/core';
import { Observable, defer, delay, of, throwError } from 'rxjs';
import { AuthApi } from './auth.api';
import type { LoginRequest, LoginResponse } from '../models/auth.model';
import { ApiProblemError } from '../models/problem.model';
import {
  MOCK_CREDENTIALS,
  MOCK_DRIVER,
  MOCK_NON_DRIVER_CREDENTIALS,
} from '../mock/mock-backend';

const ACCESS_TTL_MS = 15 * 60 * 1000;

@Injectable()
export class MockAuthApi extends AuthApi {
  private counter = 0;

  override login(request: LoginRequest): Observable<LoginResponse> {
    return defer(() => {
      if (
        request.phone === MOCK_NON_DRIVER_CREDENTIALS.phone &&
        request.password === MOCK_NON_DRIVER_CREDENTIALS.password
      ) {
        // Роль, відмінна від driver (DRV-10).
        return of(this.issue({ ...MOCK_DRIVER, role: 'supplier_admin' })).pipe(
          delay(250),
        );
      }
      if (
        request.phone !== MOCK_CREDENTIALS.phone ||
        request.password !== MOCK_CREDENTIALS.password
      ) {
        return throwError(
          () =>
            new ApiProblemError(401, {
              code: 'AUTH_INVALID_CREDENTIALS',
              detail: 'Невірний телефон або пароль',
            }),
        ).pipe(delay(250));
      }
      return of(this.issue(MOCK_DRIVER)).pipe(delay(250));
    });
  }

  override refresh(refreshToken: string): Observable<LoginResponse> {
    return defer(() => {
      if (!refreshToken.startsWith('mock-refresh')) {
        return throwError(
          () =>
            new ApiProblemError(401, {
              code: 'AUTH_TOKEN_INVALID',
              detail: 'Сесія завершилась',
            }),
        );
      }
      return of(this.issue(MOCK_DRIVER)).pipe(delay(120));
    });
  }

  override logout(): Observable<void> {
    return of(undefined).pipe(delay(80));
  }

  private issue(profile: LoginResponse['profile']): LoginResponse {
    this.counter += 1;
    return {
      accessToken: `mock-access-${this.counter}`,
      refreshToken: `mock-refresh-${this.counter}`,
      accessExpiresAt: Date.now() + ACCESS_TTL_MS,
      profile,
    };
  }
}
