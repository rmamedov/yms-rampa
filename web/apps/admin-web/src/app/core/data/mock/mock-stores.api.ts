import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import {
  Booking,
  BulkResultRow,
  ConfigChangeRequest,
  ConfigConflict,
  Page,
  PageQuery,
  Store,
  StoreConfig,
  StoreGeneralPatch,
  StoreListFilter,
  StoreListRow,
  YmsStatus,
} from '../../models';
import {
  ConfigTemplateId,
  StoreConfigDraft,
  StoresApi,
} from '../stores.api';
import { MockDb } from './mock-db';
import {
  compareValues,
  fail,
  MOCK_LATENCY,
  normalize,
  paginate,
  respond,
  sortItems,
} from './mock-support';
import {
  emptyReceivingWindows,
  isStoreConfigured,
} from '../../utils/store-config.util';
import { detectConflicts } from '../../utils/conflicts.util';
import { AuthService } from '../../auth/auth.service';

export function toListRow(store: Store): StoreListRow {
  return {
    id: store.id,
    externalId: store.externalId,
    displayName: store.displayName,
    city: store.city,
    address: store.addressOverride ?? store.address,
    ymsStatus: store.ymsStatus,
    isConfigured: store.isConfigured,
    rampCount: store.ramps.filter((r) => r.enabled).length,
    maxVehicleWeightTons: store.maxVehicleWeightTons,
    lastSyncedAt: store.lastSyncedAt,
    visibleToSuppliers: store.visibleToSuppliers,
  };
}

/**
 * STL-02 (фільтри комбінуються за AND) і STL-03
 * (пошук: externalId — точний/префіксний, адреса — підрядок без регістру).
 */
export function matchesStoreFilter(
  row: StoreListRow,
  filter: StoreListFilter,
): boolean {
  if (filter.cities.length > 0 && !filter.cities.includes(row.city)) {
    return false;
  }
  if (filter.statuses.length > 0 && !filter.statuses.includes(row.ymsStatus)) {
    return false;
  }
  if (filter.configured !== null && row.isConfigured !== filter.configured) {
    return false;
  }
  const search = normalize(filter.search);
  if (search === '') {
    return true;
  }
  const byExternalId = row.externalId.toLowerCase().startsWith(search);
  const byAddress = normalize(row.address).includes(search);
  const byName = normalize(row.displayName).includes(search);
  return byExternalId || byAddress || byName;
}

export function filterStoreRows(
  rows: readonly StoreListRow[],
  filter: StoreListFilter,
): StoreListRow[] {
  return rows.filter((row) => matchesStoreFilter(row, filter));
}

const TEMPLATES: Readonly<Record<ConfigTemplateId, Partial<StoreConfig>>> = {
  standard: {
    slotSizeMinutes: 30,
    maxVehicleWeightTons: 20,
    leadTimeHours: 4,
    bookingHorizonDays: 21,
    receivingWindows: emptyReceivingWindows().map((w) =>
      w.dayOfWeek === 7
        ? w
        : { dayOfWeek: w.dayOfWeek, intervals: [{ from: '08:00', to: '18:00' }] },
    ),
  },
  short: {
    slotSizeMinutes: 60,
    maxVehicleWeightTons: 10,
    leadTimeHours: 12,
    bookingHorizonDays: 14,
    receivingWindows: emptyReceivingWindows().map((w) =>
      w.dayOfWeek <= 5
        ? { dayOfWeek: w.dayOfWeek, intervals: [{ from: '09:00', to: '15:00' }] }
        : w,
    ),
  },
};

@Injectable()
export class MockStoresApi extends StoresApi {
  private readonly db = inject(MockDb);
  private readonly auth = inject(AuthService);
  private readonly latency = inject(MOCK_LATENCY);

  /**
   * RBAC-17: скоуп-фільтр застосовує сервер, а не клієнт.
   * RBAC-13: для store_manager/store_operator порожній storeIds = нуль доступу.
   */
  private scopedStores(): readonly Store[] {
    if (this.auth.grant('store.read') !== 'scoped') {
      return this.db.state.stores;
    }
    const allowed = this.auth.storeIds();
    return this.db.state.stores.filter((s) => allowed.includes(s.id));
  }

