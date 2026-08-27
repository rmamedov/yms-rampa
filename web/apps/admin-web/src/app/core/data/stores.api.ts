import { Observable } from 'rxjs';
import {
  BulkResultRow,
  CalendarException,
  CityOption,
  DayOfWeek,
  Page,
  PageQuery,
  Ramp,
  ReceivingWindow,
  ReservedSlotRule,
  SlotBlock,
  SlotSizeMinutes,
  Store,
  StoreConfiguration,
  StoreGeneralPatch,
  StoreListFilter,
  StoreListRow,
  YmsStatus,
} from '../models';

/**
 * Тіло POST /api/admin/v1/stores/{storeId}/configurations.
 * Назви полів — дослівно як у StoreConfigurationService::createVersion.
 * slotSizeMinutes і maxVehicleWeightTons обовʼязкові (requireInt/requireFloat).
 */
export interface StoreConfigurationDraft {
  /** STC-60: не раніше завтра (для першої версії — не раніше сьогодні). */
  readonly effectiveFrom: string;
  readonly slotSizeMinutes: SlotSizeMinutes;
  readonly maxVehicleWeightTons: number;
  readonly receivingWindows: readonly ReceivingWindow[];
  readonly ramps: readonly Ramp[];
  readonly calendarExceptions: readonly CalendarException[];
  readonly leadTimeMinutes: number;
  readonly bookingHorizonDays: number;
  readonly noShowGraceMinutes: number;
  readonly holdMaxMinutes: number;
}

/** Тіло POST /api/admin/v1/stores/{storeId}/reserved-slot-rules. */
export interface ReservedSlotRuleDraft {
  readonly supplierId: string;
  readonly rampId: string;
  readonly slotStartTime: string;
  readonly dayOfWeek: DayOfWeek | null;
  readonly date: string | null;
  readonly validFrom: string;
  readonly validTo: string | null;
  readonly active: boolean;
}

/** Тіло POST /api/admin/v1/stores/{storeId}/slot-blocks. */
export interface SlotBlockDraft {
  readonly rampIds: readonly string[];
  /** UTC ISO 8601 */
  readonly blockFrom: string;
  readonly blockTo: string;
  readonly reason: string;
}

/** store-service: довідник магазинів, версіонована конфігурація, резерви, блокування. */
export abstract class StoresApi {
  /** GET /stores?q&city&ymsStatus&configured&page&perPage&sortBy&sortDirection */
  abstract list(
    filter: StoreListFilter,
    query: PageQuery,
  ): Observable<Page<StoreListRow>>;

  /** GET /stores/cities */
  abstract cities(): Observable<readonly CityOption[]>;

  /** GET /stores/{storeId} + /configurations/current + резерви + блокування. */
  abstract get(id: string): Observable<Store>;

  /** PATCH /stores/{storeId} — лише YMS-поля (MCP-поля read-only, STC-01). */
  abstract updateGeneral(id: string, patch: StoreGeneralPatch): Observable<Store>;

  /** GET /stores/{storeId}/configurations — історія версій (DATA-09). */
  abstract configurations(storeId: string): Observable<readonly StoreConfiguration[]>;

  /** POST /stores/{storeId}/configurations — нова версія «з дати X» (STC-60). */
  abstract createConfiguration(
    storeId: string,
    draft: StoreConfigurationDraft,
  ): Observable<StoreConfiguration>;

  /** POST /stores/bulk/status — { branchIds, ymsStatus } (UI-02). */
  abstract bulkStatus(
    ids: readonly string[],
    status: YmsStatus,
  ): Observable<readonly BulkResultRow[]>;

  /**
   * Окремого масового маршруту бекенд не має — збирається з PATCH /stores/{id}
   * по кожному магазину, помилки повертаються порядково.
   */
  abstract bulkVisibility(
    ids: readonly string[],
    visible: boolean,
  ): Observable<readonly BulkResultRow[]>;

  abstract reservedRules(storeId: string): Observable<readonly ReservedSlotRule[]>;
  abstract createReservedRule(
    storeId: string,
    draft: ReservedSlotRuleDraft,
  ): Observable<ReservedSlotRule>;
  abstract updateReservedRule(
    storeId: string,
    ruleId: string,
    patch: Partial<ReservedSlotRuleDraft>,
  ): Observable<ReservedSlotRule>;
  abstract deleteReservedRule(storeId: string, ruleId: string): Observable<void>;

  abstract slotBlocks(storeId: string): Observable<readonly SlotBlock[]>;
  abstract createSlotBlock(
    storeId: string,
    draft: SlotBlockDraft,
  ): Observable<SlotBlock>;
  /** STC-52: дострокове зняття блокування — подія SlotReleased. */
  abstract releaseSlotBlock(storeId: string, blockId: string): Observable<SlotBlock>;
  abstract deleteSlotBlock(storeId: string, blockId: string): Observable<void>;
}
