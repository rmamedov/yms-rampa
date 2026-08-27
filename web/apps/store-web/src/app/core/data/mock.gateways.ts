import { inject, Injectable } from '@angular/core';
import { Observable, defer, delay, of, throwError } from 'rxjs';
import {
  toBoardSnapshot,
  toBooking,
  toLoginResponse,
  toSlots,
  toStoreConfig,
  toStoreScopes,
  toSupplierRefs,
  toWeekDaySlots,
} from '../api/wire.mapper';
import {
  WireCompleteRequest,
  WireDelayRequest,
  WireReassignRequest,
  WireRejectRequest,
  WireWalkInRequest,
} from '../api/wire.model';
import { LoginRequest, LoginResponse, StoreScope } from '../models/auth.model';
import {
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
    return respond(() =>
      toLoginResponse(
        this.backend.login({
          email: request.email,
          password: request.password,
        }),
      ),
    );
  }

  override refresh(refreshToken: string): Observable<LoginResponse> {
    return respond(() => toLoginResponse(this.backend.refresh(refreshToken)));
  }

  override logout(): Observable<void> {
    return respond(() => undefined);
  }
}

/**
 * Мок-шлюз ходить у мок-бекенд рівно тими самими тілами запитів, що й HTTP-шлюз,
 * і проганяє відповіді через той самий мапер — контракти не можуть розійтися.
 */
@Injectable()
export class MockStoreGateway extends StoreGateway {
  private readonly backend = inject(MockBackend);

  override getStores(): Observable<readonly StoreScope[]> {
    return respond(() => toStoreScopes(this.backend.getStores()));
  }

  override getStoreConfig(storeId: string): Observable<StoreConfig> {
    return respond(() => toStoreConfig(this.backend.getStoreConfig(storeId)));
  }

  override getSuppliers(): Observable<readonly SupplierRef[]> {
    return respond(() => toSupplierRefs(this.backend.getSuppliers()));
  }

  override getBoard(storeId: string, dateKey: string): Observable<BoardSnapshot> {
    return respond(() => toBoardSnapshot(this.backend.getBoard(storeId, dateKey)));
  }

  override getSlots(storeId: string, dateKey: string): Observable<readonly Slot[]> {
    return respond(() => toSlots(this.backend.getSlots(storeId, dateKey)));
  }

  override getWeek(
    storeId: string,
    mondayKey: string,
  ): Observable<readonly WeekDaySlots[]> {
    return respond(() =>
      this.backend.getWeek(storeId, mondayKey).map(toWeekDaySlots),
    );
  }

  override markArrived(bookingId: string): Observable<Booking> {
    return respond(() => toBooking(this.backend.markArrived(bookingId)));
  }

  override startUnloading(bookingId: string): Observable<Booking> {
    return respond(() => toBooking(this.backend.startUnloading(bookingId)));
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
    return respond(() =>
      toBooking(this.backend.completeUnloading(bookingId, body)),
    );
  }

  override markNoShow(bookingId: string): Observable<Booking> {
    return respond(() => toBooking(this.backend.markNoShow(bookingId)));
  }

  override reject(
    bookingId: string,
    payload: RejectPayload,
  ): Observable<Booking> {
    const body: WireRejectRequest = {
      reason: payload.reason,
      comment: payload.comment,
    };
    return respond(() => toBooking(this.backend.reject(bookingId, body)));
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
    return respond(() => toBooking(this.backend.setDelay(bookingId, body)));
  }

  override reassignRamp(
    bookingId: string,
    payload: ReassignPayload,
  ): Observable<Booking> {
    const body: WireReassignRequest = { rampId: payload.rampId };
    return respond(() => toBooking(this.backend.reassignRamp(bookingId, body)));
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
    return respond(() => toBooking(this.backend.createWalkIn(body)));
  }
}
