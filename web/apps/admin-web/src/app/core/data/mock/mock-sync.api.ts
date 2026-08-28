import { inject, Injectable } from '@angular/core';
import { Observable, tap } from 'rxjs';
import {
  PageSize,
  SyncLog,
  SyncReport,
  SyncRunDetails,
} from '../../models';
import { SyncApi } from '../sync.api';
import {
  MockDb,
  mockSyncChanges,
  SYNC_STATUS_LABELS,
  SYNC_TRIGGER_LABELS,
} from './mock-db';
import { fail, MOCK_LATENCY, respond } from './mock-support';
import { AuthService } from '../../auth/auth.service';

@Injectable()
export class MockSyncApi extends SyncApi {
  private readonly db = inject(MockDb);
  private readonly auth = inject(AuthService);
  private readonly latency = inject(MOCK_LATENCY);

  /** SYNC-01: журнал запусків із серверною пагінацією. */
  log(page: number, perPage: PageSize): Observable<SyncLog> {
    return respond(() => {
      const sorted = [...this.db.state.syncLog].sort((a, b) =>
        b.startedAt.localeCompare(a.startedAt),
      );
      const safePage = Math.max(1, page);
      const size = Math.max(1, Math.min(100, perPage));
      const offset = (safePage - 1) * size;
      const lastSuccessful = sorted.find((e) => e.status === 'success');
      return {
        items: sorted.slice(offset, offset + size),
        total: sorted.length,
        page: safePage,
        perPage: size,
        lastSuccessfulAt: lastSuccessful?.finishedAt ?? null,
        running: this.db.state.syncRunning,
      };
    }, this.latency);
  }

  /** SYNC-01: деталізація запуску — які саме філії зʼявились/змінились/зникли. */
  runDetails(id: string): Observable<SyncRunDetails> {
    const entry = this.db.state.syncLog.find((e) => e.id === id);
    if (!entry) {
      return fail(404, { code: 'SYNC_RUN_NOT_FOUND' }, this.latency);
    }
    return respond(() => entry, this.latency);
  }

  /** SYNC-02: повторний запуск під час активної синхронізації заборонений. */
  run(): Observable<SyncReport> {
    if (this.db.state.syncRunning) {
      return fail(
        409,
        { code: 'SYNC_ALREADY_RUNNING', detail: 'Синхронізація вже виконується' },
        this.latency,
      );
    }
    return respond(() => {
      const stores = this.db.state.stores;
      let cursor = 0;
      const startedAt = new Date();
      const finishedAt = new Date(startedAt.getTime() + 38_000);
      const report: SyncReport = {
        status: 'success',
        trigger: 'manual',
        initiator: this.auth.user()?.id ?? null,
        startedAt: startedAt.toISOString(),
        finishedAt: finishedAt.toISOString(),
        durationSeconds: 38,
        fetched: 1204,
        skipped: 3,
        created: 1,
        updated: 4,
        missing: 1,
        archived: 0,
        conflicts: 0,
        ineligible: 12,
        eligible: 1192,
        ineligibleByReason: { branch_closed: 9, no_configuration: 3 },
        errors: [],
        ...mockSyncChanges(1, 4, 1, () => stores[cursor++ % stores.length]),
      };
      const entry: SyncRunDetails = {
        id: this.db.nextId('sync'),
        status: report.status,
        statusLabel: SYNC_STATUS_LABELS[report.status],
        trigger: report.trigger,
        triggerLabel: SYNC_TRIGGER_LABELS[report.trigger],
        initiator: report.initiator,
        source: 'mcp',
        startedAt: report.startedAt,
        finishedAt: report.finishedAt,
        durationSeconds: report.durationSeconds,
        fetched: report.fetched,
        created: report.created,
        updated: report.updated,
        missing: report.missing,
        archived: report.archived,
        conflicts: report.conflicts,
        skipped: report.skipped,
        errors: [],
        changes: report.changes,
        changesTotal: report.changesTotal,
        changesRecorded: true,
      };
      this.db.state.syncLog = [entry, ...this.db.state.syncLog];
      return report;
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
