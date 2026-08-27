import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { AnalyticsApi } from '../analytics.api';
import { MockAnalyticsApi, matchesAnalyticsFilter } from './mock-analytics.api';
import { MockDb } from './mock-db';
import { MOCK_LATENCY } from './mock-support';
import { AuthApi } from '../auth.api';
import { MockAuthApi } from './mock-auth.api';
import { AuthService } from '../../auth/auth.service';
import { AnalyticsFilter, Booking, Store } from '../../models';
import { addDays, kyivDate } from '../../utils/time.util';

const WIDE: AnalyticsFilter = {
  from: addDays(kyivDate(), -60),
  to: addDays(kyivDate(), 60),
  cities: [],
  storeIds: [],
  supplierIds: [],
};

describe('MockAnalyticsApi — дашборди (5.7)', () => {
  let api: AnalyticsApi;
  let db: MockDb;
  let auth: AuthService;

  beforeEach(async () => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        MockDb,
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: AnalyticsApi, useClass: MockAnalyticsApi },
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    api = TestBed.inject(AnalyticsApi);
    db = TestBed.inject(MockDb);
    auth = TestBed.inject(AuthService);
    db.reset();
    await firstValueFrom(auth.login('super.admin@silpo.ua', 'demo'));
  });

  afterEach(() => localStorage.clear());

  it('ANL-14: дашборд повертає мітку recalculatedAt', async () => {
    const data = await firstValueFrom(api.dashboard(WIDE));
    expect(new Date(data.recalculatedAt).getTime()).toBeLessThanOrEqual(Date.now());
    expect(Date.now() - new Date(data.recalculatedAt).getTime()).toBeLessThan(60_000);
  });

  it('ANL-01/02: утилізація і поставки рахуються по всій мережі', async () => {
    const data = await firstValueFrom(api.dashboard(WIDE));
    expect(data.utilization.length).toBeGreaterThan(0);
    expect(data.deliveries.length).toBeGreaterThan(0);
    expect(data.utilization.every((r) => r.utilization >= 0 && r.utilization <= 1)).toBe(
      true,
    );
  });

  it('ANL-10: фільтр за містом застосовується до всіх віджетів', async () => {
    const data = await firstValueFrom(api.dashboard({ ...WIDE, cities: ['Київ'] }));
    expect(data.utilization.every((r) => r.city === 'Київ')).toBe(true);
  });

  it('ANL-13: період без даних дає порожні набори, а не помилку', async () => {
    const data = await firstValueFrom(
      api.dashboard({ ...WIDE, from: '2020-01-01', to: '2020-01-02' }),
    );
    expect(data.utilization).toEqual([]);
    expect(data.deliveries).toEqual([]);
    expect(data.noShow).toEqual([]);
  });

  it('ANL-12: для store_manager дані обмежені його магазинами', async () => {
    auth.logout();
    const session = await firstValueFrom(
      auth.login('store.manager@silpo.ua', 'demo'),
    );
    const data = await firstValueFrom(api.dashboard(WIDE));
    expect(
      data.utilization.every((r) => session.user.storeIds.includes(r.storeId)),
    ).toBe(true);
  });

  it('ANL-12: store_manager не може розширити скоуп через фільтр', async () => {
    auth.logout();
    const session = await firstValueFrom(
      auth.login('store.manager@silpo.ua', 'demo'),
    );
    const outside = db.state.stores.find(
      (s) => !session.user.storeIds.includes(s.id),
    )!;
    const data = await firstValueFrom(
      api.dashboard({ ...WIDE, storeIds: [outside.id] }),
    );
    expect(data.utilization).toEqual([]);
  });
});

describe('matchesAnalyticsFilter', () => {
  const store = { id: 'st-1', city: 'Київ' } as Store;
  const booking = {
    storeId: 'st-1',
    supplierId: 'sup-1',
    date: '2026-08-15',
  } as Booking;

  it('відсікає бронювання поза періодом', () => {
    expect(
      matchesAnalyticsFilter(booking, store, {
        ...WIDE,
        from: '2026-08-01',
        to: '2026-08-31',
      }),
    ).toBe(true);
    expect(
      matchesAnalyticsFilter(booking, store, {
        ...WIDE,
        from: '2026-08-16',
        to: '2026-08-31',
      }),
    ).toBe(false);
  });

  it('відсікає за містом і постачальником', () => {
    expect(
      matchesAnalyticsFilter(booking, store, { ...WIDE, cities: ['Львів'] }),
    ).toBe(false);
    expect(
      matchesAnalyticsFilter(booking, store, { ...WIDE, supplierIds: ['sup-9'] }),
    ).toBe(false);
  });

  it('невідомий магазин не потрапляє у вибірку', () => {
    expect(matchesAnalyticsFilter(booking, undefined, WIDE)).toBe(false);
  });
});
