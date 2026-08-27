import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { SyncApi } from '../sync.api';
import { MockSyncApi } from './mock-sync.api';
import { MockDb } from './mock-db';
import { MOCK_LATENCY } from './mock-support';
import { AuthApi } from '../auth.api';
import { MockAuthApi } from './mock-auth.api';
import { AuthService } from '../../auth/auth.service';

describe('MockSyncApi — синхронізація MCP (5.6)', () => {
  let api: SyncApi;
  let db: MockDb;
  let auth: AuthService;

  beforeEach(async () => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        MockDb,
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: SyncApi, useClass: MockSyncApi },
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    api = TestBed.inject(SyncApi);
    db = TestBed.inject(MockDb);
    auth = TestBed.inject(AuthService);
    db.reset();
    await firstValueFrom(auth.login('network.manager@silpo.ua', 'demo'));
  });

  afterEach(() => localStorage.clear());

  it('SYNC-01: журнал віддається новішими вперед із серверною пагінацією', async () => {
    const log = await firstValueFrom(api.log(1, 20));
    expect(log.total).toBe(db.state.syncLog.length);
    expect(log.page).toBe(1);
    expect(log.perPage).toBe(20);
    const dates = log.items.map((e) => e.startedAt);
    expect(dates).toEqual([...dates].sort((a, b) => b.localeCompare(a)));
  });

  it('INT-13: журнал несе lastSuccessfulAt і прапорець running', async () => {
    const log = await firstValueFrom(api.log(1, 20));
    expect(log.running).toBe(false);
    expect(log.lastSuccessfulAt).not.toBeNull();
  });

  it('статуси відповідають переліку бекенду: running/success/partial/failed', async () => {
    const log = await firstValueFrom(api.log(1, 20));
    const statuses = new Set(log.items.map((e) => e.status));
    expect([...statuses].every((s) =>
      ['running', 'success', 'partial', 'failed'].includes(s),
    )).toBe(true);
    expect(log.items.some((e) => e.status === 'failed')).toBe(true);
    expect(log.items.find((e) => e.status === 'failed')!.errors.length).toBeGreaterThan(
      0,
    );
  });

  it('SYNC-02: ручний запуск повертає звіт і додає запис у журнал', async () => {
    const before = db.state.syncLog.length;
    const report = await firstValueFrom(api.run());
    expect(report.trigger).toBe('manual');
    expect(report.status).toBe('success');
    expect(report.fetched).toBeGreaterThan(0);
    expect(db.state.syncLog).toHaveLength(before + 1);
    expect(db.state.syncLog[0].trigger).toBe('manual');
  });

  it('SYNC-02: повторний запуск під час активної синхронізації заборонений', async () => {
    db.state.syncRunning = true;
    await expect(firstValueFrom(api.run())).rejects.toMatchObject({
      status: 409,
      code: 'SYNC_ALREADY_RUNNING',
    });
  });

  it('після завершення запуску прапорець виконання знімається', async () => {
    await firstValueFrom(api.run());
    expect(db.state.syncRunning).toBe(false);
  });
});
