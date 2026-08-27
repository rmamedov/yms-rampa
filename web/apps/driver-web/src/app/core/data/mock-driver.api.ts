import { Injectable, inject } from '@angular/core';
import { Observable, defer, delay, of, throwError } from 'rxjs';
import { DriverApi } from './driver.api';
import { MockBackend } from '../mock/mock-backend';
import { NetworkService } from '../offline/network.service';
import { ApiProblemError } from '../models/problem.model';
import type {
  ArrivePayload,
  AvailableDate,
  DelayPayload,
  RoutePoint,
  RouteSheet,
} from '../models/route-sheet.model';

/** Штучна затримка мережі, щоб стани завантаження були видимі. */
const LATENCY_MS = 180;

@Injectable()
export class MockDriverApi extends DriverApi {
  private readonly backend = inject(MockBackend);
  private readonly network = inject(NetworkService);

  override availableDates(): Observable<readonly AvailableDate[]> {
    return this.wrap(() => this.backend.availableDates());
  }

  override routeSheet(date: string): Observable<RouteSheet | null> {
    return this.wrap(() => this.backend.routeSheet(date));
  }

  override setOrderId(bookingId: string, orderId: string): Observable<RoutePoint> {
    return this.wrap(() => this.backend.setOrderId(bookingId, orderId));
  }

  override arrive(bookingId: string, payload: ArrivePayload): Observable<RoutePoint> {
    return this.wrap(() => this.backend.arrive(bookingId, payload));
  }

  override setDelay(bookingId: string, payload: DelayPayload): Observable<RoutePoint> {
    return this.wrap(() => this.backend.setDelay(bookingId, payload));
  }

  private wrap<T>(fn: () => T): Observable<T> {
    return defer(() => {
      // Мок імітує реальну мережу: в офлайні запит падає так само, як HTTP.
      if (!this.network.online()) {
        return throwError(
          () =>
            new ApiProblemError(0, {
              code: 'NETWORK_UNAVAILABLE',
              detail: 'Немає звʼязку із сервером',
            }),
        );
      }
      try {
        return of(fn()).pipe(delay(LATENCY_MS));
      } catch (error) {
        return throwError(() => error).pipe(delay(LATENCY_MS));
      }
    });
  }
}
