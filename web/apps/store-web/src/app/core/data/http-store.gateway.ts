import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient } from '../api/api-client.service';
import {
  AuditEntry,
  Booking,
  CompleteUnloadingPayload,
  DelayPayload,
  ReassignPayload,
  RejectPayload,
  WalkInPayload,
} from '../models/booking.model';
import { Slot, StoreConfig, SupplierRef } from '../models/store.model';
import { BoardSnapshot, StoreGateway, WeekDaySlots } from './gateways';

/**
 * Реальна реалізація доступу до даних: booking-service і store-service через
 * api-gateway, префікс /api/store/v1.
 */
@Injectable()
export class HttpStoreGateway extends StoreGateway {
  private readonly api = inject(ApiClient);

  override getStoreConfig(storeId: string): Observable<StoreConfig> {
    return this.api.get<StoreConfig>(`/stores/${storeId}/config`);
  }

  override getSuppliers(storeId: string): Observable<readonly SupplierRef[]> {
    return this.api.get<readonly SupplierRef[]>(`/stores/${storeId}/suppliers`);
  }

  override getBoard(storeId: string, dateKey: string): Observable<BoardSnapshot> {
    return this.api.get<BoardSnapshot>(`/stores/${storeId}/board`, {
      date: dateKey,
    });
  }

  override getSlots(
    storeId: string,
    dateKey: string,
  ): Observable<readonly Slot[]> {
    return this.api.get<readonly Slot[]>(`/stores/${storeId}/slots`, {
      date: dateKey,
    });
  }

  override getWeek(
    storeId: string,
    mondayKey: string,
  ): Observable<readonly WeekDaySlots[]> {
    return this.api.get<readonly WeekDaySlots[]>(`/stores/${storeId}/week`, {
      from: mondayKey,
    });
  }

  override getAuditLog(bookingId: string): Observable<readonly AuditEntry[]> {
    return this.api.get<readonly AuditEntry[]>(`/bookings/${bookingId}/audit`);
  }

  override startUnloading(
    bookingId: string,
    version: number,
  ): Observable<Booking> {
    return this.api.post<Booking>(`/bookings/${bookingId}/start-unloading`, {
      version,
    });
  }

  override completeUnloading(
    bookingId: string,
    version: number,
    payload: CompleteUnloadingPayload,
  ): Observable<Booking> {
    return this.api.post<Booking>(`/bookings/${bookingId}/complete`, {
      version,
      ...payload,
    });
  }

  override markNoShow(bookingId: string, version: number): Observable<Booking> {
    return this.api.post<Booking>(`/bookings/${bookingId}/no-show`, { version });
  }

  override reject(
    bookingId: string,
    version: number,
    payload: RejectPayload,
  ): Observable<Booking> {
    return this.api.post<Booking>(`/bookings/${bookingId}/reject`, {
      version,
      ...payload,
    });
  }

  override setDelay(
    bookingId: string,
    version: number,
    payload: DelayPayload,
  ): Observable<Booking> {
    return this.api.patch<Booking>(`/bookings/${bookingId}/delay`, {
      version,
      ...payload,
    });
  }

  override clearDelay(bookingId: string, version: number): Observable<Booking> {
    return this.api.patch<Booking>(`/bookings/${bookingId}/delay`, {
      version,
      flag: false,
    });
  }

  override reassignRamp(
    bookingId: string,
    version: number,
    payload: ReassignPayload,
  ): Observable<Booking> {
    return this.api.post<Booking>(`/bookings/${bookingId}/reassign-ramp`, {
      version,
      ...payload,
    });
  }

  override createWalkIn(
    storeId: string,
    payload: WalkInPayload,
  ): Observable<Booking> {
    return this.api.post<Booking>(`/stores/${storeId}/walk-in-bookings`, payload);
  }
}
