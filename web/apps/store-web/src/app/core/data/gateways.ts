import { Observable } from 'rxjs';
import {
  AuthTokens,
  LoginRequest,
  LoginResponse,
} from '../models/auth.model';
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

/** Контракт автентифікації staff-контуру (/api/store/v1/auth/...). */
export abstract class AuthGateway {
  abstract login(request: LoginRequest): Observable<LoginResponse>;
  abstract refresh(refreshToken: string): Observable<AuthTokens>;
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

/** Контракт даних магазину (/api/store/v1/...). */
export abstract class StoreGateway {
  abstract getStoreConfig(storeId: string): Observable<StoreConfig>;
  abstract getSuppliers(storeId: string): Observable<readonly SupplierRef[]>;
  abstract getBoard(storeId: string, dateKey: string): Observable<BoardSnapshot>;
  abstract getSlots(storeId: string, dateKey: string): Observable<readonly Slot[]>;
  abstract getWeek(
    storeId: string,
    mondayKey: string,
  ): Observable<readonly WeekDaySlots[]>;
  abstract getAuditLog(bookingId: string): Observable<readonly AuditEntry[]>;

  abstract startUnloading(bookingId: string, version: number): Observable<Booking>;
  abstract completeUnloading(
    bookingId: string,
    version: number,
    payload: CompleteUnloadingPayload,
  ): Observable<Booking>;
  abstract markNoShow(bookingId: string, version: number): Observable<Booking>;
  abstract reject(
    bookingId: string,
    version: number,
    payload: RejectPayload,
  ): Observable<Booking>;
  abstract setDelay(
    bookingId: string,
    version: number,
    payload: DelayPayload,
  ): Observable<Booking>;
  abstract clearDelay(bookingId: string, version: number): Observable<Booking>;
  abstract reassignRamp(
    bookingId: string,
    version: number,
    payload: ReassignPayload,
  ): Observable<Booking>;
  abstract createWalkIn(
    storeId: string,
    payload: WalkInPayload,
  ): Observable<Booking>;
}
