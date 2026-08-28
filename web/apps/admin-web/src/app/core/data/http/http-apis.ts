import { inject, Injectable } from '@angular/core';
import { catchError, forkJoin, map, Observable, of, switchMap } from 'rxjs';
import { ApiClient, QueryParams } from '../../http/api.client';
import { ApiError } from '../../http/problem';
import {
  AnalyticsBreakdown,
  AnalyticsDimension,
  AnalyticsExportDataset,
  AnalyticsFilter,
  AnalyticsKpi,
  AuditLog,
  AuthSession,
  AuthTokens,
  BulkResultRow,
  CityFilter,
  PAGE_SIZES,
  Page,
  PageQuery,
  PageSize,
  ReservedSlotRule,
  SlotBlock,
  STORE_SORT_COLUMNS,
  StaffUser,
  StaffUserCredentials,
  Store,
  StoreConfiguration,
  StoreGeneralPatch,
  StoreListFilter,
  StoreListRow,
  Supplier,
  SupplierStatus,
  SyncLog,
  SyncReport,
  SyncRunDetails,
  YmsStatus,
} from '../../models';
import { AuthApi } from '../auth.api';
import {
  ReservedSlotRuleDraft,
  SlotBlockDraft,
  StoreConfigurationDraft,
  StoresApi,
} from '../stores.api';
import { SupplierDraft, SupplierFilter, SuppliersApi } from '../suppliers.api';
import {
  StaffUserDraft,
  StaffUserFilter,
  StaffUserPatch,
  UsersApi,
} from '../users.api';
import { SyncApi } from '../sync.api';
import { AuditApi, AuditFilter } from '../audit.api';
import { AnalyticsApi } from '../analytics.api';
import {
  fromCalendarException,
  fromRamp,
  toCityOption,
  toConfiguration,
  toPage,
  toReservedRule,
  toSession,
  toSlotBlock,
  toStaffUser,
  toStaffUserCredentials,
  toStore,
  toStoreListRow,
  toSupplier,
  toSupplierPage,
  toSyncLog,
  toSyncReport,
  toSyncRunDetails,
  toAuditLog,
  toTokens,
  WireBulkStatus,
  WireCity,
  WirePage,
  WireConfiguration,
  WireReservedRule,
  WireSlotBlock,
  WireStaffUser,
  WireStaffUserCredentials,
  WireStoreCard,
  WireStoreRow,
  WireSupplier,
  WireSupplierList,
  WireSyncLog,
  WireSyncReport,
  WireSyncRunDetails,
  WireAuditLog,
  WireTokenResponse,
} from './wire';

/** BranchCriteria::ALLOWED_PER_PAGE — усе інше бекенд відхиляє 422. */
function perPageOf(query: PageQuery): PageSize {
  return (PAGE_SIZES as readonly number[]).includes(query.pageSize)
    ? query.pageSize
    : 20;
}

/** Store-service сортує лише за визначеним переліком колонок. */
function sortParams(query: PageQuery): Record<string, string> {
  const params: Record<string, string> = {};
  if (query.sort && STORE_SORT_COLUMNS.includes(query.sort)) {
    params['sortBy'] = query.sort;
    params['sortDirection'] = query.direction ?? 'asc';
  }
  return params;
}

function rowResult(id: string, label: string): BulkResultRow {
  return { id, label, ok: true };
}

function rowFailure(id: string, error: unknown): BulkResultRow {
  const problem = error instanceof ApiError ? error : null;
  return {
    id,
    label: id,
    ok: false,
    message: problem?.problem.detail ?? 'error.unknown',
  };
}

function analyticsParams(filter: AnalyticsFilter): QueryParams {
  return {
    from: filter.from,
    to: filter.to,
    city: filter.cities,
    storeId: filter.storeIds,
    supplierId: filter.supplierIds,
  };
}

/** POST /api/admin/v1/auth/{login|refresh|logout} — identity-staff-service. */
@Injectable()
export class HttpAuthApi extends AuthApi {
  private readonly api = inject(ApiClient);

