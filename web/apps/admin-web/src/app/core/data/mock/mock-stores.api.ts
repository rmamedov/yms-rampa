import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import {
  BulkResultRow,
  CityFilter,
  DayOfWeek,
  NO_CITY,
  Page,
  PageQuery,
  ReservedSlotRule,
  SlotBlock,
  STORE_SORT_COLUMNS,
  Store,
  StoreConfiguration,
  StoreGeneralPatch,
  StoreListFilter,
  StoreListRow,
  YmsStatus,
} from '../../models';
import {
  ReservedSlotRuleDraft,
  SlotBlockDraft,
  StoreConfigurationDraft,
  StoresApi,
} from '../stores.api';
import {
  ALLOWED_TRANSITIONS,
  MockDb,
  MockStore,
  readinessOf,
  StoreCard,
  YMS_STATUS_LABELS,
} from './mock-db';
import {
  compareValues,
  fail,
  isAllowedPerPage,
  MOCK_LATENCY,
  normalize,
  paginate,
  PER_PAGE_PROBLEM,
  respond,
  sortItems,
} from './mock-support';
import { AuthService } from '../../auth/auth.service';
import { dayOfWeek, isValidTime, timeToMinutes } from '../../utils/time.util';

/** STL-06: текст порожньої вибірки формує store-service. */
export const EMPTY_STORES_MESSAGE = 'Магазинів за заданими умовами не знайдено';

export function toListRow(store: MockStore): StoreListRow {
  const config = activeConfig(store);
  return {
    id: store.card.id,
    externalId: store.card.externalId,
    displayName: store.card.effectiveDisplayName,
    city: store.card.city,
    address: store.card.effectiveAddress,
    ymsStatus: store.card.ymsStatus,
    ymsStatusLabel: store.card.ymsStatusLabel,
    isConfigured: store.card.isConfigured,
    missingSettings: store.card.missingSettings,
    rampCount: config ? config.ramps.filter((r) => r.enabled).length : 0,
    maxVehicleWeightTons: config?.maxVehicleWeightTons ?? null,
    visibleToSuppliers: store.card.visibleToSuppliers,
    eligible: store.card.eligible,
    lastSyncedAt: store.card.lastSyncedAt,
  };
}

function activeConfig(store: MockStore): StoreConfiguration | null {
  return store.configurations.length === 0
    ? null
    : store.configurations[store.configurations.length - 1];
}

/**
 * STL-02 (фільтри комбінуються за AND) і STL-03
 * (пошук: externalId — точний/префіксний, адреса — підрядок без регістру).
 */
/**
 * Філія без міста збігається лише зі спеціальним значенням NO_CITY —
 * так само, як BranchCriteria::cityMatches у store-service.
 */
function cityMatches(city: string, cities: readonly string[]): boolean {
  return city.trim() === ''
    ? cities.includes(NO_CITY)
    : cities.includes(city);
}

