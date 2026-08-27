import { TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import { AuthService } from './auth.service';
import { TokenStorageService } from './token-storage.service';
import { AuthGateway } from '../data/gateways';
import {
  AuthTokens,
  LoginRequest,
  LoginResponse,
  StaffProfile,
} from '../models/auth.model';
import { AppError } from '../models/problem.model';

const OPERATOR: StaffProfile = {
  userId: 'u-1',
  fullName: 'Оксана Литвин',
  email: 'operator@silpo.ua',
  role: 'store_operator',
  stores: [
    {
      storeId: 's-1',
      externalId: '1998',
      displayName: 'Сільпо №1998',
      city: 'Київ',
      address: 'просп. Володимира Івасюка, 46',
    },
    {
      storeId: 's-2',
      externalId: '2025',
      displayName: 'Сільпо №2025',
      city: 'Київ',
      address: 'вул. Бережанська, 22',
    },
  ],
};

const ADMIN: StaffProfile = {
  userId: 'u-2',
  fullName: 'Тарас Гнатюк',
  email: 'admin@silpo.ua',
  role: 'admin',
  stores: [],
};

class FakeAuthGateway extends AuthGateway {
  profile: StaffProfile = OPERATOR;
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
    return of({
      tokens: {
        accessToken: 'a1',
        refreshToken: 'r1',
        expiresAt: Date.now() + 60_000,
      },
      profile: this.profile,
    });
  }

  override refresh(): Observable<AuthTokens> {
    return of({
      accessToken: 'a2',
      refreshToken: 'r2',
      expiresAt: Date.now() + 60_000,
    });
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
    expect(localStorage.getItem('yms.store.tokens')).toContain('a1');
  });

  it('роль поза контуром магазину не отримує доступу (STW-01)', () => {
    gateway.profile = ADMIN;
    auth.login({ email: 'admin@silpo.ua', password: 'x' }).subscribe();
    expect(auth.isAuthenticated()).toBe(true);
    expect(auth.hasStoreAccess()).toBe(false);
  });

  it('перемикач магазину зʼявляється лише за 2+ магазинів (STW-03)', () => {
    auth.login({ email: 'operator@silpo.ua', password: 'x' }).subscribe();
    expect(auth.showStoreSwitcher()).toBe(true);
    expect(auth.selectedStore()?.storeId).toBe('s-1');

    gateway.profile = { ...OPERATOR, stores: [OPERATOR.stores[0]] };
    auth.logout();
    auth.login({ email: 'operator@silpo.ua', password: 'x' }).subscribe();
    expect(auth.showStoreSwitcher()).toBe(false);
    expect(auth.selectedStore()?.storeId).toBe('s-1');
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