  list(filter: StoreListFilter, query: PageQuery): Observable<Page<StoreListRow>> {
    return respond(() => {
      const rows = this.scopedStores().map(toListRow);
      const filtered = filterStoreRows(rows, filter);
      // STL-05: за замовчуванням — місто, потім externalId
      const sorted = sortItems(
        filtered as unknown as Array<Record<string, unknown>>,
        query.sort ?? 'city',
        query.direction ?? 'asc',
        (a, b) => compareValues(a['externalId'], b['externalId']),
      ) as unknown as StoreListRow[];
      return paginate(sorted, query);
    }, this.latency);
  }

  cities(): Observable<readonly string[]> {
    return respond(
      () =>
        [...new Set(this.scopedStores().map((s) => s.city))]
          .filter((c) => c.trim().length > 0)
          .sort((a, b) => a.localeCompare(b, 'uk')),
      this.latency,
    );
  }

  /** RBAC-18: читання одиничного ресурсу поза скоупом — 404, а не 403. */
  get(id: string): Observable<Store> {
    const store = this.scopedStores().find((s) => s.id === id);
    if (!store) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    return respond(() => structuredCopy(store), this.latency);
  }

  updateGeneral(id: string, patch: StoreGeneralPatch): Observable<Store> {
    const index = this.db.state.stores.findIndex((s) => s.id === id);
    if (index < 0) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    const current = this.db.state.stores[index];
    // STC-03: активувати можна лише налаштований магазин
    if (patch.ymsStatus === 'active' && !isStoreConfigured(current)) {
      return fail(
        422,
        {
          code: 'STORE_NOT_CONFIGURED',
          detail: 'Неможливо активувати: не завершено налаштування магазину',
        },
        this.latency,
      );
    }
    const updated: Store = { ...current, ...patch };
    this.db.state.stores[index] = updated;
    return respond(() => structuredCopy(updated), this.latency);
  }

  checkConflicts(
    id: string,
    draft: StoreConfigDraft,
    effectiveFrom: string,
    nextYmsStatus?: YmsStatus,
  ): Observable<readonly ConfigConflict[]> {
    const store = this.db.state.stores.find((s) => s.id === id);
    if (!store) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    return respond(() => {
      const nextConfig = mergeConfig(store, draft);
      const rampLabels = Object.fromEntries(
        nextConfig.ramps.map((r) => [r.id, r.name ?? `№${r.number}`]),
      );
      return detectConflicts({
        bookings: this.db.state.bookings.filter((b) => b.storeId === id),
        nextConfig,
        effectiveFrom,
        nextYmsStatus,
        rampLabels,
      });
    }, this.latency);
  }

  saveConfig(request: ConfigChangeRequest): Observable<Store> {
    const index = this.db.state.stores.findIndex((s) => s.id === request.storeId);
    if (index < 0) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    const current = this.db.state.stores[index];
    const merged = mergeConfig(current, request.config);
    const general = request.general ?? {
      displayName: current.displayName,
      phone: current.phone,
      addressOverride: current.addressOverride,
      ymsStatus: current.ymsStatus,
      visibleToSuppliers: current.visibleToSuppliers,
    };
    if (general.ymsStatus === 'active' && !isStoreConfigured(merged)) {
      return fail(
        422,
        {
          code: 'STORE_NOT_CONFIGURED',
          detail: 'Неможливо активувати: не завершено налаштування магазину',
        },
        this.latency,
      );
    }
    const updated: Store = {
      ...current,
      ...general,
      ...merged,
      isConfigured: isStoreConfigured(merged),
    };
    this.db.state.stores[index] = updated;

    // STC-63: «Скасувати з нотифікацією» — booking-service скасовує бронювання
    for (const decision of request.decisions ?? []) {
      if (decision.resolution !== 'cancel_notify') {
        continue;
      }
      const bookingId = decision.conflictId.replace(/^cf-/, '');
      const bIndex = this.db.state.bookings.findIndex((b) => b.id === bookingId);
      if (bIndex >= 0) {
        this.db.state.bookings[bIndex] = {
          ...this.db.state.bookings[bIndex],
          status: 'cancelled',
        };
      }
    }
    return respond(() => structuredCopy(updated), this.latency);
  }

