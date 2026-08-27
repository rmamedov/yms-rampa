import { Observable } from 'rxjs';
import { LoginRequest, LoginResponse } from '../models/auth.model';
import {
  Booking,
  CompleteUnloadingPayload,
  DelayPayload,
  ReassignPayload,
  RejectPayload,
  WalkInPayload,
} from '../models/booking.model';
import { Slot, StoreConfig, SupplierRef } from '../models/store.model';

/** Контракт автентифікації staff-контуру (/api/store/v1/auth/...). */
export abstract class AuthGateway {
  abstract login(request: LoginRequest): Observable<LoginResponse>;
  /** Бекенд на refresh віддає ту саму плоску структуру, що й на login. */
  abstract refresh(refreshToken: string): Observable<LoginResponse>;
  abstract logout(refreshToken: string | null): Observable<void>;
}

export interface BoardSnapshot {
  readonly bookings: readonly Booking[];
  /** Серверний now (UTC ISO) — для таймерів і правил доступності дій. */
  readonly now: string;
}

export interface WeekDaySlots {
  readonly dateKey: string;
  readonly slots: readonly Slot[];
}

/**
 * Контракт даних магазину.
 *
 * ЗАПИС повністю відповідає реальним маршрутам booking-service
 * (/api/store/v1/bookings/...).
 *
 * ЧИТАННЯ у контурі магазину бекенд наразі НЕ надає: у `debug:router` немає
 * жодного GET під /api/store/v1, а nginx маршрутизує весь цей префікс у
 * booking-service, де є тільки дії над бронюванням і walk-in. Тому методи
 * читання лишаються в контракті (мок-режим на них тримає весь UI), але
 * HTTP-реалізація повертає явну помилку STORE_READ_NOT_IMPLEMENTED замість
 * мовчазного 404. Перелік потрібних маршрутів — у супровідній заявці на бекенд.
 */
export abstract class StoreGateway {
  abstract getStoreConfig(storeId: string): Observable<StoreConfig>;
  abstract getSuppliers(storeId: string): Observable<readonly SupplierRef[]>;
  abstract getBoard(storeId: string, dateKey: string): Observable<BoardSnapshot>;
  abstract getSlots(storeId: string, dateKey: string): Observable<readonly Slot[]>;
  abstract getWeek(
    storeId: string,
    mondayKey: string,
  ): Observable<readonly WeekDaySlots[]>;

  /** ST-01: booked → arrived. */
  abstract markArrived(bookingId: string): Observable<Booking>;
  /** ST-02: arrived → unloading. */
  abstract startUnloading(bookingId: string): Observable<Booking>;
  /** ST-03: unloading → completed. */
  abstract completeUnloading(
    bookingId: string,
    payload: CompleteUnloadingPayload,
  ): Observable<Booking>;
  /** NOSH-02: ручний no_show після slotEnd. */
  abstract markNoShow(bookingId: string): Observable<Booking>;
  /** ST-07: arrived → rejected. */
  abstract reject(
    bookingId: string,
    payload: RejectPayload,
  ): Observable<Booking>;
  /** DLY-01: прапорець затримки з причиною та ETA. */
  abstract setDelay(
    bookingId: string,
    payload: DelayPayload,
  ): Observable<Booking>;
  /** EDIT-06: переведення на іншу вільну рампу того самого слота. */
  abstract reassignRamp(
    bookingId: string,
    payload: ReassignPayload,
  ): Observable<Booking>;
  /** WALK-01: реєстрація позапланового прибуття. */
  abstract createWalkIn(payload: WalkInPayload): Observable<Booking>;
}

/** Коди помилок, які застосунок формує сам, без бекенду. */
export const STORE_READ_NOT_IMPLEMENTED = 'STORE_READ_NOT_IMPLEMENTED';
