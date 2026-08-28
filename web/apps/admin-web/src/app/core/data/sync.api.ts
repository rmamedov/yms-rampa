import { Observable } from 'rxjs';
import { PageSize, SyncLog, SyncReport, SyncRunDetails } from '../models';

/**
 * store-service, розділ «Синхронізація MCP» (5.6):
 *   GET  /api/admin/v1/sync/log?page&perPage
 *   GET  /api/admin/v1/sync/log/{id}
 *   POST /api/admin/v1/sync/run
 */
export abstract class SyncApi {
  abstract log(page: number, perPage: PageSize): Observable<SyncLog>;

  /**
   * SYNC-01: деталізація запуску — перелік нових / змінених / зниклих філій.
   * У списку журналу його немає: перелік довгий, а таблиці потрібні лічильники.
   */
  abstract runDetails(id: string): Observable<SyncRunDetails>;
  /**
   * SYNC-02: повторний запуск під час активної синхронізації —
   * 409 SYNC_ALREADY_RUNNING. Ініціатора бекенд бере з заголовків
   * ідентичності, тіло запиту порожнє.
   */
  abstract run(): Observable<SyncReport>;
}
