import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { AuditEntry, AuditFilter, Page, PageQuery } from '../../models';
import { AuditApi, AuditWriteCommand } from '../audit.api';
import { MockDb } from './mock-db';
import { MOCK_LATENCY, paginate, respond } from './mock-support';
import { AuthService } from '../../auth/auth.service';

export function matchesAuditFilter(entry: AuditEntry, filter: AuditFilter): boolean {
  if (filter.userId && entry.userId !== filter.userId) {
    return false;
  }
  if (filter.objectType && entry.objectType !== filter.objectType) {
    return false;
  }
  if (filter.action && entry.action !== filter.action) {
    return false;
  }
  const day = entry.at.slice(0, 10);
  if (filter.from && day < filter.from) {
    return false;
  }
  if (filter.to && day > filter.to) {
    return false;
  }
  return true;
}

@Injectable()
export class MockAuditApi extends AuditApi {
  private readonly db = inject(MockDb);
  private readonly auth = inject(AuthService);
  private readonly latency = inject(MOCK_LATENCY);

  list(filter: AuditFilter, query: PageQuery): Observable<Page<AuditEntry>> {
    return respond(() => paginate(this.filtered(filter), query), this.latency);
  }

  all(filter: AuditFilter): Observable<readonly AuditEntry[]> {
    return respond(() => this.filtered(filter), this.latency);
  }

  /** AUD-03: записи незмінні — доступне лише додавання. */
  write(command: AuditWriteCommand): Observable<AuditEntry> {
    return respond(() => {
      const user = this.auth.user();
      const entry: AuditEntry = {
        id: this.db.nextId('aud'),
        at: new Date().toISOString(),
        userId: user?.id ?? 'unknown',
        userName: user?.fullName ?? 'Невідомий користувач',
        role: user?.role ?? 'analyst',
        ip: '10.20.0.1',
        ...command,
      };
      this.db.state.audit = [entry, ...this.db.state.audit];
      return entry;
    }, this.latency);
  }

  private filtered(filter: AuditFilter): AuditEntry[] {
    return this.db.state.audit
      .filter((entry) => matchesAuditFilter(entry, filter))
      .sort((a, b) => b.at.localeCompare(a.at));
  }
}
