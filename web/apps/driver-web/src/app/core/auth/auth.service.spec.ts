import { TestBed } from '@angular/core/testing';
import { Observable, firstValueFrom, of, throwError } from 'rxjs';
import { AuthService } from './auth.service';
import { AuthApi } from './auth.api';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';
import { ApiProblemError } from '../models/problem.model';
import type { LoginRequest, LoginResponse } from '../models/auth.model';

function response(role: 'driver' | 'supplier_admin', suffix = '1'): LoginResponse {
  return {
    accessToken: `access-${suffix}`,
    refreshToken: `refresh-${suffix}`,
    accessExpiresAt: Date.now() + 900_000,
    profile: {
      accountId: 'acc-1',
      login: '+380671234567',
      role,
      contour: 'partner',
      supplierId: 'sup-1',
      driverId: role === 'driver' ? 'drv-1' : null,
      mustChangePassword: false,
    },
  };
}

class FakeAuthApi extends AuthApi {
  lastLogin: LoginRequest | null = null;
  loginResult: LoginResponse | unknown = response('driver');
  refreshResult: LoginResponse | unknown = response('driver', '2');
  logoutCalls = 0;

  override login(request: LoginRequest): Observable<LoginResponse> {
    this.lastLogin = request;
    return emit(this.loginResult);
  }
  override refresh(): Observable<LoginResponse> {
    return emit(this.refreshResult);
  }
  override logout(): Observable<void> {
    this.logoutCalls += 1;
    return of(undefined);
  }
}

function emit(value: unknown): Observable<LoginResponse> {
  return value instanceof Error
    ? throwError(() => value)
    : of(value as LoginResponse);
}

describe('AuthService (DRV-06, DRV-09, DRV-10)', () => {
  let auth: AuthService;
  let api: FakeAuthApi;
  let storage: LocalStorageService;

  beforeEach(() => {
    localStorage.clear();
    api = new FakeAuthApi();
    TestBed.configureTestingModule({
      providers: [{ provide: AuthApi, useValue: api }],
    });
    auth = TestBed.inject(AuthService);
    storage = TestBed.inject(LocalStorageService);
  });

  it('нормалізує телефон до E.164 перед відправкою на сервер', async () => {
    await firstValueFrom(auth.login('067 123-45-67', 'secret', true));

    expect(api.lastLogin?.phone).toBe('+380671234567');
    expect(api.lastLogin?.rememberMe).toBe(true);
  });

  it('невалідний телефон не доходить до мережі', async () => {
    await expect(
      firstValueFrom(auth.login('12345', 'secret', true)),
    ).rejects.toBeInstanceOf(ApiProblemError);
    expect(api.lastLogin).toBeNull();
  });

  it('успішний вхід зберігає сесію в localStorage (довга сесія DRV-07)', async () => {
    await firstValueFrom(auth.login('+380671234567', 'secret', true));

    expect(auth.isAuthenticated()).toBe(true);
    expect(auth.profile()?.role).toBe('driver');
    expect(storage.getRaw(STORAGE_KEYS.session)).toContain('access-1');
  });

  it('відхиляє токен з роллю, відмінною від driver (DRV-10)', async () => {
    api.loginResult = response('supplier_admin');

    await expect(
      firstValueFrom(auth.login('+380671234567', 'secret', true)),
    ).rejects.toMatchObject({ code: 'AUTH_ROLE_NOT_ALLOWED' });
    expect(auth.isAuthenticated()).toBe(false);
  });

  it('refresh оновлює пару токенів', async () => {
    await firstValueFrom(auth.login('+380671234567', 'secret', true));

    const tokens = await firstValueFrom(auth.refreshAccessToken());

    expect(tokens.accessToken).toBe('access-2');
    expect(auth.accessToken).toBe('access-2');
  });

  it('провал refresh очищає сесію', async () => {
    await firstValueFrom(auth.login('+380671234567', 'secret', true));
    api.refreshResult = new ApiProblemError(401, { code: 'AUTH_REFRESH_REUSED' });

    await expect(firstValueFrom(auth.refreshAccessToken())).rejects.toBeDefined();
    expect(auth.isAuthenticated()).toBe(false);
    expect(storage.getRaw(STORAGE_KEYS.session)).toBeNull();
  });

  it('вихід інвалідує refresh на сервері і чистить локальний кеш (DRV-09)', async () => {
    await firstValueFrom(auth.login('+380671234567', 'secret', true));
    storage.write(STORAGE_KEYS.routeSheetCache, { sheet: {}, cachedAt: 1 });
    storage.setRaw(STORAGE_KEYS.navigatorApp, 'waze');

    await firstValueFrom(auth.logout());

    expect(api.logoutCalls).toBe(1);
    expect(auth.isAuthenticated()).toBe(false);
    expect(storage.getRaw(STORAGE_KEYS.session)).toBeNull();
    expect(storage.getRaw(STORAGE_KEYS.routeSheetCache)).toBeNull();
    expect(storage.getRaw(STORAGE_KEYS.navigatorApp)).toBeNull();
  });

  it('сесія з не-driver роллю не відновлюється зі сховища', () => {
    storage.write(STORAGE_KEYS.session, {
      ...response('supplier_admin'),
    });

    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [{ provide: AuthApi, useValue: api }],
    });

    expect(TestBed.inject(AuthService).isAuthenticated()).toBe(false);
  });
});
