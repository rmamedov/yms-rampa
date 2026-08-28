import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { AuditAction, AuditEntry, AuditLog, PageSize } from '../../models';
import { AuditApi, AuditFilter } from '../audit.api';
import { MockDb } from './mock-db';
import { fail, MOCK_LATENCY, respond } from './mock-support';
import { AuthService } from '../../auth/auth.service';

/** Підписи дій — ті самі, що віддає AuditLogService бекенду. */
export const AUDIT_ACTION_LABELS: Readonly<Record<AuditAction, string>> = {
  create: 'Створення акаунта',
  assign: 'Зміна ролі',
  scope_change: 'Зміна скоупа магазинів',
  deactivate: 'Деактивація',
  reactivate: 'Активація',
  rename: 'Зміна імені',
  password_reset: 'Скидання пароля',
};

@Injectable()
export class MockAuditApi extends AuditApi {
  private readonly db = inject(MockDb);
  private readonly auth = inject(AuthService);
  private readonly latency = inject(MOCK_LATENCY);

  list(filter: AuditFilter, page: number, perPage: PageSize): Observable<AuditLog> {
    // Матриця 4.4: право перевіряє бекенд, мок повторює його поведінку.
    if (this.auth.grant('audit.read') !== 'full') {
      return fail(403, { code: 'RBAC_PERMISSION_DENIED' }, this.latency);
    }

    return respond(() => {
      const all = this.entries().filter(
        (entry) =>
          (filter.action === '' || entry.action === filter.action) &&
          (filter.targetUserId === '' || entry.targetUserId === filter.targetUserId),
      );
      const safePage = Math.max(1, page);
      const size = Math.max(1, Math.min(100, perPage));
      const offset = (safePage - 1) * size;

      return {
        items: all.slice(offset, offset + size),
        total: all.length,
        page: safePage,
        perPage: size,
        actions: (Object.keys(AUDIT_ACTION_LABELS) as AuditAction[]).map((value) => ({
          value,
          label: AUDIT_ACTION_LABELS[value],
        })),
      };
    }, this.latency);
  }

  /**
   * Демонстраційний журнал: по одному запису створення на кожен акаунт
   * довідника плюс кілька змін ролі та скоупа. Від новіших до старіших.
   */
  private entries(): AuditEntry[] {
    const accounts = this.db.state.accounts;
    const root = accounts.find((a) => a.role === 'super_admin') ?? accounts[0];
    if (!root) {
      return [];
    }

    const entries: AuditEntry[] = [];
    accounts.forEach((account, index) => {
      const at = new Date(Date.now() - (index + 1) * 7_200_000).toISOString();
      entries.push({
        actorUserId: root.id,
        actorName: root.fullName,
        actorRole: root.role,
        actorRoleLabel: root.roleLabel,
        targetUserId: account.id,
        targetName: account.fullName,
        action: 'create',
        actionLabel: AUDIT_ACTION_LABELS['create'],
        before: {},
        after: { role: account.role, storeIds: account.storeIds },
        timestamp: at,
        requestId: `req-${index + 1}`,
        ip: '10.0.0.1',
      });

      if (index % 3 === 1) {
        entries.push({
          actorUserId: root.id,
          actorName: root.fullName,
          actorRole: root.role,
          actorRoleLabel: root.roleLabel,
          targetUserId: account.id,
          targetName: account.fullName,
          action: 'scope_change',
          actionLabel: AUDIT_ACTION_LABELS['scope_change'],
          before: { storeIds: [] },
          after: { storeIds: account.storeIds },
          timestamp: new Date(Date.now() - index * 3_600_000).toISOString(),
          requestId: `req-scope-${index + 1}`,
          ip: '10.0.0.1',
        });
      }
    });

    return entries.sort((a, b) => b.timestamp.localeCompare(a.timestamp));
  }
}
