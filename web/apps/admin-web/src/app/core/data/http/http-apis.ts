import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import { ApiClient } from '../../http/api.client';
import {
  AnalyticsDashboard,
  AnalyticsFilter,
  AuditEntry,
  AuditFilter,
  AuthSession,
  AuthTokens,
  Booking,
  BulkResultRow,
  ConfigChangeRequest,
  ConfigConflict,
  Page,
  PageQuery,
  Store,
  StoreGeneralPatch,
  StoreListFilter,
  StoreListRow,
  StaffUser,
  Supplier,
  SupplierDriver,
  SupplierStatus,
  SupplierUser,
  SyncRun,
  Vehicle,
  YmsStatus,
} from '../../models';
import { AuthApi } from '../auth.api';
import { ConfigTemplateId, StoreConfigDraft, StoresApi } from '../stores.api';
import {
  SupplierDraft,
  SupplierFilter,
  SuppliersApi,
  SupplierUserDraft,
} from '../suppliers.api';
import { StaffApi, StaffFilter, StaffUserDraft } from '../staff.api';
import { SyncApi } from '../sync.api';
import { AnalyticsApi } from '../analytics.api';
import { AuditApi, AuditWriteCommand } from '../audit.api';

function pageParams(query: PageQuery): Record<string, string | number> {
  const params: Record<string, string | number> = {
    page: query.page,
    pageSize: query.pageSize,
  };
  if (query.sort) params['sort'] = query.sort;
  if (query.direction) params['direction'] = query.direction;
  return params;
}

/** POST /api/admin/v1/auth/login | /auth/refresh */
@Injectable()
export class HttpAuthApi extends AuthApi {
  private readonly api = inject(ApiClient);

  login(email: string, password: string): Observable<AuthSession> {
    return this.api.post<AuthSession>('/auth/login', { email, password });
  }

  refresh(refreshToken: string): Observable<AuthTokens> {
    return this.api.post<AuthTokens>('/auth/refresh', { refreshToken });
  }
}

/** /api/admin/v1/stores/** (store-service через api-gateway) */
@Injectable()
export class HttpStoresApi extends StoresApi {
  private readonly api = inject(ApiClient);

  list(filter: StoreListFilter, query: PageQuery): Observable<Page<StoreListRow>> {
    return this.api.get<Page<StoreListRow>>('/stores', {
      ...pageParams(query),
      q: filter.search,
      cities: filter.cities,
      statuses: filter.statuses,
      configured: filter.configured === null ? null : String(filter.configured),
    });
  }

  cities(): Observable<readonly string[]> {
    return this.api.get<readonly string[]>('/stores/cities');
  }

  get(id: string): Observable<Store> {
    return this.api.get<Store>(`/stores/${id}`);
  }

  updateGeneral(id: string, patch: StoreGeneralPatch): Observable<Store> {
    return this.api.patch<Store>(`/stores/${id}/general`, patch);
  }

  checkConflicts(
    id: string,
    draft: StoreConfigDraft,
    effectiveFrom: string,
    nextYmsStatus?: YmsStatus,
  ): Observable<readonly ConfigConflict[]> {
    return this.api
      .post<{ conflicts: ConfigConflict[] }>(`/stores/${id}/config/conflicts`, {
        effectiveFrom,
        nextYmsStatus,
        config: draft,
      })
      .pipe(map((response) => response.conflicts));
  }

  saveConfig(request: ConfigChangeRequest): Observable<Store> {
    return this.api.put<Store>(`/stores/${request.storeId}/config`, request);
  }

  bookings(id: string): Observable<readonly Booking[]> {
    return this.api.get<readonly Booking[]>(`/stores/${id}/bookings`);
  }

  bulkStatus(
    ids: readonly string[],
    status: YmsStatus,
  ): Observable<readonly BulkResultRow[]> {
    return this.api.post<readonly BulkResultRow[]>('/stores/bulk/status', {
      ids,
      status,
    });
  }

  bulkVisibility(
    ids: readonly string[],
    visible: boolean,
  ): Observable<readonly BulkResultRow[]> {
    return this.api.post<readonly BulkResultRow[]>('/stores/bulk/visibility', {
      ids,
      visible,
    });
  }

  applyTemplate(
    ids: readonly string[],
    template: ConfigTemplateId,
  ): Observable<readonly BulkResultRow[]> {
    return this.api.post<readonly BulkResultRow[]>('/stores/bulk/template', {
      ids,
      template,
    });
  }
}

/** /api/admin/v1/suppliers/** (partner-service) */
@Injectable()
export class HttpSuppliersApi extends SuppliersApi {
  private readonly api = inject(ApiClient);

  list(filter: SupplierFilter, query: PageQuery): Observable<Page<Supplier>> {
    return this.api.get<Page<Supplier>>('/suppliers', {
      ...pageParams(query),
      q: filter.search,
      statuses: filter.statuses,
    });
  }

  all(): Observable<readonly Supplier[]> {
    return this.api.get<readonly Supplier[]>('/suppliers/all');
  }

