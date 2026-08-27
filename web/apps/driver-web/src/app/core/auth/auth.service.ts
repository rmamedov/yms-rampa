import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, of, shareReplay, tap, throwError } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import { AuthApi } from './auth.api';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';
import { ApiProblemError } from '../models/problem.model';
import type {
  AuthTokens,
  DriverProfile,
  LoginResponse,
  StoredSession,
} from '../models/auth.model';
import { normalizePhone } from '../util/phone.util';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(AuthApi);
  private readonly storage = inject(LocalStorageService);

  private readonly sessionSignal = signal<StoredSession | null>(
    this.restoreSession(),
  );

  /** Поточна сесія водія. */
  readonly session = this.sessionSignal.asReadonly();
  readonly profile = computed<DriverProfile | null>(
    () => this.sessionSignal()?.profile ?? null,
  );
  readonly isAuthenticated = computed(() => this.sessionSignal() !== null);

  private refreshInFlight: Observable<AuthTokens> | null = null;

  get accessToken(): string | null {
    return this.sessionSignal()?.accessToken ?? null;
  }

  get refreshToken(): string | null {
    return this.sessionSignal()?.refreshToken ?? null;
  }

  /**
   * Вхід водія. Телефон нормалізується до E.164 ще на клієнті (DRV-06).
   * JWT з роллю, відмінною від `driver`, відхиляється (DRV-10).
   */
  login(
    rawPhone: string,
    password: string,
    rememberMe: boolean,
  ): Observable<DriverProfile> {
    const phone = normalizePhone(rawPhone);
    if (!phone) {
      return throwError(
        () =>
          new ApiProblemError(422, {
            code: 'VALIDATION_ERROR',
            detail: 'Невірний формат телефону',
            violations: [
              { field: 'phone', code: 'format', message: 'Невірний формат телефону' },
            ],
          }),
      );
    }
    return this.api.login({ phone, password, rememberMe }).pipe(
      map((response: LoginResponse) => {
        if (response.profile.role !== 'driver') {
          throw new ApiProblemError(403, {
            code: 'AUTH_ROLE_NOT_ALLOWED',
            detail: 'Цей застосунок призначений лише для водіїв',
          });
        }
        this.persist(response);
        return response.profile;
      }),
    );
  }

  /** Безшовне оновлення access-токена; паралельні виклики поділяють один запит. */
  refreshAccessToken(): Observable<AuthTokens> {
    if (this.refreshInFlight) {
      return this.refreshInFlight;
    }
    const token = this.refreshToken;
    if (!token) {
      return throwError(
        () =>
          new ApiProblemError(401, {
            code: 'AUTH_TOKEN_INVALID',
            detail: 'Сесія завершилась. Увійдіть повторно.',
          }),
      );
    }
    this.refreshInFlight = this.api.refresh(token).pipe(
      map((response) => {
        this.persist(response);
        return {
          accessToken: response.accessToken,
          refreshToken: response.refreshToken,
          accessExpiresAt: response.accessExpiresAt,
        } satisfies AuthTokens;
      }),
      tap({
        error: () => this.clearSession(),
        finalize: () => {
          this.refreshInFlight = null;
        },
      }),
      shareReplay({ bufferSize: 1, refCount: false }),
    );
    return this.refreshInFlight;
  }

  /** Вихід: інвалідує refresh на сервері і чистить увесь локальний кеш (DRV-09). */
  logout(): Observable<void> {
    const token = this.refreshToken;
    const request = token
      ? this.api.logout(token).pipe(catchError(() => of(undefined)))
      : of(undefined);
    return request.pipe(
      map(() => {
        this.clearSession();
        this.storage.clearAppData();
        return undefined;
      }),
    );
  }

  /** Скидання сесії без запиту на сервер (напр. після провалу refresh). */
  clearSession(): void {
    this.sessionSignal.set(null);
    this.storage.remove(STORAGE_KEYS.session);
    this.storage.remove(STORAGE_KEYS.routeSheetCache);
  }

  private persist(response: LoginResponse): void {
    const session: StoredSession = {
      accessToken: response.accessToken,
      refreshToken: response.refreshToken,
      accessExpiresAt: response.accessExpiresAt,
      profile: response.profile,
    };
    this.sessionSignal.set(session);
    this.storage.write(STORAGE_KEYS.session, session);
  }

  private restoreSession(): StoredSession | null {
    const stored = this.storage.read<StoredSession | null>(
      STORAGE_KEYS.session,
      null,
    );
    if (!stored?.accessToken || !stored.refreshToken || !stored.profile) {
      return null;
    }
    if (stored.profile.role !== 'driver') {
      return null;
    }
    return stored;
  }
}
