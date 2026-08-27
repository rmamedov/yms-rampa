import { Observable } from 'rxjs';
import { PageSize, SyncLog, SyncReport } from '../models';

/**
 * store-service, розділ «Синхронізація MCP» (5.6):
 *   GET  /api/admin/v1/sync/log?page&perPage
 *   POST /api/admin/v1/sync/run
 */
export abstract class SyncApi {
  abstract log(page: number, perPage: PageSize): Observable<SyncLog>;
  /**
   * SYNC-02: повторний запуск під час активної синхронізації —
   * 409 SYNC_ALREADY_RUNNING. Ініціатора бекенд бере з заголовків
   * ідентичності, тіло запиту порожнє.
   */
  abstract run(): Observable<SyncReport>;
}
