import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, catchError, of } from 'rxjs';
import { DriverApi } from './driver.api';
import type {
  ArrivePayload,
  AvailableDate,
  DelayPayload,
  RoutePoint,
  RouteSheet,
} from '../models/route-sheet.model';
import { ApiProblemError } from '../models/problem.model';
import { environment } from '../../../environments/environment';

@Injectable()
export class HttpDriverApi extends DriverApi {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiBase;

  override availableDates(): Observable<readonly AvailableDate[]> {
    return this.http.get<AvailableDate[]>(`${this.base}/route-sheets`);
  }

  override routeSheet(date: string): Observable<RouteSheet | null> {
    return this.http
      .get<RouteSheet>(`${this.base}/route-sheets/${encodeURIComponent(date)}`)
      .pipe(
        catchError((error: unknown) => {
          // Відсутність листа на дату — не помилка інтерфейсу (DRV-13).
          if (error instanceof ApiProblemError && error.status === 404) {
            return of(null);
          }
          throw error;
        }),
      );
  }

  override setOrderId(bookingId: string, orderId: string): Observable<RoutePoint> {
    return this.http.patch<RoutePoint>(
      `${this.base}/bookings/${encodeURIComponent(bookingId)}/order-id`,
      { orderId },
    );
  }

  override arrive(bookingId: string, payload: ArrivePayload): Observable<RoutePoint> {
    return this.http.post<RoutePoint>(
      `${this.base}/bookings/${encodeURIComponent(bookingId)}/arrive`,
      payload,
    );
  }

  override setDelay(bookingId: string, payload: DelayPayload): Observable<RoutePoint> {
    return this.http.post<RoutePoint>(
      `${this.base}/bookings/${encodeURIComponent(bookingId)}/delay`,
      payload,
    );
  }
}
