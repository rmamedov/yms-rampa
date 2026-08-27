import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { StaffApi } from '../staff.api';
import { MockStaffApi, matchesStaffFilter } from './mock-staff.api';
import { MockDb } from './mock-db';
import { MOCK_LATENCY } from './mock-support';
import { StaffUser } from '../../models';

describe('MockStaffApi — користувачі staff (5.5)', () => {
  let api: StaffApi;
  let db: MockDb;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        MockDb,
        { provide: StaffApi, useClass: MockStaffApi },
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    api = TestBed.inject(StaffApi);
    db = TestBed.inject(MockDb);
    db.reset();
  });

  it('віддає перелік із серверною пагінацією', async () => {
    const page = await firstValueFrom(
      api.list({ search: '', roles: [], active: null }, { page: 1, pageSize: 20 }),
    );
    expect(page.total).toBe(db.state.staff.length);
    expect(page.items.length).toBe(db.state.staff.length);
  });

  it('фільтрує за роллю', async () => {
    const page = await firstValueFrom(
      api.list(
        { search: '', roles: ['store_manager'], active: null },
        { page: 1, pageSize: 20 },
      ),
    );
    expect(page.items.every((u) => u.role === 'store_manager')).toBe(true);
    expect(page.total).toBeGreaterThan(0);
  });

  it('USR-02: store_manager без магазинів не зберігається', async () => {
    await expect(
      firstValueFrom(
        api.save(
          {
            fullName: 'Новий Керівник',
            email: 'new.manager@silpo.ua',
            phone: '+380671110099',
            role: 'store_manager',
            storeIds: [],
            active: true,
          },
          'su-1',
        ),
      ),
    ).rejects.toMatchObject({ status: 422 });
  });

  it('USR-02: для інших ролей привʼязка магазинів не потрібна', async () => {
    const created = await firstValueFrom(
      api.save(
        {
          fullName: 'Новий Аналітик',
          email: 'new.analyst@silpo.ua',
          phone: '+380671110098',
          role: 'analyst',
          storeIds: [],
          active: true,
        },
        'su-1',
      ),
    );
    expect(created.id).toBeDefined();
    expect(db.state.staff.some((u) => u.email === 'new.analyst@silpo.ua')).toBe(true);
  });

  it('USR-01: e-mail (логін) унікальний', async () => {
    await expect(
      firstValueFrom(
        api.save(
          {
            fullName: 'Дубль',
            email: 'super.admin@silpo.ua',
            phone: '+380671110097',
            role: 'analyst',
            storeIds: [],
            active: true,
          },
          'su-1',
        ),
      ),
    ).rejects.toMatchObject({ status: 422 });
  });

  it('RBAC-24: не можна змінити власну роль', async () => {
    await expect(
      firstValueFrom(
        api.save(
          {
            id: 'su-1',
            fullName: 'Руслан Мамедов',
            email: 'super.admin@silpo.ua',
            phone: '+380671110001',
            role: 'analyst',
            storeIds: [],
            active: true,
          },
          'su-1',
        ),
      ),
    ).rejects.toMatchObject({ code: 'RBAC_SELF_ROLE_CHANGE_FORBIDDEN' });
  });

  it('USR-03: не можна деактивувати самого себе', async () => {
    await expect(
      firstValueFrom(api.setActive('su-1', false, 'su-1')),
    ).rejects.toMatchObject({ status: 403 });
  });

  it('RBAC-25: останнього активного super_admin деактивувати не можна', async () => {
    await expect(
      firstValueFrom(api.setActive('su-1', false, 'su-2')),
    ).rejects.toMatchObject({ status: 409, code: 'RBAC_LAST_SUPER_ADMIN' });
  });

  it('деактивація іншого користувача працює', async () => {
    const updated = await firstValueFrom(api.setActive('su-3', false, 'su-1'));
    expect(updated.active).toBe(false);
    expect(db.state.staff.find((u) => u.id === 'su-3')!.active).toBe(false);
  });
});

describe('matchesStaffFilter', () => {
  const user: StaffUser = {
    id: 'su-9',
    fullName: 'Павло Гончар',
    email: 'pavlo@silpo.ua',
    phone: '+380671110003',
    role: 'store_manager',
    storeIds: ['st-1'],
    active: true,
  };

  it('шукає за ПІБ, e-mail і телефоном', () => {
    expect(matchesStaffFilter(user, { search: 'гончар', roles: [], active: null })).toBe(
      true,
    );
    expect(matchesStaffFilter(user, { search: 'PAVLO', roles: [], active: null })).toBe(
      true,
    );
    expect(
      matchesStaffFilter(user, { search: '+380671110003', roles: [], active: null }),
    ).toBe(true);
    expect(matchesStaffFilter(user, { search: 'немає', roles: [], active: null })).toBe(
      false,
    );
  });

  it('фільтр активності', () => {
    expect(matchesStaffFilter(user, { search: '', roles: [], active: false })).toBe(
      false,
    );
    expect(matchesStaffFilter(user, { search: '', roles: [], active: true })).toBe(true);
  });
});
