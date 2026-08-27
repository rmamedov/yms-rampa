import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { firstValueFrom } from 'rxjs';
import { AuthApi } from './auth.api';
import { HttpAuthApi } from './http-auth.api';

/**
 * Тест закріплює контракт identity-partner-service:
 *   POST /api/driver/v1/auth/login   {phone, password, rememberMe}
 *   POST /api/driver/v1/auth/refresh {refreshToken}
 *   POST /api/driver/v1/auth/logout  {refreshToken} → 204
 * і форму AuthResult::toArray() + AccountProfile::toArray().
 */
describe('HttpAuthApi — контракт driver_auth_*', () => {
  let api: AuthApi;
  let http: HttpTestingController;

  const accessExpiresAt = '2026-08-27T12:15:00+00:00';

  const authResult = {
    accessToken: 'access-1',
    accessExpiresAt,
    expiresIn: 900,
    refreshToken: 'refresh-1',
    refreshExpiresAt: '2026-11-25T12:00:00+00:00',
    tokenType: 'Bearer' as const,
    profile: {
      accountId: 'acc-1001',
      login: '+380671234567',
      role: 'driver' as const,
      contour: 'partner' as const,
      supplierId: 'sup-77',
      driverId: 'drv-1001',
      mustChangePassword: false,
    },
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: AuthApi, useClass: HttpAuthApi },
      ],
    });
    api = TestBed.inject(AuthApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('вхід шле саме поле phone на /api/driver/v1/auth/login', async () => {
    const promise = firstValueFrom(
      api.login({ phone: '+380671234567', password: 'secret', rememberMe: true }),
    );

    const request = http.expectOne('/api/driver/v1/auth/login');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      phone: '+380671234567',
      password: 'secret',
      rememberMe: true,
    });
    request.flush(authResult);

    const response = await promise;
    expect(response.profile.driverId).toBe('drv-1001');
    // Момент протермінування береться з accessExpiresAt, а не з expiresIn.
    expect(response.accessExpiresAt).toBe(Date.parse(accessExpiresAt));
  });

  it('без accessExpiresAt момент рахується з expiresIn', async () => {
    const promise = firstValueFrom(
      api.login({ phone: '+380671234567', password: 'secret', rememberMe: false }),
    );
    const before = Date.now();

    http
      .expectOne('/api/driver/v1/auth/login')
      .flush({ ...authResult, accessExpiresAt: undefined });

    const response = await promise;
    expect(response.accessExpiresAt).toBeGreaterThanOrEqual(before + 900_000);
  });

  it('refresh шле refreshToken', async () => {
    const promise = firstValueFrom(api.refresh('refresh-1'));

    const request = http.expectOne('/api/driver/v1/auth/refresh');
    expect(request.request.body).toEqual({ refreshToken: 'refresh-1' });
    request.flush(authResult);

    expect((await promise).accessToken).toBe('access-1');
  });

  it('logout шле refreshToken і переживає порожню відповідь 204', async () => {
    const promise = firstValueFrom(api.logout('refresh-1'));

    const request = http.expectOne('/api/driver/v1/auth/logout');
    expect(request.request.body).toEqual({ refreshToken: 'refresh-1' });
    request.flush(null, { status: 204, statusText: 'No Content' });

    await expect(promise).resolves.toBeUndefined();
  });
});