  login(email: string, password: string): Observable<AuthSession> {
    return this.api
      .post<WireTokenResponse>('/auth/login', { email, password })
      .pipe(map(toSession));
  }

  refresh(refreshToken: string): Observable<AuthTokens> {
    return this.api
      .post<WireTokenResponse>('/auth/refresh', { refreshToken })
      .pipe(map(toTokens));
  }

  logout(refreshToken: string, allDevices = false): Observable<void> {
    return this.api
      .post<void>('/auth/logout', { refreshToken, allDevices })
      .pipe(map(() => undefined));
  }
}

/** /api/admin/v1/users/** — identity-staff-service (розділ 4.7). */
@Injectable()
export class HttpUsersApi extends UsersApi {
  private readonly api = inject(ApiClient);

  list(filter: StaffUserFilter, query: PageQuery): Observable<Page<StaffUser>> {
    return this.api
      .get<WirePage<WireStaffUser>>('/users', {
        page: query.page,
        perPage: perPageOf(query),
        q: filter.search,
        role: filter.role,
        status: filter.status,
      })
      .pipe(map((wire) => toPage(wire, toStaffUser)));
  }

  get(id: string): Observable<StaffUser> {
    return this.api.get<WireStaffUser>(`/users/${id}`).pipe(map(toStaffUser));
  }

  create(draft: StaffUserDraft): Observable<StaffUserCredentials> {
    return this.api
      .post<WireStaffUserCredentials>('/users', {
        email: draft.email,
        fullName: draft.fullName,
        // RBAC-04: одна роль одним полем; масив `roles` бекенд трактує
        // як спробу призначити другу.
        role: draft.role,
        storeIds: [...draft.storeIds],
        // Порожній пароль не шлемо взагалі — інакше бекенд вважатиме,
        // що адміністратор задав його явно.
        ...(draft.password ? { password: draft.password } : {}),
      })
      .pipe(map(toStaffUserCredentials));
  }

  update(id: string, patch: StaffUserPatch): Observable<StaffUser> {
    const body: Record<string, unknown> = {};
    if (patch.fullName !== undefined) {
      body['fullName'] = patch.fullName;
    }
    if (patch.role !== undefined) {
      body['role'] = patch.role;
    }
    if (patch.storeIds !== undefined) {
      body['storeIds'] = [...patch.storeIds];
    }
    return this.api.patch<WireStaffUser>(`/users/${id}`, body).pipe(map(toStaffUser));
  }

  deactivate(id: string): Observable<StaffUser> {
    return this.api
      .post<WireStaffUser>(`/users/${id}/deactivate`)
      .pipe(map(toStaffUser));
  }

  activate(id: string): Observable<StaffUser> {
    return this.api
      .post<WireStaffUser>(`/users/${id}/activate`)
      .pipe(map(toStaffUser));
  }

  resetPassword(id: string): Observable<StaffUserCredentials> {
    return this.api
      .post<WireStaffUserCredentials>(`/users/${id}/password/reset`)
      .pipe(map(toStaffUserCredentials));
  }
}

/** /api/admin/v1/stores/** — store-service. */
@Injectable()
export class HttpStoresApi extends StoresApi {
  private readonly api = inject(ApiClient);

  list(filter: StoreListFilter, query: PageQuery): Observable<Page<StoreListRow>> {
    return this.api
      .get<WirePage<WireStoreRow>>('/stores', {
        page: query.page,
        perPage: perPageOf(query),
        ...sortParams(query),
        q: filter.search,
        city: filter.cities,
        ymsStatus: filter.statuses,
        configured: filter.configured === null ? null : String(filter.configured),
      })
      .pipe(map((wire) => toPage(wire, toStoreListRow)));
  }

  cities(): Observable<CityFilter> {
    return this.api
      .get<{ items: WireCity[]; withoutCity?: number }>('/stores/cities')
      .pipe(
        map((wire) => ({
          items: (wire.items ?? []).map(toCityOption),
          withoutCity: wire.withoutCity ?? 0,
        })),
      );
  }

