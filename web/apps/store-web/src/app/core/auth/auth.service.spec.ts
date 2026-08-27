import { TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import { AuthService } from './auth.service';
import { TokenStorageService } from './token-storage.service';
import { AuthGateway } from '../data/gateways';
import { toLoginResponse } from '../api/wire.mapper';
import { WireAuthTokenResponse, WireStaffUser } from '../api/wire.model';
import { LoginRequest, LoginResponse } from '../models/auth.model';
import { AppError } from '../models/problem.model';

/** Профіль рівно у формі `LoginResult::profile()`. */
function user(
  overrides: Partial<WireStaffUser> = {},
): WireStaffUser {
  return {
    id: 'u-1',
    email: 'operator@silpo.ua',
    fullName: 'Оксана Литвин',
    role: 'store_operator',
    roleLabel: 'Приймальник магазину',
    scope: { storeIds: ['s-1', 's-2'], networkWide: false },
    twoFactorEnabled: false,
    permissions: ['booking.read.all'],
    ...overrides,
  };
}

/** Відповідь рівно у формі `AuthController::tokenResponse()` — плоска. */
function tokenResponse(
  profile: WireStaffUser,
  accessToken = 'a1',
  refreshToken = 'r1',
): WireAuthTokenResponse {
  return {
    tokenType: 'Bearer',
    accessToken,
    expiresIn: 900,
    accessExpiresAt: new Date(Date.now() + 900_000).toISOString(),
    refreshToken,
    refreshExpiresAt: new Date(Date.now() + 86_400_000).toISOString(),
    sessionId: 'sess-1',
    user: profile,
  };
}

const OPERATOR = user();
const NETWORK_MANAGER = user({
  id: 'u-2',
  email: 'admin@silpo.ua',
  fullName: 'Тарас Гнатюк',
  role: 'network_manager',
  roleLabel: 'Менеджер мережі',
  scope: { storeIds: [], networkWide: true },
});

class FakeAuthGateway extends AuthGateway {
  profile: WireStaffUser = OPERATOR;
  failLogin = false;

  override login(request: LoginRequest): Observable<LoginResponse> {
    void request;
    if (this.failLogin) {
      return throwError(
        () =>
          new AppError(
            { status: 401, code: 'AUTH_INVALID_CREDENTIALS' },
            'error.AUTH_INVALID_CREDENTIALS',
          ),
      );
    }
    return of(toLoginResponse(tokenResponse(this.profile)));
  }

  override refresh(): Observable<LoginResponse> {
    return of(toLoginResponse(tokenResponse(this.profile, 'a2', 'r2')));
  }

  override logout(): Observable<void> {
    return of(undefined);
  }
}

describe('AuthService', () => {
  let gateway: FakeAuthGateway;
  let auth: AuthService;

  beforeEach(() => {
    localStorage.clear();
    gateway = new FakeAuthGateway();
    TestBed.configureTestingModule({
      providers: [
        TokenStorageService,
        { provide: AuthGateway, useValue: gateway },
      ],
    });
    auth = TestBed.inject(AuthService);
  });

  it('після входу зберігає токени і профіль', () => {
    auth.login({ email: 'operator@silpo.ua', password: 'x' }).subscribe();
    expect(auth.isAuthenticated()).toBe(true);
    expect(auth.hasStoreAccess()).toBe(true);
    expect(auth.profile()?.roleLabel).toBe('Приймальник магазину');
    expect(localStorage.getItem('yms.store.tokens')).toContain('a1');
  });

  // Мережеві ролі МАЮТЬ доступ до модуля магазину: канонічна матриця прав дає
  // їм повний набір дій магазину, і бекенд ці дії від них приймає. Раніше
  // застосунок пускав лише store_manager/store_operator, через що модуль був
  // недоступний узагалі — на стенді таких облікових записів не існувало.
  it('мережева роль отримує доступ до модуля магазину', () => {
    gateway.profile = NETWORK_MANAGER;
    auth.login({ email: 'admin@silpo.ua', password: 'x' }).subscribe();
    expect(auth.isAuthenticated()).toBe(true);
    expect(auth.hasStoreAccess()).toBe(true);
  });

  it('роль партнерського контуру доступу не отримує (STW-01)', () => {
    gateway.profile = { ...NETWORK_MANAGER, role: 'driver' };
    auth.login({ email: 'driver@rampa.ua', password: 'x' }).subscribe();
    expect(auth.isAuthenticated()).toBe(true);
    expect(auth.hasStoreAccess()).toBe(false);
  });

  it('перемикач магазину зʼявляється лише за 2+ магазинів (STW-03)', () => {
    auth.login({ email: 'operator@silpo.ua', password: 'x' }).subscribe();
    expect(auth.showStoreSwitcher()).toBe(true);
    expect(auth.selectedStore()?.storeId).toBe('s-1');

    gateway.profile = user({ scope: { storeIds: ['s-1'], networkWide: false } });
    auth.logout();
    auth.login({ email: 'operator@silpo.ua', password: 'x' }).subscribe();
    expect(auth.showStoreSwitcher()).toBe(false);
    expect(auth.selectedStore()?.storeId).toBe('s-1');
  });

  it('без конфігурації магазину підписом лишається його ідентифікатор', () => {
    auth.login({ email: 'operator@silpo.ua', password: 'x' }).subscribe();
    expect(auth.selectedStore()?.displayName).toBe('s-1');
  });

  it('вибір магазину зберігається між сесіями', () => {
    auth.login({ email: 'operator@silpo.ua', password: 'x' }).subscribe();
    auth.selectStore('s-2');
    expect(auth.selectedStore()?.storeId).toBe('s-2');
    expect(localStorage.getItem('yms.store.selectedStoreId')).toBe('s-2');

    auth.logout();
    auth.login({ email: 'operator@silpo.ua', password: 'x' }).subscribe();
    expect(auth.selectedStore()?.storeId).toBe('s-2');
  });

  it('ігнорує вибір магазину поза скоупом користувача (STW-02)', () => {
    auth.login({ email: 'operator@silpo.ua', password: 'x' }).subscribe();
    auth.selectStore('чужий');
    expect(auth.selectedStore()?.storeId).toBe('s-1');
  });

  it('refresh без збереженого токена завершується помилкою', (done) => {
    auth.refresh().subscribe({
      error: (error: unknown) => {
        expect((error as AppError).code).toBe('AUTH_TOKEN_INVALID');
        done();
      },
    });
  });

  it('refresh зберігає нову пару токенів і оновлений профіль', () => {
    auth.login({ email: 'operator@silpo.ua', password: 'x' }).subscribe();
    auth.refresh().subscribe();
    expect(localStorage.getItem('yms.store.tokens')).toContain('a2');
    expect(localStorage.getItem('yms.store.tokens')).toContain('r2');
  });

  it('logout очищає сесію', () => {
    auth.login({ email: 'operator@silpo.ua', password: 'x' }).subscribe();
    auth.logout();
    expect(auth.isAuthenticated()).toBe(false);
    expect(localStorage.getItem('yms.store.tokens')).toBeNull();
  });

  it('передає помилку логіна далі', (done) => {
    gateway.failLogin = true;
    auth.login({ email: 'x@y.z', password: 'bad' }).subscribe({
      error: (error: unknown) => {
        expect((error as AppError).code).toBe('AUTH_INVALID_CREDENTIALS');
        expect(auth.isAuthenticated()).toBe(false);
        done();
      },
    });
  });
});
