import { inject, Injectable } from '@angular/core';
import { Observable, defer, delay, of, throwError } from 'rxjs';
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
import { AuthGateway, BoardSnapshot, StoreGateway, WeekDaySlots } from './gateways';
import { MockBackend } from './mock-backend.service';

/** Затримка мережі, щоб UI поводився як із реальним бекендом. */
const LATENCY_MS = 120;

function respond<T>(produce: () => T): Observable<T> {
  return defer(() => {
    try {
      return of(produce());
    } catch (error) {
      return throwError(() => error);
    }
  }).pipe(delay(LATENCY_MS));
}

@Injectable()
export class MockAuthGateway extends AuthGateway {
  private readonly backend = inject(MockBackend);

  override login(request: LoginRequest): Observable<LoginResponse> {
    return respond(() => this.backend.login(request));
  }

  override refresh(refreshToken: string): Observable<AuthTokens> {
    return respond(() => this.backend.refresh(refreshToken));
  }

  override logout(): Observable<void> {
    return respond(() => undefined);
  }
}

@Injectable()
export class MockStoreGateway extends StoreGateway {
  private readonly backend = inject(MockBackend);

  override getStoreConfig(storeId: string): Observable<StoreConfig> {
    return respond(() => this.backend.getStoreConfig(storeId));
  }

  override getSuppliers(): Observable<readonly SupplierRef[]> {
    return respond(() => this.backend.getSuppliers());
  }

  override getBoard(storeId: string, dateKey: string): Observable<BoardSnapshot> {
    return respond(() => this.backend.getBoard(storeId, dateKey));
  }

  override getSlots(storeId: string, dateKey: string): Observable<readonly Slot[]> {
    return respond(() => this.backend.getSlots(storeId, dateKey));
  }

  override getWeek(
    storeId: string,
    mondayKey: string,
  ): Observable<readonly WeekDaySlots[]> {
    return respond(() => this.backend.getWeek(storeId, mondayKey));
  }

  override getAuditLog(bookingId: string): Observable<readonly AuditEntry[]> {
    return respond(() => this.backend.getAuditLog(bookingId));
  }

  override startUnloading(bookingId: string, version: number): Observable<Booking> {
    return respond(() => this.backend.startUnloading(bookingId, version));
  }

  override completeUnloading(
    bookingId: string,
    version: number,
    payload: CompleteUnloadingPayload,
  ): Observable<Booking> {
    return respond(() =>
      this.backend.completeUnloading(bookingId, version, payload),
    );
  }

  override markNoShow(bookingId: string, version: number): Observable<Booking> {
    return respond(() => this.backend.markNoShow(bookingId, version));
  }

  override reject(
    bookingId: string,
    version: number,
    payload: RejectPayload,
  ): Observable<Booking> {
    return respond(() => this.backend.reject(bookingId, version, payload));
  }

  override setDelay(
    bookingId: string,
    version: number,
    payload: DelayPayload,
  ): Observable<Booking> {
    return respond(() => this.backend.setDelay(bookingId, version, payload));
  }

  override clearDelay(bookingId: string, version: number): Observable<Booking> {
    return respond(() => this.backend.clearDelay(bookingId, version));
  }

  override reassignRamp(
    bookingId: string,
    version: number,
    payload: ReassignPayload,
  ): Observable<Booking> {
    return respond(() => this.backend.reassignRamp(bookingId, version, payload));
  }

  override createWalkIn(
    storeId: string,
    payload: WalkInPayload,
  ): Observable<Booking> {
    return respond(() => this.backend.createWalkIn(storeId, payload));
  }
}
