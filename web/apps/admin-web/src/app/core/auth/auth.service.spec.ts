import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { AuthService } from './auth.service';
import { AuthApi } from '../data/auth.api';
import { MockAuthApi } from '../data/mock/mock-auth.api';
import { MOCK_LATENCY } from '../data/mock/mock-support';
import { ApiError } from '../http/problem';
import { AuthSession } from '../models';

describe('AuthService', () => {
  let auth: AuthService;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    auth = TestBed.inject(AuthService);
  });

  afterEach(() => localStorage.clear());

  it('пускає super_admin і зберігає сесію в localStorage', async () => {
    const session = await firstValueFrom(
      auth.login('super.admin@silpo.ua', 'demo'),
    );
    expect(session.user.role).toBe('super_admin');
    expect(auth.isAuthenticated()).toBe(true);
    expect(auth.accessToken()).toContain('mock.access.su-1');
    expect(localStorage.getItem('yms.admin.session')).toContain('super_admin');
  });

  it('ADM-01: store_operator не має доступу до admin-web', async () => {
    await expect(
      firstValueFrom(auth.login('store.operator@silpo.ua', 'demo')),
    ).rejects.toBeInstanceOf(ApiError);
    expect(auth.isAuthenticated()).toBe(false);
  });

  it('деактивований користувач не входить', async () => {
    await expect(
      firstValueFrom(auth.login('alina.tereshchenko@silpo.ua', 'demo')),
    ).rejects.toMatchObject({ status: 403 });
  });

  it('невідомий e-mail — 401', async () => {
    await expect(
      firstValueFrom(auth.login('nobody@silpo.ua', 'demo')),
    ).rejects.toMatchObject({ status: 401 });
  });

  it('ADM-02: розділи видно за роллю', async () => {
    await firstValueFrom(auth.login('analyst@silpo.ua', 'demo'));
    expect(auth.canSee('analytics')).toBe(true);
    expect(auth.canSee('stores')).toBe(true);
    expect(auth.canSee('staff')).toBe(false);
    expect(auth.canSee('sync')).toBe(false);
    expect(auth.canSee('audit')).toBe(false);
    expect(auth.canConfigureStores()).toBe(false);
  });

  it('network_manager бачить магазини, постачальників, синхронізацію та аналітику', async () => {
    await firstValueFrom(auth.login('network.manager@silpo.ua', 'demo'));
    expect(auth.canSee('stores')).toBe(true);
    expect(auth.canSee('suppliers')).toBe(true);
    expect(auth.canSee('sync')).toBe(true);
    expect(auth.canSee('analytics')).toBe(true);
    expect(auth.canConfigureStores()).toBe(true);
  });

  it('RBAC-13: store_manager блокує слоти лише у своїх магазинах', async () => {
    const session = await firstValueFrom(
      auth.login('store.manager@silpo.ua', 'demo'),
    );
    const own = session.user.storeIds[0];
    expect(own).toBeDefined();
    expect(auth.canBlockSlots(own)).toBe(true);
    expect(auth.canBlockSlots('st-not-mine')).toBe(false);
    expect(auth.canReadStore(own)).toBe(true);
    expect(auth.canReadStore('st-not-mine')).toBe(false);
    expect(auth.canConfigureStores()).toBe(false);
  });

  it('оновлює токени через refresh і зберігає користувача', async () => {
    await firstValueFrom(auth.login('super.admin@silpo.ua', 'demo'));
    const before = auth.accessToken();
    const tokens = await firstValueFrom(auth.refresh());
    expect(tokens.accessToken).toBeDefined();
    expect(auth.accessToken()).toBe(tokens.accessToken);
    expect(auth.user()?.email).toBe('super.admin@silpo.ua');
    expect(typeof before).toBe('string');
  });

  it('refresh без токена завершує помилкою AUTH_TOKEN_INVALID', async () => {
    await expect(firstValueFrom(auth.refresh())).rejects.toMatchObject({
      status: 401,
    });
  });

  it('logout очищає сесію і localStorage', async () => {
    await firstValueFrom(auth.login('super.admin@silpo.ua', 'demo'));
    auth.logout();
    expect(auth.isAuthenticated()).toBe(false);
    expect(auth.user()).toBeNull();
    expect(localStorage.getItem('yms.admin.session')).toBeNull();
  });

  it('відновлює сесію з localStorage при створенні сервісу', () => {
    const session: AuthSession = {
      user: {
        id: 'su-2',
        fullName: 'Оксана Лисенко',
        email: 'network.manager@silpo.ua',
        role: 'network_manager',
        storeIds: [],
      },
      tokens: {
        accessToken: 'a',
        refreshToken: 'r',
        expiresAt: new Date().toISOString(),
      },
    };
    localStorage.setItem('yms.admin.session', JSON.stringify(session));
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    const restored = TestBed.inject(AuthService);
    expect(restored.isAuthenticated()).toBe(true);
    expect(restored.role()).toBe('network_manager');
  });
});
