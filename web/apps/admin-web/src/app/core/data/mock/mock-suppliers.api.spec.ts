import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { SupplierDraft, SuppliersApi } from '../suppliers.api';
import { MockSuppliersApi, matchesSupplierFilter } from './mock-suppliers.api';
import { MockDb } from './mock-db';
import { MOCK_LATENCY } from './mock-support';

function draft(overrides: Partial<SupplierDraft> = {}): SupplierDraft {
  return {
    name: 'ТОВ «Новий Постачальник»',
    edrpou: '12345678',
    allStores: true,
    storeIds: [],
    contacts: [
      { name: 'Ірина Дуб', phone: '+380671234567', email: 'iryna@example.com' },
    ],
    ...overrides,
  };
}

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

  it('фільтрує за ОДНИМ статусом і шукає за назвою та ЄДРПОУ', async () => {
    const suspended = await firstValueFrom(
      api.list({ search: '', status: 'suspended' }, { page: 1, pageSize: 50 }),
    );
    expect(suspended.total).toBeGreaterThan(0);
    expect(suspended.items.every((s) => s.status === 'suspended')).toBe(true);

    const byName = await firstValueFrom(
      api.list({ search: 'молочний', status: null }, { page: 1, pageSize: 50 }),
    );
    expect(byName.items.every((s) => s.name.toLowerCase().includes('молочний'))).toBe(
      true,
    );
  });

  it('list працює на limit/offset — друга сторінка не повторює першу', async () => {
    const first = await firstValueFrom(
      api.list({ search: '', status: null }, { page: 1, pageSize: 20 }),
    );
    const second = await firstValueFrom(
      api.list({ search: '', status: null }, { page: 2, pageSize: 20 }),
    );
    expect(second.total).toBe(first.total);
    expect(second.page).toBe(2);
  });

  it('створює нового постачальника з контактами і доступом до магазинів', async () => {
    const created = await firstValueFrom(
      api.create(draft({ allStores: false, storeIds: ['st-1', 'st-2'] })),
    );
    expect(created.id).toBeDefined();
    expect(created.status).toBe('active');
    expect(created.storeAccess).toEqual({
      allStores: false,
      storeIds: ['st-1', 'st-2'],
    });
    expect(created.contacts[0].name).toBe('Ірина Дуб');
  });

  it('SUP-01: порожня назва відхиляється 422', async () => {
    await expect(
      firstValueFrom(api.create(draft({ name: '   ' }))),
    ).rejects.toMatchObject({ status: 422 });
  });

  it('SUP-02: suspend/activate — окремі маршрути зі статусом і причиною', async () => {
    const active = db.state.suppliers.find((s) => s.status === 'active')!;
    const suspended = await firstValueFrom(
      api.suspend(active.id, 'Прострочена заборгованість'),
    );
    expect(suspended.status).toBe('suspended');
    expect(suspended.suspendReason).toBe('Прострочена заборгованість');
    expect(suspended.suspendedAt).not.toBeNull();

    const back = await firstValueFrom(api.activate(active.id));
    expect(back.status).toBe('active');
    expect(back.suspendedAt).toBeNull();
  });

  it('SUP-06: постачальника з бронюваннями видалити не можна', async () => {
    const withBookings = db.state.bookings[0].supplierId;
    await expect(firstValueFrom(api.remove(withBookings))).rejects.toMatchObject({
      status: 409,
      code: 'SUPPLIER_HAS_BOOKINGS',
    });
  });

  it('постачальника без бронювань можна видалити', async () => {
    const created = await firstValueFrom(api.create(draft()));
    await firstValueFrom(api.remove(created.id));
    expect(db.state.suppliers.some((s) => s.id === created.id)).toBe(false);
  });

  it('масова зміна статусу збирається з suspend/activate по кожному', async () => {
    const ids = db.state.suppliers.slice(0, 3).map((s) => s.id);
    const result = await firstValueFrom(api.bulkStatus(ids, 'suspended'));
    expect(result.every((r) => r.ok)).toBe(true);
    for (const id of ids) {
      expect(db.state.suppliers.find((s) => s.id === id)!.status).toBe('suspended');
    }
  });

  it('невідомий постачальник — 404 SUPPLIER_NOT_FOUND', async () => {
    await expect(firstValueFrom(api.get('sup-нема'))).rejects.toMatchObject({
      status: 404,
      code: 'SUPPLIER_NOT_FOUND',
    });
  });

  it('matchesSupplierFilter шукає за підрядком ЄДРПОУ', () => {
    const supplier = db.state.suppliers[0];
    expect(
      matchesSupplierFilter(supplier, {
        search: supplier.edrpou!.slice(0, 4),
        status: null,
      }),
    ).toBe(true);
    expect(
      matchesSupplierFilter(supplier, { search: '00000000', status: null }),
    ).toBe(false);
  });
});
