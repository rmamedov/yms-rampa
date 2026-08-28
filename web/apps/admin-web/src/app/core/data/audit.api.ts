import { Observable } from 'rxjs';
import { AuditAction, AuditLog, PageSize } from '../models';

export interface AuditFilter {
  /** '' — будь-яка дія. */
  readonly action: AuditAction | '';
  /** '' — будь-який користувач. */
  readonly targetUserId: string;
}

export const DEFAULT_AUDIT_FILTER: AuditFilter = { action: '', targetUserId: '' };

/**
 * identity-staff-service, розділ «Журнал аудиту» (RBAC-29, RBAC-31):
 *   GET /api/admin/v1/audit?page&perPage&action&targetUserId
 *
 * Право `audit.read` перевіряє бекенд; ролям без нього — 403.
 */
export abstract class AuditApi {
  abstract list(
    filter: AuditFilter,
    page: number,
    perPage: PageSize,
  ): Observable<AuditLog>;
}
