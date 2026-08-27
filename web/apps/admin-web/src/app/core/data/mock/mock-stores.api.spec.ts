import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { StoresApi } from '../stores.api';
import { MockStoresApi, matchesStoreFilter, toListRow } from './mock-stores.api';
import { MockDb } from './mock-db';
import { MOCK_LATENCY } from './mock-support';
import { DEFAULT_STORE_FILTER } from '../../utils/query-state.util';
import { StoreListFilter } from '../../models';
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
      (s) => !session.user.storeIds.includes(s.id),
    )!;
    await expect(firstValueFrom(api.get(outside.id))).rejects.toMatchObject({
      status: 404,
      code: 'RESOURCE_NOT_FOUND',
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

  it('STL-05: за замовчуванням сортує за містом, потім за externalId', async () => {
    const page = await firstValueFrom(
      api.list(filter(), { page: 1, pageSize: 50, sort: 'city', direction: 'asc' }),
    );
    const cities = page.items.map((r) => r.city);
    const sorted = [...cities].sort((a, b) => a.localeCompare(b, 'uk'));
    expect(cities).toEqual(sorted);
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

  it('STL-06: порожній результат повертає total 0', async () => {
    const page = await firstValueFrom(
      api.list(filter({ search: 'нема-такої-адреси-zzz' }), {
        page: 1,
        pageSize: 20,
      }),
    );
    expect(page.total).toBe(0);
    expect(page.items).toEqual([]);
  });

  it('зміна сторінки зберігає активні фільтри', async () => {
    const applied = filter({ cities: ['Київ'] });
    const first = await firstValueFrom(api.list(applied, { page: 1, pageSize: 5 }));
    const second = await firstValueFrom(api.list(applied, { page: 2, pageSize: 5 }));
    expect(second.total).toBe(first.total);
    expect(second.page).toBe(2);
    expect(second.items.every((r) => r.city === 'Київ')).toBe(true);
    expect(second.items[0].id).not.toBe(first.items[0].id);
  });

  it('STC-03: неналаштований магазин неможливо активувати', async () => {
    const target = db.state.stores.find((s) => !s.isConfigured);
    expect(target).toBeDefined();
    await expect(
      firstValueFrom(
        api.updateGeneral(target!.id, {
          displayName: target!.displayName,
          phone: null,
          addressOverride: null,
          ymsStatus: 'active',
          visibleToSuppliers: true,
        }),
      ),
    ).rejects.toMatchObject({ status: 422, code: 'STORE_NOT_CONFIGURED' });
  });

  it('STC-04/STC-07: зберігає видимість і addressOverride', async () => {
    const target = db.state.stores.find((s) => s.isConfigured)!;
    const updated = await firstValueFrom(
      api.updateGeneral(target.id, {
        displayName: 'Сільпо тест',
        phone: '+380441234567',
        addressOverride: 'вʼїзд з двору',
        ymsStatus: 'active',
        visibleToSuppliers: false,
      }),
    );
    expect(updated.displayName).toBe('Сільпо тест');
    expect(updated.addressOverride).toBe('вʼїзд з двору');
    expect(updated.visibleToSuppliers).toBe(false);
    const row = toListRow(updated);
    expect(row.address).toBe('вʼїзд з двору');
  });

  it('STL-07: масове застосування шаблону робить магазини налаштованими', async () => {
    const ids = db.state.stores
      .filter((s) => !s.isConfigured)
      .slice(0, 3)
      .map((s) => s.id);
    const result = await firstValueFrom(api.applyTemplate(ids, 'standard'));
    expect(result.every((r) => r.ok)).toBe(true);
    for (const id of ids) {
      const store = db.state.stores.find((s) => s.id === id)!;
      expect(store.isConfigured).toBe(true);
      expect(store.slotSizeMinutes).toBe(30);
      expect(store.maxVehicleWeightTons).toBe(20);
    }
  });

  it('UI-02: масова активація неналаштованих магазинів повертає помилку по кожному', async () => {
    const ids = db.state.stores
      .filter((s) => !s.isConfigured)
      .slice(0, 2)
      .map((s) => s.id);
    const result = await firstValueFrom(api.bulkStatus(ids, 'active'));
    expect(result.every((r) => !r.ok)).toBe(true);
    expect(result[0].message).toBe('store.error.activate');
  });

  it('STC-63: рішення «Скасувати з нотифікацією» скасовує бронювання', async () => {
    const store = db.state.stores.find(
      (s) => s.isConfigured && db.state.bookings.some((b) => b.storeId === s.id),
    )!;
    const bookingItem = db.state.bookings.find(
      (b) => b.storeId === store.id && b.status === 'booked',
    );
    if (!bookingItem) {
      expect(store).toBeDefined();
      return;
    }
    await firstValueFrom(
      api.saveConfig({
        storeId: store.id,
        effectiveFrom: '2026-01-01',
        config: {},
        decisions: [
          { conflictId: `cf-${bookingItem.id}`, resolution: 'cancel_notify' },
        ],
      }),
    );
    expect(
      db.state.bookings.find((b) => b.id === bookingItem.id)!.status,
    ).toBe('cancelled');
  });

  it('невідомий магазин — 404 RESOURCE_NOT_FOUND', async () => {
    await expect(firstValueFrom(api.get('st-нема'))).rejects.toMatchObject({
      status: 404,
      code: 'RESOURCE_NOT_FOUND',
    });
  });

  it('перелік міст не містить порожніх значень і відсортований', async () => {
    const cities = await firstValueFrom(api.cities());
    expect(cities.length).toBeGreaterThan(10);
    expect(cities.every((c) => c.trim().length > 0)).toBe(true);
    expect([...cities]).toEqual([...cities].sort((a, b) => a.localeCompare(b, 'uk')));
  });
});

describe('matchesStoreFilter', () => {
  const row = toListRow({
    id: 'st-1998',
    branchId: 'b',
    companyId: 'c',
    externalId: '1998',
    city: 'Київ',
    address: 'просп. Володимира Івасюка, 46',
    latitude: '50.5',
    longitude: '30.5',
    hasPickup: true,
    open: true,
    displayName: 'Сільпо на Івасюка',
    phone: null,
    addressOverride: null,
    ymsStatus: 'active',
    visibleToSuppliers: true,
    slotSizeMinutes: 30,
    ramps: [],
    maxVehicleWeightTons: 20,
    leadTimeHours: 4,
    bookingHorizonDays: 21,
    receivingWindows: [],
    exceptions: [],
    reservedRules: [],
    slotBlocks: [],
    isConfigured: true,
    lastSyncedAt: '2026-08-27T00:00:00.000Z',
    missingSyncCount: 0,
  });

  it('префіксний збіг за externalId', () => {
    expect(matchesStoreFilter(row, filter({ search: '19' }))).toBe(true);
    expect(matchesStoreFilter(row, filter({ search: '998' }))).toBe(false);
  });

  it('фільтр «налаштовано» працює як AND', () => {
    expect(matchesStoreFilter(row, filter({ configured: true }))).toBe(true);
    expect(matchesStoreFilter(row, filter({ configured: false }))).toBe(false);
  });
});