export function matchesStoreFilter(
  row: StoreListRow,
  filter: StoreListFilter,
): boolean {
  if (filter.cities.length > 0 && !cityMatches(row.city, filter.cities)) {
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
  return byExternalId || byAddress;
}

export function filterStoreRows(
  rows: readonly StoreListRow[],
  filter: StoreListFilter,
): StoreListRow[] {
  return rows.filter((row) => matchesStoreFilter(row, filter));
}

/** Колонки списку → поля рядка, як у BranchCriteria::sortBy. */
const SORT_FIELD: Readonly<Record<string, keyof StoreListRow>> = {
  city: 'city',
  externalId: 'externalId',
  ymsStatus: 'ymsStatus',
  address: 'address',
  syncedAt: 'lastSyncedAt',
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
  private scopedStores(): readonly MockStore[] {
    if (this.auth.grant('store.read') !== 'scoped') {
      return this.db.state.stores;
    }
    const allowed = this.auth.storeIds();
    return this.db.state.stores.filter((s) => allowed.includes(s.card.id));
  }

  list(filter: StoreListFilter, query: PageQuery): Observable<Page<StoreListRow>> {
    if (!isAllowedPerPage(query.pageSize)) {
      return fail(422, PER_PAGE_PROBLEM, this.latency);
    }
    return respond(() => {
      const rows = this.scopedStores().map(toListRow);
      const filtered = filterStoreRows(rows, filter);
      const column =
        query.sort && STORE_SORT_COLUMNS.includes(query.sort) ? query.sort : 'city';
      const sorted = sortItems(
        filtered as unknown as Array<Record<string, unknown>>,
        SORT_FIELD[column],
        query.direction ?? 'asc',
        (a, b) => compareValues(a['externalId'], b['externalId']),
      ) as unknown as StoreListRow[];
      return paginate(sorted, query, EMPTY_STORES_MESSAGE);
    }, this.latency);
  }

  cities(): Observable<CityFilter> {
    return respond(() => {
      const counts = new Map<string, number>();
      let withoutCity = 0;
      for (const store of this.scopedStores()) {
        const city = store.card.city.trim();
        if (city.length === 0) {
          withoutCity += 1;
          continue;
        }
        counts.set(city, (counts.get(city) ?? 0) + 1);
      }
      return {
        items: [...counts.entries()]
          .map(([city, storeCount]) => ({ city, storeCount }))
          .sort((a, b) => a.city.localeCompare(b.city, 'uk')),
        withoutCity,
      };
    }, this.latency);
  }

  /** RBAC-18: читання одиничного ресурсу поза скоупом — 404, а не 403. */
  get(id: string): Observable<Store> {
    const store = this.scopedStores().find((s) => s.card.id === id);
    if (!store) {
      return fail(404, { code: 'STORE_NOT_FOUND' }, this.latency);
    }
    return respond(() => this.assemble(store), this.latency);
  }

  private assemble(store: MockStore): Store {
    return copy({
      ...store.card,
      configuration: activeConfig(store),
      reservedRules: store.reservedRules,
      slotBlocks: store.slotBlocks,
    });
  }

  updateGeneral(id: string, patch: StoreGeneralPatch): Observable<Store> {
    const store = this.db.store(id);
    if (!store) {
      return fail(404, { code: 'STORE_NOT_FOUND' }, this.latency);
    }
    // STC-03: активувати можна лише налаштований магазин
    if (patch.ymsStatus === 'active' && !store.card.isConfigured) {
      return fail(
        409,
        {
          code: 'STORE_NOT_CONFIGURED',
          detail: 'Неможливо активувати: не завершено налаштування магазину',
        },
        this.latency,
      );
    }
    return respond(() => {
      store.card = withStatus(
        {
          ...store.card,
          displayName: patch.displayName,
          effectiveDisplayName:
            patch.displayName ?? `Сільпо ${store.card.externalId}`,
          phone: patch.phone,
          addressOverride: patch.addressOverride,
          effectiveAddress: patch.addressOverride ?? store.card.address,
          visibleToSuppliers: patch.visibleToSuppliers,
        },
        patch.ymsStatus,
      );
      return this.assemble(store);
    }, this.latency);
  }

  configurations(storeId: string): Observable<readonly StoreConfiguration[]> {
    const store = this.db.store(storeId);
    if (!store) {
      return fail(404, { code: 'STORE_NOT_FOUND' }, this.latency);
    }
    return respond(() => copy(store.configurations), this.latency);
  }

  /** DATA-09: створення НОВОЇ версії; наявна версія ніколи не оновлюється. */
  createConfiguration(
    storeId: string,
    draft: StoreConfigurationDraft,
  ): Observable<StoreConfiguration> {
    const store = this.db.store(storeId);
    if (!store) {
      return fail(404, { code: 'STORE_NOT_FOUND' }, this.latency);
    }
    const version = store.configurations.length + 1;
    const config: StoreConfiguration = {
      id: this.db.nextId('cfg'),
      storeId,
      version,
      effectiveFrom: `${draft.effectiveFrom}T00:00:00+00:00`,
      receivingWindows: draft.receivingWindows,
      slotSizeMinutes: draft.slotSizeMinutes,
      ramps: draft.ramps,
      maxVehicleWeightTons: draft.maxVehicleWeightTons,
      leadTimeMinutes: draft.leadTimeMinutes,
      bookingHorizonDays: draft.bookingHorizonDays,
      noShowGraceMinutes: draft.noShowGraceMinutes,
      holdMaxMinutes: draft.holdMaxMinutes,
      calendarExceptions: draft.calendarExceptions,
      configured: true,
      missingSettings: [],
      createdBy: this.auth.user()?.id ?? null,
      createdAt: new Date().toISOString(),
      schemaVersion: 1,
    };
    const readiness = readinessOf(config);
    return respond(() => {
      const stored: StoreConfiguration = {
        ...config,
        configured: readiness.configured,
        missingSettings: readiness.missing,
      };
      store.configurations = [...store.configurations, stored];
      store.card = {
        ...store.card,
        isConfigured: readiness.configured,
        missingSettings: readiness.missing,
        activeConfigurationVersion: version,
      };
      return copy(stored);
    }, this.latency);
  }

  bulkStatus(
    ids: readonly string[],
    status: YmsStatus,
  ): Observable<readonly BulkResultRow[]> {
    return respond(
      () =>
        ids.map((id) => {
          const store = this.db.store(id);
          if (!store) {
            return { id, label: id, ok: false, message: 'Магазин не знайдено' };
          }
          if (status === 'active' && !store.card.isConfigured) {
            return {
              id,
              label: store.card.externalId,
              ok: false,
              message: 'Неможливо активувати: не завершено налаштування магазину',
            };
          }
          store.card = withStatus(store.card, status);
          return { id, label: store.card.externalId, ok: true };
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
          const store = this.db.store(id);
          if (!store) {
            return { id, label: id, ok: false, message: 'Магазин не знайдено' };
          }
          store.card = { ...store.card, visibleToSuppliers: visible };
          return { id, label: store.card.externalId, ok: true };
        }),
      this.latency,
    );
  }

  reservedRules(storeId: string): Observable<readonly ReservedSlotRule[]> {
    const store = this.db.store(storeId);
    if (!store) {
      return fail(404, { code: 'STORE_NOT_FOUND' }, this.latency);
    }
    return respond(() => copy(store.reservedRules), this.latency);
  }

  /** STC-42: резерв лише у вікні прийому, на увімкнену рампу, без перетину. */
  createReservedRule(
    storeId: string,
    draft: ReservedSlotRuleDraft,
  ): Observable<ReservedSlotRule> {
    const store = this.db.store(storeId);
    if (!store) {
      return fail(404, { code: 'STORE_NOT_FOUND' }, this.latency);
    }
    const rule: ReservedSlotRule = {
      id: this.db.nextId('res'),
      storeId,
      supplierId: draft.supplierId,
      rampId: draft.rampId,
      slotStartTime: draft.slotStartTime,
      dayOfWeek: draft.dayOfWeek,
      date: draft.date,
      validFrom: `${draft.validFrom}T00:00:00+00:00`,
      validTo: draft.validTo ? `${draft.validTo}T00:00:00+00:00` : null,
      active: draft.active,
    };
    const problem = this.reserveProblem(store, rule);
    if (problem) {
      return fail(problem.status, problem.body, this.latency);
    }
    return respond(() => {
      store.reservedRules = [...store.reservedRules, rule];
      return copy(rule);
    }, this.latency);
  }

  updateReservedRule(
    storeId: string,
    ruleId: string,
    patch: Partial<ReservedSlotRuleDraft>,
  ): Observable<ReservedSlotRule> {
    const store = this.db.store(storeId);
    const existing = store?.reservedRules.find((r) => r.id === ruleId);
    if (!store || !existing) {
      return fail(404, { code: 'RESERVED_RULE_NOT_FOUND' }, this.latency);
    }
    const updated: ReservedSlotRule = {
      ...existing,
      supplierId: patch.supplierId ?? existing.supplierId,
      rampId: patch.rampId ?? existing.rampId,
      slotStartTime: patch.slotStartTime ?? existing.slotStartTime,
      dayOfWeek: patch.dayOfWeek === undefined ? existing.dayOfWeek : patch.dayOfWeek,
      date: patch.date === undefined ? existing.date : patch.date,
      active: patch.active ?? existing.active,
    };
    const problem = this.reserveProblem(store, updated);
    if (problem) {
      return fail(problem.status, problem.body, this.latency);
    }
    return respond(() => {
      store.reservedRules = store.reservedRules.map((r) =>
        r.id === ruleId ? updated : r,
      );
      return copy(updated);
    }, this.latency);
  }

  private reserveProblem(
    store: MockStore,
    rule: ReservedSlotRule,
  ): { status: number; body: { code: string; detail: string } } | null {
    const config = activeConfig(store);
    if (!config) {
      return {
        status: 409,
        body: {
          code: 'STORE_NOT_CONFIGURED',
          detail: 'Резерв неможливий: для магазину ще не задано конфігурацію прийому',
        },
      };
    }
    const ramp = config.ramps.find((r) => r.id === rule.rampId);
    if (!ramp || !ramp.enabled) {
      return {
        status: 422,
        body: {
          code: 'CONFIG_VALIDATION_FAILED',
          detail: 'Резерв не можна створити на вимкнену рампу',
        },
      };
    }
    const day =
      rule.dayOfWeek ??
      (rule.date ? (dayOfWeek(rule.date) as DayOfWeek) : null);
    const intervals =
      day === null
        ? []
        : (config.receivingWindows.find((w) => w.dayOfWeek === day)?.intervals ?? []);
    const start = isValidTime(rule.slotStartTime)
      ? timeToMinutes(rule.slotStartTime)
      : -1;
    const inWindow = intervals.some(
      (i) => start >= timeToMinutes(i.from) && start < timeToMinutes(i.to),
    );
    if (!inWindow) {
      return {
        status: 422,
        body: {
          code: 'CONFIG_VALIDATION_FAILED',
          detail: 'Час резерву не потрапляє в жодне вікно прийому',
        },
      };
    }
    const overlap = store.reservedRules.some(
      (other) =>
        other.id !== rule.id &&
        other.active &&
        other.rampId === rule.rampId &&
        other.slotStartTime === rule.slotStartTime &&
        (other.dayOfWeek === rule.dayOfWeek || other.date === rule.date),
    );
    if (overlap) {
      return {
        status: 409,
        body: {
          code: 'RESERVED_RULE_OVERLAP',
          detail: 'Правила резерву перетинаються на одному слоті',
        },
      };
    }
    return null;
  }

  deleteReservedRule(storeId: string, ruleId: string): Observable<void> {
    const store = this.db.store(storeId);
    if (!store || !store.reservedRules.some((r) => r.id === ruleId)) {
      return fail(404, { code: 'RESERVED_RULE_NOT_FOUND' }, this.latency);
    }
    return respond(() => {
      store.reservedRules = store.reservedRules.filter((r) => r.id !== ruleId);
      return undefined;
    }, this.latency);
  }

  slotBlocks(storeId: string): Observable<readonly SlotBlock[]> {
    const store = this.db.store(storeId);
    if (!store) {
      return fail(404, { code: 'STORE_NOT_FOUND' }, this.latency);
    }
    return respond(() => copy(store.slotBlocks), this.latency);
  }

  createSlotBlock(storeId: string, draft: SlotBlockDraft): Observable<SlotBlock> {
    const store = this.db.store(storeId);
    if (!store) {
      return fail(404, { code: 'STORE_NOT_FOUND' }, this.latency);
    }
    // STC-60: блокування не можна створити на минулий період
    if (new Date(draft.blockTo).getTime() <= Date.now()) {
      return fail(
        422,
        {
          code: 'CONFIG_VALIDATION_FAILED',
          detail: 'Блокування не можна створити на минулий період',
        },
        this.latency,
      );
    }
    const block: SlotBlock = {
      id: this.db.nextId('blk'),
      storeId,
      rampIds: [...draft.rampIds],
      coversAllRamps: draft.rampIds.length === 0,
      blockFrom: draft.blockFrom,
      blockTo: draft.blockTo,
      reason: draft.reason,
      releasedAt: null,
      createdAt: new Date().toISOString(),
    };
    return respond(() => {
      store.slotBlocks = [block, ...store.slotBlocks];
      return copy(block);
    }, this.latency);
  }

  /** STC-52: дострокове зняття блокування (подія SlotReleased). */
  releaseSlotBlock(storeId: string, blockId: string): Observable<SlotBlock> {
    const store = this.db.store(storeId);
    const block = store?.slotBlocks.find((b) => b.id === blockId);
    if (!store || !block) {
      return fail(404, { code: 'SLOT_BLOCK_NOT_FOUND' }, this.latency);
    }
    if (block.releasedAt !== null) {
      return fail(
        422,
        { code: 'CONFIG_VALIDATION_FAILED', detail: 'Блокування вже знято' },
        this.latency,
      );
    }
    return respond(() => {
      const released: SlotBlock = { ...block, releasedAt: new Date().toISOString() };
      store.slotBlocks = store.slotBlocks.map((b) =>
        b.id === blockId ? released : b,
      );
      return copy(released);
    }, this.latency);
  }

  deleteSlotBlock(storeId: string, blockId: string): Observable<void> {
    const store = this.db.store(storeId);
    if (!store || !store.slotBlocks.some((b) => b.id === blockId)) {
      return fail(404, { code: 'SLOT_BLOCK_NOT_FOUND' }, this.latency);
    }
    return respond(() => {
      store.slotBlocks = store.slotBlocks.filter((b) => b.id !== blockId);
      return undefined;
    }, this.latency);
  }
}

function withStatus(card: StoreCard, status: YmsStatus): StoreCard {
  return {
    ...card,
    ymsStatus: status,
    ymsStatusLabel: YMS_STATUS_LABELS[status],
    allowedTransitions: ALLOWED_TRANSITIONS[status],
    visibleToSuppliers: status === 'active' ? card.visibleToSuppliers : false,
    eligible: status === 'active' && card.isConfigured && card.open,
    archivedAt: status === 'archived' ? new Date().toISOString() : null,
  };
}

function copy<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}
