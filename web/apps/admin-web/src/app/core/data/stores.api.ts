import { Observable } from 'rxjs';
import {
  Booking,
  BulkResultRow,
  CalendarException,
  ConfigChangeRequest,
  ConfigConflict,
  Page,
  PageQuery,
  Ramp,
  ReceivingWindow,
  ReservedSlotRule,
  SlotBlock,
  SlotSizeMinutes,
  Store,
  StoreGeneralPatch,
  StoreListFilter,
  StoreListRow,
  YmsStatus,
} from '../models';

export interface StoreConfigDraft {
  readonly receivingWindows?: readonly ReceivingWindow[];
  readonly exceptions?: readonly CalendarException[];
  readonly slotSizeMinutes?: SlotSizeMinutes | null;
  readonly ramps?: readonly Ramp[];
  readonly maxVehicleWeightTons?: number | null;
  readonly leadTimeHours?: number;
  readonly bookingHorizonDays?: number;
  readonly reservedRules?: readonly ReservedSlotRule[];
  readonly slotBlocks?: readonly SlotBlock[];
}

export type ConfigTemplateId = 'standard' | 'short';

/** store-service: довідник магазинів і конфігурація прийому. */
export abstract class StoresApi {
  abstract list(
    filter: StoreListFilter,
    query: PageQuery,
  ): Observable<Page<StoreListRow>>;

  abstract cities(): Observable<readonly string[]>;

  abstract get(id: string): Observable<Store>;

  abstract updateGeneral(id: string, patch: StoreGeneralPatch): Observable<Store>;

  /** Перевірка конфліктів перед збереженням (STC-62). */
  abstract checkConflicts(
    id: string,
    draft: StoreConfigDraft,
    effectiveFrom: string,
    nextYmsStatus?: YmsStatus,
  ): Observable<readonly ConfigConflict[]>;

  abstract saveConfig(request: ConfigChangeRequest): Observable<Store>;

  abstract bookings(id: string): Observable<readonly Booking[]>;

  abstract bulkStatus(
    ids: readonly string[],
    status: YmsStatus,
  ): Observable<readonly BulkResultRow[]>;

  abstract bulkVisibility(
    ids: readonly string[],
    visible: boolean,
  ): Observable<readonly BulkResultRow[]>;

  abstract applyTemplate(
    ids: readonly string[],
    template: ConfigTemplateId,
  ): Observable<readonly BulkResultRow[]>;
}