  get(id: string): Observable<Supplier> {
    return this.api.get<Supplier>(`/suppliers/${id}`);
  }

  save(draft: SupplierDraft): Observable<Supplier> {
    return draft.id
      ? this.api.patch<Supplier>(`/suppliers/${draft.id}`, draft)
      : this.api.post<Supplier>('/suppliers', draft);
  }

  remove(id: string): Observable<void> {
    return this.api.delete<void>(`/suppliers/${id}`);
  }

  bulkStatus(
    ids: readonly string[],
    status: SupplierStatus,
  ): Observable<readonly BulkResultRow[]> {
    return this.api.post<readonly BulkResultRow[]>('/suppliers/bulk/status', {
      ids,
      status,
    });
  }

  users(supplierId: string): Observable<readonly SupplierUser[]> {
    return this.api.get<readonly SupplierUser[]>(`/suppliers/${supplierId}/users`);
  }

  saveUser(draft: SupplierUserDraft): Observable<SupplierUser> {
    return draft.id
      ? this.api.patch<SupplierUser>(
          `/suppliers/${draft.supplierId}/users/${draft.id}`,
          draft,
        )
      : this.api.post<SupplierUser>(`/suppliers/${draft.supplierId}/users`, draft);
  }

  resetUserPassword(userId: string): Observable<void> {
    return this.api.post<void>(`/supplier-users/${userId}/reset-password`);
  }

  vehicles(supplierId: string, search: string): Observable<readonly Vehicle[]> {
    return this.api.get<readonly Vehicle[]>(`/suppliers/${supplierId}/vehicles`, {
      q: search,
    });
  }

  drivers(supplierId: string, search: string): Observable<readonly SupplierDriver[]> {
    return this.api.get<readonly SupplierDriver[]>(
      `/suppliers/${supplierId}/drivers`,
      { q: search },
    );
  }
}

/** /api/admin/v1/staff-users/** (identity-staff-service) */
@Injectable()
export class HttpStaffApi extends StaffApi {
  private readonly api = inject(ApiClient);

  list(filter: StaffFilter, query: PageQuery): Observable<Page<StaffUser>> {
    return this.api.get<Page<StaffUser>>('/staff-users', {
      ...pageParams(query),
      q: filter.search,
      roles: filter.roles,
      active: filter.active === null ? null : String(filter.active),
    });
  }

  get(id: string): Observable<StaffUser> {
    return this.api.get<StaffUser>(`/staff-users/${id}`);
  }

  save(draft: StaffUserDraft): Observable<StaffUser> {
    return draft.id
      ? this.api.patch<StaffUser>(`/staff-users/${draft.id}`, draft)
      : this.api.post<StaffUser>('/staff-users', draft);
  }

  setActive(id: string, active: boolean): Observable<StaffUser> {
    return this.api.patch<StaffUser>(`/staff-users/${id}/active`, { active });
  }
}

/** /api/admin/v1/mcp-sync/** (store-service) */
@Injectable()
export class HttpSyncApi extends SyncApi {
  private readonly api = inject(ApiClient);

  list(query: PageQuery): Observable<Page<SyncRun>> {
    return this.api.get<Page<SyncRun>>('/mcp-sync/runs', pageParams(query));
  }

  get(id: string): Observable<SyncRun> {
    return this.api.get<SyncRun>(`/mcp-sync/runs/${id}`);
  }

  run(): Observable<SyncRun> {
    return this.api.post<SyncRun>('/mcp-sync/runs');
  }
}

/** /api/admin/v1/analytics/dashboard (analytics-service) */
@Injectable()
export class HttpAnalyticsApi extends AnalyticsApi {
  private readonly api = inject(ApiClient);

  dashboard(filter: AnalyticsFilter): Observable<AnalyticsDashboard> {
    return this.api.get<AnalyticsDashboard>('/analytics/dashboard', {
      from: filter.from,
      to: filter.to,
      cities: filter.cities,
      storeIds: filter.storeIds,
      supplierIds: filter.supplierIds,
    });
  }
}

/** /api/admin/v1/audit (аудит-лог, лише читання і додавання) */
@Injectable()
export class HttpAuditApi extends AuditApi {
  private readonly api = inject(ApiClient);

  list(filter: AuditFilter, query: PageQuery): Observable<Page<AuditEntry>> {
    return this.api.get<Page<AuditEntry>>('/audit', {
      ...pageParams(query),
      userId: filter.userId,
      objectType: filter.objectType,
      action: filter.action,
      from: filter.from,
      to: filter.to,
    });
  }

  all(filter: AuditFilter): Observable<readonly AuditEntry[]> {
    return this.api.get<readonly AuditEntry[]>('/audit/export', {
      userId: filter.userId,
      objectType: filter.objectType,
      action: filter.action,
      from: filter.from,
      to: filter.to,
    });
  }

  write(command: AuditWriteCommand): Observable<AuditEntry> {
    return this.api.post<AuditEntry>('/audit', command);
  }
}