  /**
   * Картка магазину не містить конфігурації — її, резерви й блокування
   * бекенд віддає окремими маршрутами, тому збираємо їх разом.
   * Відсутня конфігурація — 404 CONFIG_NOT_FOUND, це нормальний стан
   * ненастроєного магазину, а не помилка.
   */
  get(id: string): Observable<Store> {
    return forkJoin({
      card: this.api.get<WireStoreCard>(`/stores/${id}`),
      configuration: this.latestConfiguration(id),
      reservedRules: this.reservedRules(id),
      slotBlocks: this.slotBlocks(id),
    }).pipe(
      map((parts) =>
        toStore(parts.card, parts.configuration, parts.reservedRules, parts.slotBlocks),
      ),
    );
  }

  /**
   * Екран налаштувань редагує ОСТАННЮ версію, а не чинну сьогодні.
   *
   * Нова версія за STC-60 набирає чинності не раніше завтра. Поки тут читалася
   * /configurations/current, одразу після збереження екран перемальовувався
   * старою чинною версією — щойно введене вікно прийому зникало, і виглядало
   * це як «не зберігається». Саме так виявився невидимим розклад на неділю.
   */
  private latestConfiguration(id: string): Observable<StoreConfiguration | null> {
    return this.api.get<WireConfiguration>(`/stores/${id}/configurations/latest`).pipe(
      map(toConfiguration),
      catchError((error: unknown) => {
        if (error instanceof ApiError && error.status === 404) {
          return of(null);
        }
        throw error;
      }),
    );
  }

  updateGeneral(id: string, patch: StoreGeneralPatch): Observable<Store> {
    return this.api
      .patch<WireStoreCard>(`/stores/${id}`, {
        displayName: patch.displayName,
        phone: patch.phone,
        addressOverride: patch.addressOverride,
        ymsStatus: patch.ymsStatus,
        visibleToSuppliers: patch.visibleToSuppliers,
      })
      .pipe(switchMap(() => this.get(id)));
  }

  configurations(storeId: string): Observable<readonly StoreConfiguration[]> {
    return this.api
      .get<{ items: WireConfiguration[] }>(`/stores/${storeId}/configurations`)
      .pipe(map((wire) => (wire.items ?? []).map(toConfiguration)));
  }

  createConfiguration(
    storeId: string,
    draft: StoreConfigurationDraft,
  ): Observable<StoreConfiguration> {
    return this.api
      .post<WireConfiguration>(`/stores/${storeId}/configurations`, {
        effectiveFrom: draft.effectiveFrom,
        slotSizeMinutes: draft.slotSizeMinutes,
        maxVehicleWeightTons: draft.maxVehicleWeightTons,
        receivingWindows: draft.receivingWindows.map((w) => ({
          dayOfWeek: w.dayOfWeek,
          intervals: w.intervals.map((i) => ({ from: i.from, to: i.to })),
        })),
        ramps: draft.ramps.map(fromRamp),
        calendarExceptions: draft.calendarExceptions.map(fromCalendarException),
        leadTimeMinutes: draft.leadTimeMinutes,
        bookingHorizonDays: draft.bookingHorizonDays,
        noShowGraceMinutes: draft.noShowGraceMinutes,
        holdMaxMinutes: draft.holdMaxMinutes,
      })
      .pipe(map(toConfiguration));
  }

  bulkStatus(
    ids: readonly string[],
    status: YmsStatus,
  ): Observable<readonly BulkResultRow[]> {
    return this.api
      .post<WireBulkStatus>('/stores/bulk/status', {
        branchIds: [...ids],
        ymsStatus: status,
      })
      .pipe(
        map((wire) => {
          const failed = new Map(
            (wire.failed ?? []).map((f) => [f.branchId, f.message]),
          );
          return ids.map<BulkResultRow>((id) =>
            failed.has(id)
              ? { id, label: id, ok: false, message: failed.get(id) }
              : rowResult(id, id),
          );
        }),
      );
  }

