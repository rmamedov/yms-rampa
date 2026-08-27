import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { SuppliersApi } from '../suppliers.api';
import { MockSuppliersApi, matchesSupplierFilter } from './mock-suppliers.api';
import { MockDb } from './mock-db';
import { MOCK_LATENCY } from './mock-support';

describe('MockSuppliersApi — постачальники (5.4)', () => {
  let api: SuppliersApi;
  let db: MockDb;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        MockDb,
        { provide: SuppliersApi, useClass: MockSuppliersApi },
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    api = TestBed.inject(SuppliersApi);
    db = TestBed.inject(MockDb);
    db.reset();
  });

  it('фільтрує за статусом і шукає за назвою та ЄДРПОУ', async () => {
    const suspended = await firstValueFrom(
      api.list({ search: '', statuses: ['suspended'] }, { page: 1, pageSize: 50 }),
    );
    expect(suspended.total).toBeGreaterThan(0);
    expect(suspended.items.every((s) => s.status === 'suspended')).toBe(true);

    const byName = await firstValueFrom(
      api.list({ search: 'молочний', statuses: [] }, { page: 1, pageSize: 50 }),
    );
    expect(byName.items[0].name).toContain('Молочний');
  });

  it('SUP-01: назва унікальна', async () => {
    const existing = db.state.suppliers[0];
    await expect(
      firstValueFrom(
        api.save({
          name: existing.name,
          edrpou: '11112222',
          contactPerson: 'Тест',
          contactPhone: '+380671110000',
          contactEmail: 'test@x.ua',
          status: 'active',
          storeAccessMode: 'all',
          allowedStoreIds: [],
        }),
      ),
    ).rejects.toMatchObject({ status: 422 });
  });

  it('SUP-01: код ЄДРПОУ унікальний', async () => {
    const existing = db.state.suppliers[0];
    await expect(
      firstValueFrom(
        api.save({
          name: 'ТОВ «Абсолютно Нове»',
          edrpou: existing.edrpou,
          contactPerson: 'Тест',
          contactPhone: '+380671110000',
          contactEmail: 'test@x.ua',
          status: 'active',
          storeAccessMode: 'all',
          allowedStoreIds: [],
        }),
      ),
    ).rejects.toMatchObject({ status: 422 });
  });

  it('створює нового постачальника', async () => {
    const created = await firstValueFrom(
      api.save({
        name: 'ТОВ «Нові Поставки»',
        edrpou: '99887766',
        contactPerson: 'Тест Тестенко',
        contactPhone: '+380671110000',
        contactEmail: 'new@x.ua',
        status: 'active',
        storeAccessMode: 'whitelist',
        allowedStoreIds: ['st-1998'],
      }),
    );
    expect(created.id).toBeDefined();
    expect(created.bookingsCount).toBe(0);
    expect(created.allowedStoreIds).toEqual(['st-1998']);
  });

  it('SUP-06: постачальника з історією бронювань видалити не можна', async () => {
    const withBookings = db.state.suppliers.find((s) => s.bookingsCount > 0)!;
    await expect(firstValueFrom(api.remove(withBookings.id))).rejects.toMatchObject({
      status: 409,
    });
    expect(db.state.suppliers.some((s) => s.id === withBookings.id)).toBe(true);
  });

  it('постачальника без бронювань можна видалити', async () => {
    const created = await firstValueFrom(
      api.save({
        name: 'ТОВ «Тимчасовий»',
        edrpou: '55554444',
        contactPerson: 'Тест',
        contactPhone: '+380671110000',
        contactEmail: 'tmp@x.ua',
        status: 'active',
        storeAccessMode: 'all',
        allowedStoreIds: [],
      }),
    );
    await firstValueFrom(api.remove(created.id));
    expect(db.state.suppliers.some((s) => s.id === created.id)).toBe(false);
  });

  it('SUP-02: масова зміна статусу переводить у suspended', async () => {
    const ids = db.state.suppliers.slice(0, 3).map((s) => s.id);
    const result = await firstValueFrom(api.bulkStatus(ids, 'suspended'));
    expect(result.every((r) => r.ok)).toBe(true);
    expect(
      db.state.suppliers
        .filter((s) => ids.includes(s.id))
        .every((s) => s.status === 'suspended'),
    ).toBe(true);
  });

  it('SUP-05: машини й водії шукаються за номером і телефоном', async () => {
    const supplier = db.state.suppliers[0];
    const all = await firstValueFrom(api.vehicles(supplier.id, ''));
    expect(all.length).toBeGreaterThan(0);
    const found = await firstValueFrom(api.vehicles(supplier.id, all[0].plate));
    expect(found).toHaveLength(1);

    const drivers = await firstValueFrom(api.drivers(supplier.id, ''));
    expect(drivers.length).toBeGreaterThan(0);
    const byPhone = await firstValueFrom(api.drivers(supplier.id, drivers[0].phone));
    expect(byPhone[0].id).toBe(drivers[0].id);
  });

  it('SUP-04: користувачі постачальника мають ролі supplier_admin / supplier_operator', async () => {
    const supplier = db.state.suppliers[0];
    const users = await firstValueFrom(api.users(supplier.id));
    expect(users.length).toBeGreaterThan(0);
    expect(
      users.every(
        (u) => u.role === 'supplier_admin' || u.role === 'supplier_operator',
      ),
    ).toBe(true);
  });

  it('matchesSupplierFilter шукає за префіксом ЄДРПОУ', () => {
    const supplier = db.state.suppliers[0];
    expect(
      matchesSupplierFilter(supplier, {
        search: supplier.edrpou.slice(0, 4),
        statuses: [],
      }),
    ).toBe(true);
    expect(
      matchesSupplierFilter(supplier, { search: 'zzzz', statuses: [] }),
    ).toBe(false);
  });
});
