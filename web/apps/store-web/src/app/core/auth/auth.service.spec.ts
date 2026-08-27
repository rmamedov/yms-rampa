import { TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import { AuthService } from './auth.service';
import { TokenStorageService } from './token-storage.service';
import { AuthGateway, StoreGateway } from '../data/gateways';
import { toLoginResponse, toStoreScopes } from '../api/wire.mapper';
import {
  WireAuthTokenResponse,
  WireStaffUser,
  WireStoreBrief,
} from '../api/wire.model';
import { LoginRequest, LoginResponse, StoreScope } from '../models/auth.model';
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
  // RBAC-16: скоуп мережевої ролі задає РОЛЬ, перелік ідентифікаторів порожній.
  scope: { storeIds: [], networkWide: true },
});

/** Філія рівно у формі `StoreBrief::toArray()`. */
function store(
  storeId: string,
  externalId: string,
  displayName: string,
): WireStoreBrief {
  return {
    storeId,
    externalId,
    displayName,
    city: 'Київ',
    address: 'просп. Володимира Івасюка, 46',
    ymsStatus: 'active',
  };
}

const SCOPE_STORES: readonly WireStoreBrief[] = [
  store('s-1', '1998', 'Сільпо, просп. Володимира Івасюка, 46'),
  store('s-2', '1999', 'Сільпо, наб. Русанівська, 10'),
];

/** Мережа: усі активні філії, серед них і ті, яких немає в жодному скоупі. */
const ALL_STORES: readonly WireStoreBrief[] = [
  ...SCOPE_STORES,
  store('s-3', '2000', 'Сільпо, вул. Закревського Миколи, 61/2'),
];

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

/**
 * Із контракту магазину сесії потрібен лише перелік філій — решту методів
 * AuthService не торкається, тому підміняємо саме `getStores()`.
 */
class FakeStoreGateway {
  stores: readonly WireStoreBrief[] = SCOPE_STORES;
  calls = 0;
  fail = false;

  getStores(): Observable<readonly StoreScope[]> {
    this.calls += 1;
    if (this.fail) {
      return throwError(
        () => new AppError({ status: 403, code: 'ACCESS_DENIED' }, null),
      );
    }
    return of(toStoreScopes(this.stores));
  }
}