  bulkVisibility(
    ids: readonly string[],
    visible: boolean,
  ): Observable<readonly BulkResultRow[]> {
    if (ids.length === 0) {
      return of([]);
    }
    return forkJoin(
      ids.map((id) =>
        this.api
          .patch<WireStoreCard>(`/stores/${id}`, { visibleToSuppliers: visible })
          .pipe(
            map((card) => rowResult(id, card.mcpData?.externalId ?? id)),
            catchError((error: unknown) => of(rowFailure(id, error))),
          ),
      ),
    );
  }

  reservedRules(storeId: string): Observable<readonly ReservedSlotRule[]> {
    return this.api
      .get<{ items: WireReservedRule[] }>(`/stores/${storeId}/reserved-slot-rules`)
      .pipe(map((wire) => (wire.items ?? []).map(toReservedRule)));
  }

  createReservedRule(
    storeId: string,
    draft: ReservedSlotRuleDraft,
  ): Observable<ReservedSlotRule> {
    return this.api
      .post<WireReservedRule>(`/stores/${storeId}/reserved-slot-rules`, draft)
      .pipe(map(toReservedRule));
  }

  updateReservedRule(
    storeId: string,
    ruleId: string,
    patch: Partial<ReservedSlotRuleDraft>,
  ): Observable<ReservedSlotRule> {
    return this.api
      .patch<WireReservedRule>(
        `/stores/${storeId}/reserved-slot-rules/${ruleId}`,
        patch,
      )
      .pipe(map(toReservedRule));
  }

  deleteReservedRule(storeId: string, ruleId: string): Observable<void> {
    return this.api
      .delete<void>(`/stores/${storeId}/reserved-slot-rules/${ruleId}`)
      .pipe(map(() => undefined));
  }

  slotBlocks(storeId: string): Observable<readonly SlotBlock[]> {
    return this.api
      .get<{ items: WireSlotBlock[] }>(`/stores/${storeId}/slot-blocks`)
      .pipe(map((wire) => (wire.items ?? []).map(toSlotBlock)));
  }

  createSlotBlock(storeId: string, draft: SlotBlockDraft): Observable<SlotBlock> {
    return this.api
      .post<WireSlotBlock>(`/stores/${storeId}/slot-blocks`, {
        rampIds: [...draft.rampIds],
        blockFrom: draft.blockFrom,
        blockTo: draft.blockTo,
        reason: draft.reason,
      })
      .pipe(map(toSlotBlock));
  }

  releaseSlotBlock(storeId: string, blockId: string): Observable<SlotBlock> {
    return this.api
      .post<WireSlotBlock>(`/stores/${storeId}/slot-blocks/${blockId}/release`)
      .pipe(map(toSlotBlock));
  }

  deleteSlotBlock(storeId: string, blockId: string): Observable<void> {
    return this.api
      .delete<void>(`/stores/${storeId}/slot-blocks/${blockId}`)
      .pipe(map(() => undefined));
  }
}

/** /api/admin/v1/suppliers/** — partner-service. */
@Injectable()
export class HttpSuppliersApi extends SuppliersApi {
  private readonly api = inject(ApiClient);

  list(filter: SupplierFilter, query: PageQuery): Observable<Page<Supplier>> {
    const limit = perPageOf(query);
    return this.api
      .get<WireSupplierList>('/suppliers', {
        q: filter.search,
        status: filter.status,
        limit,
        offset: (query.page - 1) * limit,
      })
      .pipe(map((wire) => toSupplierPage(wire, query)));
  }

  all(): Observable<readonly Supplier[]> {
    return this.api
      .get<WireSupplierList>('/suppliers', { limit: 200, offset: 0 })
      .pipe(map((wire) => (wire.items ?? []).map(toSupplier)));
  }

  get(id: string): Observable<Supplier> {
    return this.api.get<WireSupplier>(`/suppliers/${id}`).pipe(map(toSupplier));
  }

  create(draft: SupplierDraft): Observable<Supplier> {
    return this.api
      .post<WireSupplier>('/suppliers', supplierBody(draft))
      .pipe(map(toSupplier));
  }

