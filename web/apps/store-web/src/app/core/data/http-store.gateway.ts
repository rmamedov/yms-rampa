import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import { ApiClient } from '../api/api-client.service';
import {
  toBoardSnapshot,
  toBooking,
  toSlots,
  toStoreConfig,
  toStoreScopes,
  toSupplierRefs,
  toWeekDaySlots,
} from '../api/wire.mapper';
import {
  WireBooking,
  WireCompleteRequest,
  WireDelayRequest,
  WireRejectRequest,
  WireReassignRequest,
  WireSlot,
  WireStoreBoard,
  WireStoreBrief,
  WireStoreConfig,
  WireSupplierRef,
  WireWalkInRequest,
  WireWeekDay,
} from '../api/wire.model';
import { StoreScope } from '../models/auth.model';
import {
  Booking,
  CompleteUnloadingPayload,
  DelayPayload,
  ReassignPayload,
  RejectPayload,
  WalkInPayload,
} from '../models/booking.model';
import { Slot, StoreConfig, SupplierRef } from '../models/store.model';
import { BoardSnapshot, StoreGateway, WeekDaySlots } from './gateways';

/** Скільки діб просить екран «Розклад тижня» (стеля бекенду — 14). */
const WEEK_DAYS = 7;

/**
 * Реальний контур магазину: booking-service через api-gateway, префікс
 * /api/store/v1.
 *
 *   GET  /api/store/v1/stores
 *   GET  /api/store/v1/stores/{storeId}/config
 *   GET  /api/store/v1/stores/{storeId}/suppliers
 *   GET  /api/store/v1/stores/{storeId}/slots?date=
 *   GET  /api/store/v1/stores/{storeId}/slots?from=&days=
 *   GET  /api/store/v1/bookings?storeId=&date=
 *   POST /api/store/v1/bookings/{bookingId}/arrived
 *   POST /api/store/v1/bookings/{bookingId}/unloading
 *   POST /api/store/v1/bookings/{bookingId}/completed
 *   POST /api/store/v1/bookings/{bookingId}/rejected
 *   POST /api/store/v1/bookings/{bookingId}/no-show
 *   POST /api/store/v1/bookings/{bookingId}/delay
 *   POST /api/store/v1/bookings/{bookingId}/reassign
 *   POST /api/store/v1/bookings/walk-in
 *
 * Читання віддає колекції ПЛОСКИМИ масивами без пагінації, тому шлюз мапить
 * відповідь цілком: обрізати перелік філій, постачальників чи слотів нічим
 * і нікуди — сторінок просто немає.
 */
@Injectable()
export class HttpStoreGateway extends StoreGateway {
  private readonly api = inject(ApiClient);

  // --- Читання -----------------------------------------------------------

  override getStores(): Observable<readonly StoreScope[]> {
    return this.api
      .get<readonly WireStoreBrief[]>('/stores')
      .pipe(map((wire) => toStoreScopes(wire ?? [])));
  }

  override getStoreConfig(storeId: string): Observable<StoreConfig> {
    return this.api
      .get<WireStoreConfig>(`/stores/${encodeURIComponent(storeId)}/config`)
      .pipe(map(toStoreConfig));
  }

  override getSuppliers(storeId: string): Observable<readonly SupplierRef[]> {
    return this.api
      .get<
        readonly WireSupplierRef[]
      >(`/stores/${encodeURIComponent(storeId)}/suppliers`)
      .pipe(map((wire) => toSupplierRefs(wire ?? [])));
  }

  override getBoard(
    storeId: string,
    dateKey: string,
  ): Observable<BoardSnapshot> {
    return this.api
      .get<WireStoreBoard>('/bookings', { storeId, date: dateKey })
      .pipe(map(toBoardSnapshot));
  }

  override getSlots(
    storeId: string,
    dateKey: string,
  ): Observable<readonly Slot[]> {
    return this.api
      .get<
        readonly WireSlot[]
      >(`/stores/${encodeURIComponent(storeId)}/slots`, { date: dateKey })
      .pipe(map((wire) => toSlots(wire ?? [])));
  }

  /**
   * Тиждень живе на тому самому маршруті, що й доба: `from` + `days` замість
   * `date`. Відповідь — масив діб із ключем локальної дати.
   */
  override getWeek(
    storeId: string,
    mondayKey: string,
  ): Observable<readonly WeekDaySlots[]> {
    return this.api
      .get<
        readonly WireWeekDay[]
      >(`/stores/${encodeURIComponent(storeId)}/slots`, { from: mondayKey, days: WEEK_DAYS })
      .pipe(map((wire) => (wire ?? []).map(toWeekDaySlots)));
  }

  // --- Дії над бронюванням ----------------------------------------------

  override markArrived(bookingId: string): Observable<Booking> {
    return this.action(`/bookings/${bookingId}/arrived`);
  }

  override startUnloading(bookingId: string): Observable<Booking> {
    return this.action(`/bookings/${bookingId}/unloading`);
  }

  override completeUnloading(
    bookingId: string,
    payload: CompleteUnloadingPayload,
  ): Observable<Booking> {
    const body: WireCompleteRequest = {
      unloadedPalletsCount: payload.unloadedPalletsCount,
      ...(payload.partialUnload
        ? {
            partialUnload: {
              reason: payload.partialUnload.reason,
              comment: payload.partialUnload.comment,
            },
          }
        : {}),
    };
    return this.action(`/bookings/${bookingId}/completed`, body);
  }

  override markNoShow(bookingId: string): Observable<Booking> {
    return this.action(`/bookings/${bookingId}/no-show`);
  }

  override reject(
    bookingId: string,
    payload: RejectPayload,
  ): Observable<Booking> {
    const body: WireRejectRequest = {
      reason: payload.reason,
      comment: payload.comment,
    };
    return this.action(`/bookings/${bookingId}/rejected`, body);
  }

  override setDelay(
    bookingId: string,
    payload: DelayPayload,
  ): Observable<Booking> {
    const body: WireDelayRequest = {
      reason: payload.reason,
      eta: payload.eta,
      comment: payload.comment,
    };
    return this.action(`/bookings/${bookingId}/delay`, body);
  }

  override reassignRamp(
    bookingId: string,
    payload: ReassignPayload,
  ): Observable<Booking> {
    const body: WireReassignRequest = { rampId: payload.rampId };
    return this.action(`/bookings/${bookingId}/reassign`, body);
  }

  override createWalkIn(payload: WalkInPayload): Observable<Booking> {
    const body: WireWalkInRequest = {
      storeId: payload.storeId,
      rampId: payload.rampId,
      slotStart: payload.slotStart,
      vehicle: payload.vehicle,
      palletsCount: payload.palletsCount,
      supplierId: payload.supplierId,
      supplierName: payload.supplierName,
      orderId: payload.orderId,
    };
    return this.action('/bookings/walk-in', body);
  }

  private action(path: string, body?: unknown): Observable<Booking> {
    return this.api.post<WireBooking>(path, body).pipe(map(toBooking));
  }
}
