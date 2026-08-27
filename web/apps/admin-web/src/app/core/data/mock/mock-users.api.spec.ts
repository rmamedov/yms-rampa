import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { StaffUserDraft, UsersApi } from '../users.api';
import { MockUsersApi, matchesUserFilter, toStaffUser } from './mock-users.api';
import { MockDb } from './mock-db';
import { MOCK_LATENCY } from './mock-support';
import { AuthApi } from '../auth.api';
import { MockAuthApi } from './mock-auth.api';
import { AuthService } from '../../auth/auth.service';

function draft(overrides: Partial<StaffUserDraft> = {}): StaffUserDraft {
  return {
    email: 'nova.osoba@silpo.ua',
    fullName: 'Нова Особа',
    role: 'store_operator',
    storeIds: [],
    password: null,
    ...overrides,
  };
}

describe('MockUsersApi — користувачі (розділ 4.7)', () => {
  let api: UsersApi;
  let db: MockDb;
  let auth: AuthService;

  beforeEach(async () => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        MockDb,
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: UsersApi, useClass: MockUsersApi },
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    api = TestBed.inject(UsersApi);
    db = TestBed.inject(MockDb);
    auth = TestBed.inject(AuthService);
    db.reset();
    await firstValueFrom(auth.login('super.admin@silpo.ua', 'demo'));
  });

  afterEach(() => localStorage.clear());

  it('фільтрує за роллю, статусом і шукає за e-mail та імʼям', async () => {
    const managers = await firstValueFrom(
      api.list(
        { search: '', role: 'store_manager', status: '' },
        { page: 1, pageSize: 50 },
      ),
    );
    expect(managers.total).toBeGreaterThan(0);
    expect(managers.items.every((u) => u.role === 'store_manager')).toBe(true);

    const inactive = await firstValueFrom(
      api.list({ search: '', role: '', status: 'inactive' }, { page: 1, pageSize: 50 }),
    );
    expect(inactive.total).toBe(1);
    expect(inactive.items[0].active).toBe(false);

    const byEmail = await firstValueFrom(
      api.list({ search: 'analyst@', role: '', status: '' }, { page: 1, pageSize: 50 }),
    );
    expect(byEmail.items.map((u) => u.role)).toEqual(['analyst']);

    const byName = await firstValueFrom(
      api.list({ search: 'Оксана', role: '', status: '' }, { page: 1, pageSize: 50 }),
    );
    expect(byName.total).toBe(1);
  });

  it('UI-01: приймає лише 20/50/100 рядків на сторінці', async () => {
    for (const pageSize of [20, 50, 100] as const) {
      const page = await firstValueFrom(
        api.list({ search: '', role: '', status: '' }, { page: 1, pageSize }),
      );
      expect(page.pageSize).toBe(pageSize);
    }

    await expect(
      firstValueFrom(
        api.list(
          { search: '', role: '', status: '' },
          { page: 1, pageSize: 25 as unknown as 20 },
        ),
      ),
    ).rejects.toMatchObject({ status: 422, code: 'VALIDATION_FAILED' });
  });

  it('порожня вибірка супроводжується поясненням, а не просто нулем', async () => {
    const page = await firstValueFrom(
      api.list({ search: 'нікого-нема', role: '', status: '' }, { page: 1, pageSize: 20 }),
    );
    expect(page.total).toBe(0);
    expect(page.emptyMessage).toBe('Користувачів за заданими умовами не знайдено');
  });

  it('створює користувача і показує згенерований пароль рівно один раз', async () => {
    const created = await firstValueFrom(
      api.create(draft({ storeIds: ['st-1', 'st-2'] })),
    );

    expect(created.passwordGenerated).toBe(true);
    expect(created.password.length).toBeGreaterThanOrEqual(12);
    expect(created.passwordNotice).toBe('Запишіть пароль — повторно він не показується.');
    expect(created.user.scope.storeIds).toEqual(['st-1', 'st-2']);

    // Повторно пароль не віддається — у картці його немає
    const card = await firstValueFrom(api.get(created.user.id));
    expect(card).not.toHaveProperty('password');
  });

  it('RBAC-13: порожній перелік магазинів у магазинної ролі — НУЛЬ доступу', async () => {
    const operator = await firstValueFrom(api.create(draft({ storeIds: [] })));

    expect(operator.user.scope.storeScoped).toBe(true);
    expect(operator.user.scope.networkWide).toBe(false);
    expect(operator.user.scope.zeroAccess).toBe(true);
    expect(operator.user.scope.warning).toContain('не матиме доступу');
  });

  it('RBAC-16: у мережевої ролі порожній перелік магазинів — це вся мережа', async () => {
    const analyst = await firstValueFrom(
      api.create(draft({ email: 'analityk@silpo.ua', role: 'analyst', storeIds: [] })),
    );

    expect(analyst.user.scope.networkWide).toBe(true);
    expect(analyst.user.scope.storeScoped).toBe(false);
    expect(analyst.user.scope.zeroAccess).toBe(false);
    expect(analyst.user.scope.warning).toBeNull();
  });

  it('e-mail унікальний — дубль відхиляється 409', async () => {
    const existing = db.state.accounts[0];

    await expect(
      firstValueFrom(api.create(draft({ email: existing.email.toUpperCase() }))),
    ).rejects.toMatchObject({ status: 409, code: 'USER_EMAIL_ALREADY_EXISTS' });
  });

  it('порожні обовʼязкові поля і невідома роль — 422', async () => {
    await expect(
      firstValueFrom(api.create(draft({ email: '   ' }))),
    ).rejects.toMatchObject({ status: 422 });

    await expect(
      firstValueFrom(api.create(draft({ fullName: '  ' }))),
    ).rejects.toMatchObject({ status: 422 });

    await expect(
      firstValueFrom(
        api.create(draft({ role: 'supplier_admin' as unknown as 'analyst' })),
      ),
    ).rejects.toMatchObject({ status: 422 });
  });

  it('PATCH застосовує лише передані поля', async () => {
    const created = await firstValueFrom(api.create(draft({ storeIds: ['st-1'] })));

    const renamed = await firstValueFrom(
      api.update(created.user.id, { fullName: 'Інше Імʼя' }),
    );
    expect(renamed.fullName).toBe('Інше Імʼя');
    expect(renamed.role).toBe('store_operator');
    expect(renamed.scope.storeIds).toEqual(['st-1']);

    const promoted = await firstValueFrom(
      api.update(created.user.id, { role: 'analyst' }),
    );
    // Мережевій ролі перелік магазинів не потрібен — він очищується
    expect(promoted.role).toBe('analyst');
    expect(promoted.scope.networkWide).toBe(true);
    expect(promoted.scope.storeIds).toEqual([]);
    expect(promoted.fullName).toBe('Інше Імʼя');
  });

  it('деактивація і повернення акаунта', async () => {
    const created = await firstValueFrom(api.create(draft()));

    const off = await firstValueFrom(api.deactivate(created.user.id));
    expect(off.active).toBe(false);

    const on = await firstValueFrom(api.activate(created.user.id));
    expect(on.active).toBe(true);
  });

  it('RBAC-25: останнього активного super_admin деактивувати не можна', async () => {
    const root = db.state.accounts.find((a) => a.role === 'super_admin')!;

    await expect(firstValueFrom(api.deactivate(root.id))).rejects.toMatchObject({
      status: 409,
      code: 'RBAC_LAST_SUPER_ADMIN',
    });
    expect(db.state.accounts.find((a) => a.id === root.id)!.active).toBe(true);
  });

  it('скидання пароля видає новий одноразовий пароль', async () => {
    const created = await firstValueFrom(api.create(draft()));
    const reset = await firstValueFrom(api.resetPassword(created.user.id));

    expect(reset.passwordGenerated).toBe(true);
    expect(reset.password).not.toBe(created.password);
    expect(reset.login).toBe(created.user.email);
  });

  it('невідомий користувач — 404 RESOURCE_NOT_FOUND', async () => {
    for (const call of [
      api.get('немає'),
      api.update('немає', { fullName: 'X' }),
      api.deactivate('немає'),
      api.activate('немає'),
      api.resetPassword('немає'),
    ]) {
      await expect(firstValueFrom(call)).rejects.toMatchObject({
        status: 404,
        code: 'RESOURCE_NOT_FOUND',
      });
    }
  });

  it('RBAC-23: network_manager бачить лише ролі свого дерева призначення', async () => {
    await firstValueFrom(auth.login('network.manager@silpo.ua', 'demo'));

    const page = await firstValueFrom(
      api.list({ search: '', role: '', status: '' }, { page: 1, pageSize: 100 }),
    );

    expect(page.total).toBeGreaterThan(0);
    expect(
      page.items.every(
        (u) => u.role === 'store_manager' || u.role === 'store_operator',
      ),
    ).toBe(true);
    expect(page.items.some((u) => u.role === 'super_admin')).toBe(false);
  });

  it('matchesUserFilter звіряє роль, статус і підрядок пошуку', () => {
    const account = db.state.accounts.find((a) => a.role === 'store_manager')!;

    expect(
      matchesUserFilter(account, { search: '', role: 'store_manager', status: '' }),
    ).toBe(true);
    expect(
      matchesUserFilter(account, { search: '', role: 'analyst', status: '' }),
    ).toBe(false);
    expect(
      matchesUserFilter(account, { search: '', role: '', status: 'inactive' }),
    ).toBe(false);
    expect(
      matchesUserFilter(account, {
        search: account.email.slice(0, 5),
        role: '',
        status: '',
      }),
    ).toBe(true);
  });

  it('toStaffUser не втрачає ознаку нульового доступу для деактивованих', () => {
    const account = db.state.accounts.find((a) => !a.active)!;

    const user = toStaffUser({ ...account, storeIds: [] });
    expect(user.active).toBe(false);
    expect(user.scope.zeroAccess).toBe(true);
  });
});
