import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { AuthSession, AuthTokens } from '../../models';
import { AuthApi } from '../auth.api';
import { MockDb } from './mock-db';
import { fail, MOCK_LATENCY, normalize, respond } from './mock-support';

function issueTokens(userId: string): AuthTokens {
  const now = Date.now();
  return {
    accessToken: `mock.access.${userId}.${now}`,
    refreshToken: `mock.refresh.${userId}.${now}`,
    expiresAt: new Date(now + 15 * 60_000).toISOString(),
  };
}

/** Мок identity-staff-service: пароль не перевіряється, роль береться з довідника. */
@Injectable()
export class MockAuthApi extends AuthApi {
  private readonly db = inject(MockDb);
  private readonly latency = inject(MOCK_LATENCY);

  login(email: string, password: string): Observable<AuthSession> {
    const user = this.db.state.staff.find(
      (u) => normalize(u.email) === normalize(email),
    );
    if (!user || password.trim() === '') {
      return fail(
        401,
        { code: 'AUTH_TOKEN_INVALID', detail: 'Невірний e-mail або пароль' },
        this.latency,
      );
    }
    if (!user.active) {
      return fail(
        403,
        { code: 'RBAC_PERMISSION_DENIED', detail: 'Обліковий запис деактивовано' },
        this.latency,
      );
    }
    return respond(
      () => ({
        user: {
          id: user.id,
          fullName: user.fullName,
          email: user.email,
          role: user.role,
          storeIds: [...user.storeIds],
        },
        tokens: issueTokens(user.id),
      }),
      this.latency,
    );
  }

  refresh(refreshToken: string): Observable<AuthTokens> {
    const userId = refreshToken.split('.')[2] ?? '';
    const user = this.db.state.staff.find((u) => u.id === userId);
    if (!user || !user.active) {
      return fail(401, { code: 'AUTH_TOKEN_INVALID' }, this.latency);
    }
    return respond(() => issueTokens(user.id), this.latency);
  }
}
