import { Observable } from 'rxjs';
import { Page, PageQuery, SyncRun } from '../models';

/** store-service: журнал і ручний запуск синхронізації з MCP (5.6). */
export abstract class SyncApi {
  abstract list(query: PageQuery): Observable<Page<SyncRun>>;
  abstract get(id: string): Observable<SyncRun>;
  /** SYNC-02: повторний запуск під час активної синхронізації заборонений. */
  abstract run(initiatedBy: string): Observable<SyncRun>;
}
