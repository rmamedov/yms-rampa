import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { StoresApi } from '../stores.api';
import {
  EMPTY_STORES_MESSAGE,
  MockStoresApi,
  matchesStoreFilter,
  toListRow,
} from './mock-stores.api';
import { MockDb } from './mock-db';
import { MOCK_LATENCY } from './mock-support';
import { DEFAULT_STORE_FILTER } from '../../utils/query-state.util';
import { NO_CITY, PageSize, StoreListFilter } from '../../models';
import { AuthApi } from '../auth.api';
import { MockAuthApi } from './mock-auth.api';
import { AuthService } from '../../auth/auth.service';

function filter(overrides: Partial<StoreListFilter> = {}): StoreListFilter {
  return { ...DEFAULT_STORE_FILTER, ...overrides };
}

describe('MockStoresApi — довідник магазинів', () => {
  let api: StoresApi;
  let db: MockDb;
  let auth: AuthService;

  beforeEach(async () => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        MockDb,
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: StoresApi, useClass: MockStoresApi },
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    api = TestBed.inject(StoresApi);
    db = TestBed.inject(MockDb);
    auth = TestBed.inject(AuthService);
    db.reset();
    await firstValueFrom(auth.login('super.admin@silpo.ua', 'demo'));
  });

  afterEach(() => localStorage.clear());

  it('RBAC-13/17: store_manager бачить лише магазини свого скоупа', async () => {
    auth.logout();
    const session = await firstValueFrom(
      auth.login('store.manager@silpo.ua', 'demo'),
    );
    const page = await firstValueFrom(
      api.list(filter(), { page: 1, pageSize: 100 }),
    );
    expect(page.total).toBe(session.user.storeIds.length);
    expect(page.items.map((r) => r.id).sort()).toEqual(
      [...session.user.storeIds].sort(),
    );
  });

  it('RBAC-18: читання магазину поза скоупом — 404, а не 403', async () => {
    auth.logout();
    const session = await firstValueFrom(
      auth.login('store.manager@silpo.ua', 'demo'),
    );
    const outside = db.state.stores.find(
      (s) => !session.user.storeIds.includes(s.card.id),
    )!;
    await expect(firstValueFrom(api.get(outside.card.id))).rejects.toMatchObject({
      status: 404,
      code: 'STORE_NOT_FOUND',
    });
    await expect(
      firstValueFrom(api.get(session.user.storeIds[0])),
    ).resolves.toBeDefined();
  });

  it('віддає сторінку магазинів із фікстур MCP', async () => {
    const page = await firstValueFrom(
      api.list(filter(), { page: 1, pageSize: 20 }),
    );
    expect(page.total).toBeGreaterThan(50);
    expect(page.items).toHaveLength(20);
    expect(page.items[0].externalId).toBeDefined();
  });

  it('UI-01: perPage поза 20/50/100 бекенд відхиляє 422', async () => {
    await expect(
      firstValueFrom(api.list(filter(), { page: 1, pageSize: 25 as PageSize })),
    ).rejects.toMatchObject({ status: 422, code: 'VALIDATION_FAILED' });

    for (const size of [20, 50, 100] as PageSize[]) {
      await expect(
        firstValueFrom(api.list(filter(), { page: 1, pageSize: size })),
      ).resolves.toBeDefined();
    }
  });

  it('STL-05: за замовчуванням сортує за містом, потім за externalId', async () => {
    const page = await firstValueFrom(
      api.list(filter(), { page: 1, pageSize: 50, sort: 'city', direction: 'asc' }),
    );
    const cities = page.items.map((r) => r.city);
    const sorted = [...cities].sort((a, b) => a.localeCompare(b, 'uk'));
    expect(cities).toEqual(sorted);
  });

  it('сортування за колонкою поза переліком бекенду відкочується на місто', async () => {
    const byUnsupported = await firstValueFrom(
      api.list(filter(), { page: 1, pageSize: 20, sort: 'rampCount' }),
    );
    const byCity = await firstValueFrom(
      api.list(filter(), { page: 1, pageSize: 20, sort: 'city' }),
    );
    expect(byUnsupported.items.map((r) => r.id)).toEqual(
      byCity.items.map((r) => r.id),
    );
  });

  it('STL-03: пошук «1998» знаходить філію з таким externalId', async () => {
    const page = await firstValueFrom(
      api.list(filter({ search: '1998' }), { page: 1, pageSize: 20 }),
    );
    expect(page.items.some((r) => r.externalId === '1998')).toBe(true);
  });

  it('STL-03: пошук за адресою — підрядок без урахування регістру', async () => {
    const page = await firstValueFrom(
      api.list(filter({ search: 'бережанська' }), { page: 1, pageSize: 20 }),
    );
    expect(page.total).toBeGreaterThan(0);
    expect(
      page.items.every((r) => r.address.toLowerCase().includes('бережанська')),
    ).toBe(true);
  });

  it('STL-02: фільтри комбінуються за AND (місто + статус)', async () => {
    const page = await firstValueFrom(
      api.list(filter({ cities: ['Київ'], statuses: ['not_configured'] }), {
        page: 1,
        pageSize: 100,
      }),
    );
    expect(page.total).toBeGreaterThan(0);
    for (const row of page.items) {
      expect(row.city).toBe('Київ');
      expect(row.ymsStatus).toBe('not_configured');
      expect(row.isConfigured).toBe(false);
    }
  });

  it('STL-06: порожній результат несе emptyMessage бекенду', async () => {
    const page = await firstValueFrom(
      api.list(filter({ search: 'нема-такої-адреси-zzz' }), {
        page: 1,
        pageSize: 20,
      }),
    );
    expect(page.total).toBe(0);
    expect(page.items).toEqual([]);
    expect(page.emptyMessage).toBe(EMPTY_STORES_MESSAGE);
  });

  it('зміна сторінки зберігає активні фільтри', async () => {
    const applied = filter({ statuses: ['active'] });
    const first = await firstValueFrom(api.list(applied, { page: 1, pageSize: 20 }));
    const second = await firstValueFrom(api.list(applied, { page: 2, pageSize: 20 }));
    expect(first.total).toBeGreaterThan(20);
    expect(second.total).toBe(first.total);
    expect(second.page).toBe(2);
    expect(second.items.every((r) => r.ymsStatus === 'active')).toBe(true);
    expect(second.items[0].id).not.toBe(first.items[0].id);
  });

  it('картка магазину віддає чинну конфігурацію, резерви й блокування', async () => {
    const configured = db.state.stores.find((s) => s.configurations.length > 0)!;
    const store = await firstValueFrom(api.get(configured.card.id));
    expect(store.configuration).not.toBeNull();
    expect(store.configuration!.slotSizeMinutes).toBe(
      configured.configurations[0].slotSizeMinutes,
    );
    expect(store.reservedRules).toEqual(configured.reservedRules);
    expect(store.slotBlocks).toEqual(configured.slotBlocks);
  });

  it('магазин без конфігурації віддає configuration = null', async () => {
    const bare = db.state.stores.find((s) => s.configurations.length === 0)!;
    const store = await firstValueFrom(api.get(bare.card.id));
    expect(store.configuration).toBeNull();
    expect(store.isConfigured).toBe(false);
  });

  it('STC-03: неналаштований магазин неможливо активувати', async () => {
    const target = db.state.stores.find((s) => !s.card.isConfigured)!;
    await expect(
      firstValueFrom(
        api.updateGeneral(target.card.id, {
          displayName: target.card.displayName,
          phone: null,
          addressOverride: null,
          ymsStatus: 'active',
          visibleToSuppliers: true,
        }),
      ),
    ).rejects.toMatchObject({ status: 409, code: 'STORE_NOT_CONFIGURED' });
  });

  it('STC-04/STC-07: зберігає видимість і addressOverride', async () => {
    const target = db.state.stores.find((s) => s.card.isConfigured)!;
    const updated = await firstValueFrom(
      api.updateGeneral(target.card.id, {
        displayName: 'Сільпо тест',
        phone: '+380441234567',
        addressOverride: 'вʼїзд з двору',
        ymsStatus: 'active',
        visibleToSuppliers: false,
      }),
    );
    expect(updated.displayName).toBe('Сільпо тест');
    expect(updated.addressOverride).toBe('вʼїзд з двору');
    expect(updated.effectiveAddress).toBe('вʼїзд з двору');
    expect(updated.visibleToSuppliers).toBe(false);
  });

  it('DATA-09: збереження конфігурації створює НОВУ версію', async () => {
    const target = db.state.stores.find((s) => s.configurations.length > 0)!;
    const before = target.configurations.length;
    const created = await firstValueFrom(
      api.createConfiguration(target.card.id, {
        effectiveFrom: '2026-09-01',
        slotSizeMinutes: 30,
        maxVehicleWeightTons: 18,
        receivingWindows: target.configurations[0].receivingWindows,
        ramps: target.configurations[0].ramps,
        calendarExceptions: [],
        leadTimeMinutes: 120,
        bookingHorizonDays: 21,
        noShowGraceMinutes: 30,
        holdMaxMinutes: 15,
      }),
    );
    expect(created.version).toBe(before + 1);
    expect(target.configurations).toHaveLength(before + 1);
    expect(target.configurations[0].version).toBe(1);
  });

  it('UI-02: масова активація неналаштованих магазинів повертає помилку по кожному', async () => {
    const ids = db.state.stores
      .filter((s) => !s.card.isConfigured)
      .slice(0, 2)
      .map((s) => s.card.id);
    const result = await firstValueFrom(api.bulkStatus(ids, 'active'));
    expect(result.every((r) => !r.ok)).toBe(true);
    expect(result[0].message).toContain('не завершено налаштування');
  });

  it('масова зміна видимості складається з покартковових оновлень', async () => {
    const ids = db.state.stores.slice(0, 3).map((s) => s.card.id);
    const result = await firstValueFrom(api.bulkVisibility(ids, false));
    expect(result.every((r) => r.ok)).toBe(true);
    for (const id of ids) {
      expect(db.store(id)!.card.visibleToSuppliers).toBe(false);
    }
  });

  it('STC-42: резерв на вимкнену рампу відхиляється', async () => {
    const target = db.state.stores.find((s) => s.configurations.length > 0)!;
    const config = target.configurations[0];
    await expect(
      firstValueFrom(
        api.createReservedRule(target.card.id, {
          supplierId: 'sup-1',
          rampId: 'немає-такої-рампи',
          slotStartTime: config.receivingWindows.find((w) => w.intervals.length > 0)!
            .intervals[0].from,
          dayOfWeek: 1,
          date: null,
          validFrom: '2026-09-01',
          validTo: null,
          active: true,
        }),
      ),
    ).rejects.toMatchObject({ status: 422, code: 'CONFIG_VALIDATION_FAILED' });
  });

  it('STC-52: зняте блокування отримує releasedAt і повторно не знімається', async () => {
    const target = db.state.stores.find((s) => s.slotBlocks.length > 0)!;
    const blockId = target.slotBlocks[0].id;
    const released = await firstValueFrom(
      api.releaseSlotBlock(target.card.id, blockId),
    );
    expect(released.releasedAt).not.toBeNull();
    await expect(
      firstValueFrom(api.releaseSlotBlock(target.card.id, blockId)),
    ).rejects.toMatchObject({ status: 422 });
  });

  it('невідомий магазин — 404 STORE_NOT_FOUND', async () => {
    await expect(firstValueFrom(api.get('st-нема'))).rejects.toMatchObject({
      status: 404,
      code: 'STORE_NOT_FOUND',
    });
  });

  it('довідник міст віддає місто і кількість магазинів', async () => {
    const { items } = await firstValueFrom(api.cities());
    expect(items.length).toBeGreaterThan(10);
    expect(items.every((c) => c.city.trim().length > 0)).toBe(true);
    expect(items.every((c) => c.storeCount > 0)).toBe(true);
    expect(items.map((c) => c.city)).toEqual(
      [...items].map((c) => c.city).sort((a, b) => a.localeCompare(b, 'uk')),
    );
  });

  it('довідник міст рахує філії без міста окремо, і їх видно фільтром', async () => {
    const filter = await firstValueFrom(api.cities());
    const all = await firstValueFrom(
      api.list(DEFAULT_STORE_FILTER, { page: 1, pageSize: 100 }),
    );
    const covered = filter.items.reduce((sum, c) => sum + c.storeCount, 0);

    // Кожна філія довідника потрапляє або в місто, або у «без міста».
    expect(covered + filter.withoutCity).toBe(all.total);

    const withoutCity = await firstValueFrom(
      api.list(
        { ...DEFAULT_STORE_FILTER, cities: [NO_CITY] },
        { page: 1, pageSize: 100 },
      ),
    );
    expect(withoutCity.total).toBe(filter.withoutCity);
    expect(withoutCity.items.every((row) => row.city.trim() === '')).toBe(true);
  });
});

