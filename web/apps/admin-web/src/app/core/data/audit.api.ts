import { Observable } from 'rxjs';
import {
  AuditAction,
  AuditEntry,
  AuditFilter,
  AuditObjectType,
  FieldChange,
  Page,
  PageQuery,
} from '../models';

export interface AuditWriteCommand {
  readonly objectType: AuditObjectType;
  readonly objectId: string;
  readonly objectLabel: string;
  readonly action: AuditAction;
  readonly changes: readonly FieldChange[];
}

/** Аудит-лог адмін-дій (5.8). Записи незмінні — лише читання і додавання. */
export abstract class AuditApi {
  abstract list(filter: AuditFilter, query: PageQuery): Observable<Page<AuditEntry>>;
  abstract all(filter: AuditFilter): Observable<readonly AuditEntry[]>;
  /** ADM-04: запис фіксується до повернення успішної відповіді користувачу. */
  abstract write(command: AuditWriteCommand): Observable<AuditEntry>;
}
