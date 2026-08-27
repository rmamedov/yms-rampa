import { inject, Injectable } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { Page, PageQuery, SyncRun } from '../../models';
import { SyncApi } from '../sync.api';
import { MockDb } from './mock-db';
import { fail, MOCK_LATENCY, paginate, respond } from './mock-support';

@Injectable()
export class MockSyncApi extends SyncApi {
  private readonly db = inject(MockDb);
  private readonly latency = inject(MOCK_LATENCY);

  list(query: PageQuery): Observable<Page<SyncRun>> {
    return respond(() => {
      const sorted = [...this.db.state.syncRuns].sort((a, b) =>
        query.direction === 'asc'
          ? a.startedAt.localeCompare(b.startedAt)
          : b.startedAt.localeCompare(a.startedAt),
      );
      return paginate(sorted, query);
    }, this.latency);
  }

  get(id: string): Observable<SyncRun> {
    const run = this.db.state.syncRuns.find((r) => r.id === id);
    if (!run) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    return respond(() => run, this.latency);
  }

  /** SYNC-02: повторний запуск під час активної синхронізації заборонений. */
  run(initiatedBy: string): Observable<SyncRun> {
    if (this.db.state.syncRunning) {
      return fail(
        409,
        { code: 'SYNC_ALREADY_RUNNING', detail: 'Синхронізація вже виконується' },
        this.latency,
      );
    }
    return respond(() => {
      const startedAt = new Date();
      const stores = this.db.state.stores;
      const changed = stores.slice(2, 5).map((s) => ({
        externalId: s.externalId,
        city: s.city,
        changes: [
          { field: 'address', oldValue: s.address, newValue: `${s.address}` },
          { field: 'open', oldValue: String(!s.open), newValue: String(s.open) },
        ],
      }));
      const run: SyncRun = {
        id: this.db.nextId('sync'),
        startedAt: startedAt.toISOString(),
        finishedAt: new Date(startedAt.getTime() + 38_000).toISOString(),
        durationMs: 38_000,
        type: 'manual',
        initiatedBy,
        status: 'success',
        error: null,
        newCount: 1,
        changedCount: changed.length,
        missingCount: 1,
        diff: {
          created: [
            {
              externalId: '9001',
              city: 'Київ',
              address: 'вул. Нова, 1 (нова філія MCP)',
            },
          ],
          changed,
          missing: [
            {
              externalId: stores[7]?.externalId ?? '0000',
              city: stores[7]?.city ?? 'Київ',
              address: stores[7]?.address ?? '',
              missingSyncCount: 1,
              hasFutureBookings: true,
            },
          ],
        },
      };
      this.db.state.syncRuns = [run, ...this.db.state.syncRuns];
      return run;
    }, this.latency).pipe(
      tap({
        subscribe: () => {
          this.db.state.syncRunning = true;
        },
        next: () => {
          this.db.state.syncRunning = false;
        },
        error: () => {
          this.db.state.syncRunning = false;
        },
      }),
    );
  }
}