describe('matchesStoreFilter', () => {
  const db = new MockDb();
  const row = toListRow(
    db.state.stores.find((s) => s.card.externalId === '1998') ?? db.state.stores[0],
  );

  it('префіксний збіг за externalId', () => {
    expect(
      matchesStoreFilter(row, filter({ search: row.externalId.slice(0, 2) })),
    ).toBe(true);
    expect(matchesStoreFilter(row, filter({ search: 'zzz-нема' }))).toBe(false);
  });

  it('фільтр «налаштовано» працює як AND', () => {
    expect(matchesStoreFilter(row, filter({ configured: row.isConfigured }))).toBe(
      true,
    );
    expect(matchesStoreFilter(row, filter({ configured: !row.isConfigured }))).toBe(
      false,
    );
  });

  it('філія без міста досяжна лише значенням «без міста»', () => {
    const homeless = { ...row, city: '' };

    // Варіант «(без міста)» знаходить її, а місто з довідника — ні.
    expect(matchesStoreFilter(homeless, filter({ cities: [NO_CITY] }))).toBe(true);
    expect(matchesStoreFilter(homeless, filter({ cities: [row.city] }))).toBe(false);
    // І навпаки: філія з містом у «без міста» не потрапляє.
    expect(matchesStoreFilter(row, filter({ cities: [NO_CITY] }))).toBe(false);
    expect(matchesStoreFilter(row, filter({ cities: [row.city] }))).toBe(true);
  });
});