  update(id: string, draft: SupplierDraft): Observable<Supplier> {
    return this.api
      .patch<WireSupplier>(`/suppliers/${id}`, supplierBody(draft))
      .pipe(map(toSupplier));
  }

  suspend(id: string, reason: string | null): Observable<Supplier> {
    return this.api
      .post<WireSupplier>(`/suppliers/${id}/suspend`, { reason })
      .pipe(map(toSupplier));
  }

  activate(id: string): Observable<Supplier> {
    return this.api
      .post<WireSupplier>(`/suppliers/${id}/activate`)
      .pipe(map(toSupplier));
  }

  remove(id: string): Observable<void> {
    return this.api.delete<void>(`/suppliers/${id}`).pipe(map(() => undefined));
  }

  bulkStatus(
    ids: readonly string[],
    status: SupplierStatus,
  ): Observable<readonly BulkResultRow[]> {
    if (ids.length === 0) {
      return of([]);
    }
    return forkJoin(
      ids.map((id) =>
        (status === 'suspended' ? this.suspend(id, null) : this.activate(id)).pipe(
          map((supplier) => rowResult(id, supplier.name)),
          catchError((error: unknown) => of(rowFailure(id, error))),
        ),
      ),
    );
  }
}

function supplierBody(draft: SupplierDraft): Record<string, unknown> {
  return {
    name: draft.name,
    edrpou: draft.edrpou,
    allStores: draft.allStores,
    storeIds: draft.allStores ? [] : [...draft.storeIds],
    contacts: draft.contacts.map((c) => ({
      name: c.name,
      phone: c.phone,
      email: c.email,
    })),
  };
}

/** /api/admin/v1/sync/** — store-service. */
@Injectable()
export class HttpSyncApi extends SyncApi {
  private readonly api = inject(ApiClient);

  log(page: number, perPage: PageSize): Observable<SyncLog> {
    return this.api
      .get<WireSyncLog>('/sync/log', { page, perPage })
      .pipe(map(toSyncLog));
  }

  runDetails(id: string): Observable<SyncRunDetails> {
    return this.api
      .get<WireSyncRunDetails>(`/sync/log/${encodeURIComponent(id)}`)
      .pipe(map(toSyncRunDetails));
  }

  run(): Observable<SyncReport> {
    return this.api.post<WireSyncReport>('/sync/run').pipe(map(toSyncReport));
  }
}

/**
 * /api/admin/v1/audit — identity-staff-service (RBAC-31).
 *
 * Журнал покриває зміни облікових записів і ролей: рівно те, що пише
 * колекція `role_audit`. Дій над магазинами й бронюваннями тут немає —
 * їх ведуть інші сервіси у власних журналах.
 */
@Injectable()
export class HttpAuditApi extends AuditApi {
  private readonly api = inject(ApiClient);

  list(filter: AuditFilter, page: number, perPage: PageSize): Observable<AuditLog> {
    return this.api
      .get<WireAuditLog>('/audit', {
        page,
        perPage,
        action: filter.action,
        targetUserId: filter.targetUserId,
      })
      .pipe(map(toAuditLog));
  }
}

/** /api/admin/v1/analytics/** — analytics-service. */
@Injectable()
export class HttpAnalyticsApi extends AnalyticsApi {
  private readonly api = inject(ApiClient);

  kpi(filter: AnalyticsFilter): Observable<AnalyticsKpi> {
    return this.api.get<AnalyticsKpi>('/analytics/kpi', analyticsParams(filter));
  }

  breakdown(
    filter: AnalyticsFilter,
    dimension: AnalyticsDimension,
  ): Observable<AnalyticsBreakdown> {
    return this.api.get<AnalyticsBreakdown>('/analytics/breakdown', {
      ...analyticsParams(filter),
      dimension,
    });
  }

  exportCsv(
    filter: AnalyticsFilter,
    dataset: AnalyticsExportDataset,
    dimension: AnalyticsDimension,
  ): Observable<string> {
    return this.api.getText('/analytics/export.csv', {
      ...analyticsParams(filter),
      dataset,
      dimension,
    });
  }
}
