import { inject, Injectable } from '@angular/core';
import { map, Observable, throwError } from 'rxjs';
import { ApiClient } from '../api/api-client.service';
import { toBooking } from '../api/wire.mapper';
import {
  WireBooking,
  WireCompleteRequest,
  WireDelayRequest,
  WireRejectRequest,
  WireReassignRequest,
  WireWalkInRequest,
} from '../api/wire.model';
import {
  Booking,
  CompleteUnloadingPayload,
  DelayPayload,
  ReassignPayload,
  RejectPayload,
  WalkInPayload,
} from '../models/booking.model';
import { AppError } from '../models/problem.model';
import { Slot, StoreConfig, SupplierRef } from '../models/store.model';
import {
  BoardSnapshot,
  STORE_READ_NOT_IMPLEMENTED,
  StoreGateway,
  WeekDaySlots,
} from './gateways';

/**
 * Реальний контур магазину: booking-service через api-gateway, префікс
 * /api/store/v1. Перелік маршрутів обмежений тим, що справді існує:
 *
 *   POST /api/store/v1/bookings/{bookingId}/arrived
 *   POST /api/store/v1/bookings/{bookingId}/unloading
 *   POST /api/store/v1/bookings/{bookingId}/completed
 *   POST /api/store/v1/bookings/{bookingId}/rejected
 *   POST /api/store/v1/bookings/{bookingId}/no-show
 *   POST /api/store/v1/bookings/{bookingId}/delay
 *   POST /api/store/v1/bookings/{bookingId}/reassign
 *   POST /api/store/v1/bookings/walk-in
 */
@Injectable()
export class HttpStoreGateway extends StoreGateway {
  private readonly api = inject(ApiClient);

  // --- Читання: маршрутів у контурі магазину немає -----------------------

  override getStoreConfig(storeId: string): Observable<StoreConfig> {
    return this.notImplemented(
      `GET /api/store/v1/stores/${storeId}/config — конфігурація магазину (рампи, вікна прийому, ліміт тоннажу)`,
    );
  }

  override getSuppliers(storeId: string): Observable<readonly SupplierRef[]> {
    return this.notImplemented(
      `GET /api/store/v1/stores/${storeId}/suppliers — довідник постачальників для walk-in`,
    );
  }

  override getBoard(
    storeId: string,
    dateKey: string,
  ): Observable<BoardSnapshot> {
    return this.notImplemented(
      `GET /api/store/v1/bookings?storeId=${storeId}&date=${dateKey} — перелік бронювань магазину на дату`,
    );
  }

  override getSlots(
    storeId: string,
    dateKey: string,
  ): Observable<readonly Slot[]> {
    return this.notImplemented(
      `GET /api/store/v1/stores/${storeId}/slots?date=${dateKey} — сітка слотів магазину`,
    );
  }

  override getWeek(
    storeId: string,
    mondayKey: string,
  ): Observable<readonly WeekDaySlots[]> {
    return this.notImplemented(
      `GET /api/store/v1/stores/${storeId}/slots?from=${mondayKey}&days=7 — сітка слотів на тиждень`,
    );
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

  private notImplemented<T>(wanted: string): Observable<T> {
    return throwError(
      () =>
        new AppError(
          {
            type: 'about:blank',
            title: 'Not Implemented',
            status: 501,
            code: STORE_READ_NOT_IMPLEMENTED,
            detail: `Бекенд ще не надає цей маршрут: ${wanted}`,
          },
          `error.${STORE_READ_NOT_IMPLEMENTED}`,
        ),
    );
  }
}