  bookings(id: string): Observable<readonly Booking[]> {
    return respond(
      () => this.db.state.bookings.filter((b) => b.storeId === id),
      this.latency,
    );
  }

  bulkStatus(
    ids: readonly string[],
    status: YmsStatus,
  ): Observable<readonly BulkResultRow[]> {
    return respond(
      () =>
        ids.map((id) => {
          const index = this.db.state.stores.findIndex((s) => s.id === id);
          if (index < 0) {
            return { id, label: id, ok: false, message: 'error.RESOURCE_NOT_FOUND' };
          }
          const store = this.db.state.stores[index];
          if (status === 'active' && !store.isConfigured) {
            return {
              id,
              label: store.externalId,
              ok: false,
              message: 'store.error.activate',
            };
          }
          this.db.state.stores[index] = {
            ...store,
            ymsStatus: status,
            visibleToSuppliers:
              status === 'active' ? store.visibleToSuppliers : false,
          };
          return { id, label: store.externalId, ok: true };
        }),
      this.latency,
    );
  }

  bulkVisibility(
    ids: readonly string[],
    visible: boolean,
  ): Observable<readonly BulkResultRow[]> {
    return respond(
      () =>
        ids.map((id) => {
          const index = this.db.state.stores.findIndex((s) => s.id === id);
          if (index < 0) {
            return { id, label: id, ok: false, message: 'error.RESOURCE_NOT_FOUND' };
          }
          const store = this.db.state.stores[index];
          this.db.state.stores[index] = { ...store, visibleToSuppliers: visible };
          return { id, label: store.externalId, ok: true };
        }),
      this.latency,
    );
  }

  applyTemplate(
    ids: readonly string[],
    template: ConfigTemplateId,
  ): Observable<readonly BulkResultRow[]> {
    return respond(
      () =>
        ids.map((id) => {
          const index = this.db.state.stores.findIndex((s) => s.id === id);
          if (index < 0) {
            return { id, label: id, ok: false, message: 'error.RESOURCE_NOT_FOUND' };
          }
          const store = this.db.state.stores[index];
          const ramps =
            store.ramps.length > 0
              ? store.ramps
              : [
                  {
                    id: `${store.id}-ramp-1`,
                    number: 1,
                    name: 'Основна',
                    enabled: true,
                    disabledFrom: null,
                    hasBookings: false,
                  },
                ];
          const merged = mergeConfig(store, { ...TEMPLATES[template], ramps });
          this.db.state.stores[index] = {
            ...store,
            ...merged,
            isConfigured: isStoreConfigured(merged),
          };
          return { id, label: store.externalId, ok: true };
        }),
      this.latency,
    );
  }
}

export function mergeConfig(
  store: StoreConfig,
  draft: StoreConfigDraft | Partial<StoreConfig>,
): StoreConfig {
  return {
    slotSizeMinutes:
      draft.slotSizeMinutes !== undefined
        ? draft.slotSizeMinutes
        : store.slotSizeMinutes,
    ramps: draft.ramps ?? store.ramps,
    maxVehicleWeightTons:
      draft.maxVehicleWeightTons !== undefined
        ? draft.maxVehicleWeightTons
        : store.maxVehicleWeightTons,
    leadTimeHours: draft.leadTimeHours ?? store.leadTimeHours,
    bookingHorizonDays: draft.bookingHorizonDays ?? store.bookingHorizonDays,
    receivingWindows: draft.receivingWindows ?? store.receivingWindows,
    exceptions: draft.exceptions ?? store.exceptions,
    reservedRules: draft.reservedRules ?? store.reservedRules,
    slotBlocks: draft.slotBlocks ?? store.slotBlocks,
  };
}

function structuredCopy<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}