describe('AuthService', () => {
  let gateway: FakeAuthGateway;
  let stores: FakeStoreGateway;
  let auth: AuthService;

  beforeEach(() => {
    localStorage.clear();
    gateway = new FakeAuthGateway();
    stores = new FakeStoreGateway();
    TestBed.configureTestingModule({
      providers: [
        TokenStorageService,
        { provide: AuthGateway, useValue: gateway },
        { provide: StoreGateway, useValue: stores as unknown as StoreGateway },
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

  // -------------------------------------------------------------------------
  // Перелік магазинів: джерело — GET /stores, а не profile.storeIds (STW-03)
  // -------------------------------------------------------------------------

  describe('перелік магазинів', () => {
    function signIn(profile: WireStaffUser): void {
      gateway.profile = profile;
      auth.login({ email: profile.email, password: 'x' }).subscribe();
    }

    it('мережева роль отримує перемикач із маршруту, а не з порожнього профілю', () => {
      stores.stores = ALL_STORES;
      signIn(NETWORK_MANAGER);

      // Профіль мережевої ролі порожній — саме тому перемикач був порожній.
      expect(auth.profile()?.storeIds).toEqual([]);
      expect(auth.stores()).toEqual([]);
      expect(auth.selectedStore()).toBeNull();

      auth.ensureStores().subscribe();

      expect(auth.stores().map((s) => s.storeId)).toEqual([
        's-1',
        's-2',
        's-3',
      ]);
      expect(auth.showStoreSwitcher()).toBe(true);
      expect(auth.selectedStore()?.storeId).toBe('s-1');
    });

    it('перелік беруть цілком — жодних зрізів і першої сторінки', () => {
      const many: WireStoreBrief[] = Array.from({ length: 30 }, (_, i) =>
        store(`s-${i + 1}`, String(2000 + i), `Сільпо №${2000 + i}`),
      );
      stores.stores = many;
      signIn(NETWORK_MANAGER);
      auth.ensureStores().subscribe();

      expect(auth.stores()).toHaveLength(many.length);
      expect(auth.stores().at(-1)?.storeId).toBe('s-30');
    });

    it('підпис філії бере з маршруту: назва, код, місто, адреса', () => {
      stores.stores = SCOPE_STORES;
      signIn(OPERATOR);
      auth.ensureStores().subscribe();

      expect(auth.selectedStore()).toEqual({
        storeId: 's-1',
        displayName: 'Сільпо, просп. Володимира Івасюка, 46',
        externalId: '1998',
        city: 'Київ',
        address: 'просп. Володимира Івасюка, 46',
      });
    });

    it('магазинна роль бачить рівно свої філії', () => {
      stores.stores = SCOPE_STORES;
      signIn(OPERATOR);
      auth.ensureStores().subscribe();

      expect(auth.stores().map((s) => s.storeId)).toEqual(['s-1', 's-2']);
    });

    it('повторні виклики не породжують нового запиту', () => {
      signIn(OPERATOR);
      auth.ensureStores().subscribe();
      auth.ensureStores().subscribe();
      auth.ensureStores().subscribe();
      expect(stores.calls).toBe(1);
    });

    it('підмінений вибір поза переліком відкочується на дозволену філію (STW-02)', () => {
      localStorage.setItem('yms.store.selectedStoreId', 'чужий-магазин');
      stores.stores = ALL_STORES;
      signIn(NETWORK_MANAGER);
      auth.ensureStores().subscribe();

      expect(auth.selectedStore()?.storeId).toBe('s-1');
      expect(localStorage.getItem('yms.store.selectedStoreId')).toBe('s-1');
    });

    // Перезавантаження з підміненим ідентифікатором: перелік філій піднімається
    // з кешу, тож запиту до бекенду не буде взагалі — перевірити збережений
    // вибір нікому, крім самої сесії на старті (STW-02).
    it('підмінений вибір не переживає перезавантаження сторінки (STW-02)', () => {
      stores.stores = ALL_STORES;
      signIn(NETWORK_MANAGER);
      auth.ensureStores().subscribe();
      localStorage.setItem('yms.store.selectedStoreId', 'чужий-магазин');

      const restored = TestBed.runInInjectionContext(() => new AuthService());

      expect(restored.selectedStore()?.storeId).toBe('s-1');
      expect(localStorage.getItem('yms.store.selectedStoreId')).toBe('s-1');
      expect(stores.calls).toBe(1);
    });

    it('перелік переживає перезавантаження сторінки', () => {
      stores.stores = ALL_STORES;
      signIn(NETWORK_MANAGER);
      auth.ensureStores().subscribe();

      // Новий екземпляр = свіжий запуск застосунку з тим самим localStorage.
      const restored = TestBed.runInInjectionContext(() => new AuthService());
      expect(restored.stores().map((s) => s.storeId)).toEqual([
        's-1',
        's-2',
        's-3',
      ]);
    });

    it('вихід забуває перелік філій попередньої сесії', () => {
      stores.stores = ALL_STORES;
      signIn(NETWORK_MANAGER);
      auth.ensureStores().subscribe();
      auth.logout();

      expect(auth.stores()).toEqual([]);
      expect(localStorage.getItem('yms.store.stores')).toBeNull();
    });

    it('помилка переліку не ковтається — екран має показати причину', (done) => {
      stores.fail = true;
      signIn(OPERATOR);
      auth.ensureStores().subscribe({
        error: (error: unknown) => {
          expect((error as AppError).code).toBe('ACCESS_DENIED');
          done();
        },
      });
    });
  });
});
