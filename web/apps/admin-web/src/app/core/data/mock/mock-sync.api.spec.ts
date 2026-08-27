import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { SyncApi } from '../sync.api';
import { MockSyncApi } from './mock-sync.api';
import { MockDb } from './mock-db';
import { MOCK_LATENCY } from './mock-support';

describe('MockSyncApi — синхронізація MCP (5.6)', () => {
  let api: SyncApi;
  let db: MockDb;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        MockDb,
        { provide: SyncApi, useClass: MockSyncApi },
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    api = TestBed.inject(SyncApi);
    db = TestBed.inject(MockDb);
    db.reset();
  });

  it('SYNC-01: журнал віддається новішими вперед із серверною пагінацією', async () => {
    const page = await firstValueFrom(api.list({ page: 1, pageSize: 5 }));
    expect(page.items).toHaveLength(5);
    expect(page.total).toBe(db.state.syncRuns.length);
    const dates = page.items.map((r) => r.startedAt);
    expect([...dates]).toEqual([...dates].sort().reverse());
  });

  it('SYNC-04: невдалий запуск має статус «помилка» з текстом причини', async () => {
    const page = await firstValueFrom(api.list({ page: 1, pageSize: 100 }));
    const failed = page.items.find((r) => r.status === 'error');
    expect(failed).toBeDefined();
    expect(failed!.error).toContain('MCP');
    expect(failed!.newCount).toBe(0);
    expect(failed!.changedCount).toBe(0);
  });

  it('SYNC-02: ручний запуск додає запис із іменем ініціатора', async () => {
    const before = db.state.syncRuns.length;
    const run = await firstValueFrom(api.run('Оксана Лисенко'));
    expect(run.type).toBe('manual');
    expect(run.initiatedBy).toBe('Оксана Лисенко');
    expect(db.state.syncRuns.length).toBe(before + 1);
    expect(db.state.syncRuns[0].id).toBe(run.id);
  });

  it('SYNC-03: у запуску є diff нових / змінених / зниклих', async () => {
    const run = await firstValueFrom(api.run('Оксана Лисенко'));
    expect(run.diff.created.length).toBe(run.newCount);
    expect(run.diff.changed.length).toBe(run.changedCount);
    expect(run.diff.missing.length).toBe(run.missingCount);
    expect(run.diff.missing[0].missingSyncCount).toBeGreaterThan(0);
    expect(run.diff.changed[0].changes.length).toBeGreaterThan(0);
  });

  it('SYNC-02: повторний запуск під час активної синхронізації заборонений', async () => {
    db.state.syncRunning = true;
    await expect(firstValueFrom(api.run('Оксана Лисенко'))).rejects.toMatchObject({
      status: 409,
      code: 'SYNC_ALREADY_RUNNING',
    });
  });

  it('після завершення запуску прапорець виконання знімається', async () => {
    await firstValueFrom(api.run('Оксана Лисенко'));
    expect(db.state.syncRunning).toBe(false);
    await expect(firstValueFrom(api.run('Оксана Лисенко'))).resolves.toBeDefined();
  });

  it('невідомий запуск — 404', async () => {
    await expect(firstValueFrom(api.get('sync-нема'))).rejects.toMatchObject({
      status: 404,
    });
  });
});
