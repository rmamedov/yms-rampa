import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { AuditApi } from '../audit.api';
import { MockAuditApi, matchesAuditFilter } from './mock-audit.api';
import { MockDb } from './mock-db';
import { MOCK_LATENCY } from './mock-support';
import { AuthService } from '../../auth/auth.service';
import { AuthApi } from '../auth.api';
import { MockAuthApi } from './mock-auth.api';
import { AuditEntry, AuditFilter } from '../../models';

const NO_FILTER: AuditFilter = {
  userId: null,
  objectType: null,
  action: null,
  from: null,
  to: null,
};

describe('MockAuditApi — аудит-лог (5.8)', () => {
  let api: AuditApi;
  let db: MockDb;
  let auth: AuthService;

  beforeEach(async () => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        MockDb,
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: AuditApi, useClass: MockAuditApi },
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    api = TestBed.inject(AuditApi);
    db = TestBed.inject(MockDb);
    auth = TestBed.inject(AuthService);
    db.reset();
    await firstValueFrom(auth.login('super.admin@silpo.ua', 'demo'));
  });

  afterEach(() => localStorage.clear());

  it('AUD-02: серверна пагінація, новіші вперед', async () => {
    const page = await firstValueFrom(api.list(NO_FILTER, { page: 1, pageSize: 10 }));
    expect(page.items).toHaveLength(10);
    expect(page.total).toBe(db.state.audit.length);
    const times = page.items.map((e) => e.at);
    expect([...times]).toEqual([...times].sort().reverse());
  });

  it('AUD-02: фільтр за типом обʼєкта і дією', async () => {
    const page = await firstValueFrom(
      api.list(
        { ...NO_FILTER, objectType: 'supplier', action: 'status_change' },
        { page: 1, pageSize: 100 },
      ),
    );
    expect(page.total).toBeGreaterThan(0);
    expect(
      page.items.every(
        (e) => e.objectType === 'supplier' && e.action === 'status_change',
      ),
    ).toBe(true);
  });

  it('ADM-04: запис фіксує актора, обʼєкт і зміни', async () => {
    const before = db.state.audit.length;
    const entry = await firstValueFrom(
      api.write({
        objectType: 'store',
        objectId: 'st-1998',
        objectLabel: '1998 — Київ',
        action: 'update',
        changes: [
          { field: 'maxVehicleWeightTons', oldValue: '20', newValue: '18' },
        ],
      }),
    );
    expect(entry.userName).toBe('Руслан Мамедов');
    expect(entry.role).toBe('super_admin');
    expect(entry.changes[0].newValue).toBe('18');
    expect(db.state.audit.length).toBe(before + 1);
    expect(db.state.audit[0].id).toBe(entry.id);
  });

  it('AUD-02: експорт віддає всю вибірку без пагінації', async () => {
    const all = await firstValueFrom(
      api.all({ ...NO_FILTER, objectType: 'store' }),
    );
    const page = await firstValueFrom(
      api.list({ ...NO_FILTER, objectType: 'store' }, { page: 1, pageSize: 5 }),
    );
    expect(all.length).toBe(page.total);
    expect(all.length).toBeGreaterThan(page.items.length);
  });
});

describe('matchesAuditFilter', () => {
  const entry: AuditEntry = {
    id: 'a1',
    at: '2026-08-20T10:00:00.000Z',
    userId: 'su-1',
    userName: 'Руслан Мамедов',
    role: 'super_admin',
    ip: '10.0.0.1',
    objectType: 'store',
    objectId: 'st-1',
    objectLabel: '1998',
    action: 'update',
    changes: [],
  };

  it('фільтрує за періодом включно', () => {
    expect(
      matchesAuditFilter(entry, { ...NO_FILTER, from: '2026-08-20', to: '2026-08-20' }),
    ).toBe(true);
    expect(matchesAuditFilter(entry, { ...NO_FILTER, from: '2026-08-21' })).toBe(false);
    expect(matchesAuditFilter(entry, { ...NO_FILTER, to: '2026-08-19' })).toBe(false);
  });

  it('фільтрує за користувачем', () => {
    expect(matchesAuditFilter(entry, { ...NO_FILTER, userId: 'su-1' })).toBe(true);
    expect(matchesAuditFilter(entry, { ...NO_FILTER, userId: 'su-2' })).toBe(false);
  });
});
