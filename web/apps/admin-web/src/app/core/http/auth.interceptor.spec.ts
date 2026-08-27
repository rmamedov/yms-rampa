import { TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { authInterceptor } from './auth.interceptor';
import { AuthService } from '../auth/auth.service';
import { AuthApi } from '../data/auth.api';
import { MockAuthApi } from '../data/mock/mock-auth.api';
import { MOCK_LATENCY } from '../data/mock/mock-support';
import { AuthSession } from '../models';

const SESSION: AuthSession = {
  user: {
    id: 'su-1',
    fullName: 'Руслан Мамедов',
    email: 'super.admin@silpo.ua',
    role: 'super_admin',
    storeIds: [],
  },
  tokens: {
    accessToken: 'access-1',
    refreshToken: 'mock.refresh.su-1.1',
    expiresAt: new Date(Date.now() + 900_000).toISOString(),
  },
};

describe('authInterceptor', () => {
  let http: HttpClient;
  let httpMock: HttpTestingController;
  let auth: AuthService;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    http = TestBed.inject(HttpClient);
    httpMock = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);
  });

  afterEach(() => {
    httpMock.verify();
    localStorage.clear();
  });

  it('додає Authorization: Bearer <access token>', () => {
    auth.setSession(SESSION);
    http.get('/api/admin/v1/stores').subscribe();
    const request = httpMock.expectOne('/api/admin/v1/stores');
    expect(request.request.headers.get('Authorization')).toBe('Bearer access-1');
    request.flush({});
  });

  it('не додає заголовок без сесії', () => {
    http.get('/api/admin/v1/stores').subscribe();
    const request = httpMock.expectOne('/api/admin/v1/stores');
    expect(request.request.headers.has('Authorization')).toBe(false);
    request.flush({});
  });

  it('не чіпає маршрути логіна/refresh', () => {
    auth.setSession(SESSION);
    http.post('/api/admin/v1/auth/login', {}).subscribe();
    const request = httpMock.expectOne('/api/admin/v1/auth/login');
    expect(request.request.headers.has('Authorization')).toBe(false);
    request.flush({});
  });

  it('на 401 робить refresh і повторює запит з новим токеном', async () => {
    auth.setSession(SESSION);
    const done = new Promise<unknown>((resolve, reject) => {
      http.get('/api/admin/v1/stores').subscribe({ next: resolve, error: reject });
    });

    const first = httpMock.expectOne('/api/admin/v1/stores');
    expect(first.request.headers.get('Authorization')).toBe('Bearer access-1');
    first.flush({ code: 'AUTH_TOKEN_INVALID' }, { status: 401, statusText: 'Unauthorized' });

    await Promise.resolve();
    const retry = httpMock.expectOne('/api/admin/v1/stores');
    expect(retry.request.headers.get('Authorization')).not.toBe('Bearer access-1');
    expect(retry.request.headers.get('Authorization')).toContain('Bearer mock.access.su-1');
    retry.flush({ ok: true });

    await expect(done).resolves.toEqual({ ok: true });
    expect(auth.isAuthenticated()).toBe(true);
  });

  it('без refresh-токена 401 завершує сесію', async () => {
    auth.setSession({
      ...SESSION,
      tokens: { ...SESSION.tokens, refreshToken: '' },
    });
    const done = new Promise<void>((resolve) => {
      http.get('/api/admin/v1/stores').subscribe({ error: () => resolve() });
    });
    httpMock
      .expectOne('/api/admin/v1/stores')
      .flush({}, { status: 401, statusText: 'Unauthorized' });
    await done;
    expect(auth.isAuthenticated()).toBe(false);
  });

  it('інші статуси не викликають refresh', async () => {
    auth.setSession(SESSION);
    const done = new Promise<number>((resolve) => {
      http.get('/api/admin/v1/stores').subscribe({
        error: (error: { status: number }) => resolve(error.status),
      });
    });
    httpMock
      .expectOne('/api/admin/v1/stores')
      .flush({}, { status: 500, statusText: 'Server Error' });
    await expect(done).resolves.toBe(500);
    expect(auth.isAuthenticated()).toBe(true);
  });
});
