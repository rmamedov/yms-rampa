import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { AnalyticsApi } from '../analytics.api';
import {
  KPI_TARGETS,
  MockAnalyticsApi,
  NO_DATA_MESSAGE,
  describeFilter,
  matchesAnalyticsFilter,
} from './mock-analytics.api';
import { MockDb } from './mock-db';
import { MOCK_LATENCY } from './mock-support';
import { AnalyticsFilter } from '../../models';
import { AuthApi } from '../auth.api';
import { MockAuthApi } from './mock-auth.api';
import { AuthService } from '../../auth/auth.service';
import { addDays, kyivDate } from '../../utils/time.util';

function filter(overrides: Partial<AnalyticsFilter> = {}): AnalyticsFilter {
  return {
    from: addDays(kyivDate(), -29),
    to: addDays(kyivDate(), 29),
    cities: [],
    storeIds: [],
    supplierIds: [],
    ...overrides,
  };
}

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

  it('ANL-14: відповідь несе мітку recalculatedAt і рядок фільтрів', async () => {
    const result = await firstValueFrom(api.kpi(filter()));
    expect(result.recalculatedAt).toBeTruthy();
    expect(result.filters).toContain('період:');
  });

  it('KPI-01…KPI-04 віддаються у формі KpiSummary бекенду', async () => {
    const result = await firstValueFrom(api.kpi(filter()));
    expect(result.kpi.kpi01_rampUtilization).toEqual(
      expect.objectContaining({
        bookedMinutes: expect.any(Number),
        availableMinutes: expect.any(Number),
        utilizationPercent: expect.any(Number),
        slotsCounted: expect.any(Number),
      }),
    );
    expect(result.kpi.kpi02_onTimeDelivery.onTimePercent).toBeGreaterThanOrEqual(0);
    expect(result.kpi.kpi03_waitingTime.sampleSize).toBeGreaterThanOrEqual(0);
    expect(result.kpi.kpi04_noShowRate.totalCount).toBeGreaterThanOrEqual(0);
    expect(result.kpi.anl04_unloadingTime.averageSlotMinutes).toBeGreaterThanOrEqual(0);
    expect(result.kpi.targets).toEqual(KPI_TARGETS);
  });

  it('ANL-10: фільтр за містом звужує вибірку', async () => {
    const city = db.state.bookings[0].city;
    const result = await firstValueFrom(api.breakdown(filter({ cities: [city] }), 'city'));
    expect(result.rows.every((r) => r.key === city)).toBe(true);
  });

  it('ANL-13: період без даних дає empty і текст бекенду, а не помилку', async () => {
    const result = await firstValueFrom(
      api.kpi(filter({ from: '2020-01-01', to: '2020-01-02' })),
    );
    expect(result.empty).toBe(true);
    expect(result.message).toBe(NO_DATA_MESSAGE);
    expect(result.kpi.counters.total).toBe(0);
  });

  it('ANL-10: період обовʼязковий — інакше 422 ANALYTICS_INVALID_PERIOD', async () => {
    await expect(
      firstValueFrom(api.kpi(filter({ from: '', to: '' }))),
    ).rejects.toMatchObject({ status: 422, code: 'ANALYTICS_INVALID_PERIOD' });

    await expect(
      firstValueFrom(api.kpi(filter({ from: '2026-08-27', to: '2026-08-01' }))),
    ).rejects.toMatchObject({ status: 422, code: 'ANALYTICS_INVALID_PERIOD' });
  });

  it('задовгий період відхиляється ANALYTICS_PERIOD_TOO_LONG', async () => {
    await expect(
      firstValueFrom(api.kpi(filter({ from: '2024-01-01', to: '2026-01-01' }))),
    ).rejects.toMatchObject({ status: 422, code: 'ANALYTICS_PERIOD_TOO_LONG' });
  });

  it('ANL-12: для store_manager дані обмежені його магазинами', async () => {
    auth.logout();
    const session = await firstValueFrom(
      auth.login('store.manager@silpo.ua', 'demo'),
    );
    const result = await firstValueFrom(api.breakdown(filter(), 'store'));
    expect(
      result.rows.every((r) => session.user.storeIds.includes(r.key)),
    ).toBe(true);
  });

  it('ANL-12: store_manager не може розширити скоуп через фільтр', async () => {
    auth.logout();
    const session = await firstValueFrom(
      auth.login('store.manager@silpo.ua', 'demo'),
    );
    const outside = db.state.stores.find(
      (s) => !session.user.storeIds.includes(s.card.id),
    )!;
    const result = await firstValueFrom(
      api.breakdown(filter({ storeIds: [outside.card.id] }), 'store'),
    );
    expect(result.rows).toEqual([]);
    expect(result.empty).toBe(true);
  });

  it('ANL-11: CSV починається рядком фільтрів', async () => {
    const csv = await firstValueFrom(api.exportCsv(filter(), 'bookings', 'store'));
    expect(csv.split('\n')[0]).toContain('Фільтри:');
    expect(csv.split('\n')[1]).toContain('bookingId');
  });
});

describe('matchesAnalyticsFilter', () => {
  const db = new MockDb();
  const fact = db.state.bookings[0];

  it('відсікає бронювання поза періодом', () => {
    expect(
      matchesAnalyticsFilter(fact, filter({ from: '2020-01-01', to: '2020-01-02' })),
    ).toBe(false);
  });

  it('відсікає за містом і постачальником', () => {
    expect(matchesAnalyticsFilter(fact, filter({ cities: ['Ніде'] }))).toBe(false);
    expect(matchesAnalyticsFilter(fact, filter({ supplierIds: ['sup-нема'] }))).toBe(
      false,
    );
    expect(
      matchesAnalyticsFilter(
        fact,
        filter({ cities: [fact.city], supplierIds: [fact.supplierId] }),
      ),
    ).toBe(true);
  });

  it('describeFilter повторює AnalyticsQuery::describe()', () => {
    expect(describeFilter(filter({ cities: ['Київ', 'Львів'] }))).toContain(
      'міста: Київ|Львів',
    );
  });
});
