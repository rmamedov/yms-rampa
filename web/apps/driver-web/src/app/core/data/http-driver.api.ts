import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { DriverApi } from './driver.api';
import { toDayRouteSheet } from './route-sheet.mapper';
import { toBookingActionResult } from './booking-action.mapper';
import type {
  DayRouteSheet,
  DriverRouteSheetResponse,
} from '../models/route-sheet.model';
import type {
  BookingActionResult,
  BookingResponse,
  DelayReport,
} from '../models/booking-action.model';
import { environment } from '../../../environments/environment';

@Injectable()
export class HttpDriverApi extends DriverApi {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiBase;

  /**
   * `date` передається ЗАВЖДИ: без нього контролер бере поточну київську
   * дату, і клієнт із іншим часовим поясом отримав би чужий день.
   * Некоректний формат бекенд відхиляє з 422 VALIDATION_FAILED.
   */
  override routeSheet(date: string): Observable<DayRouteSheet | null> {
    return this.http
      .get<DriverRouteSheetResponse>(`${this.base}/route-sheet`, {
        params: new HttpParams().set('date', date),
      })
      .pipe(map(toDayRouteSheet));
  }

  /**
   * Повторний виклик на вже позначеному бронюванні — НЕ помилка: бекенд
   * віддає 200 і поточний стан (правило «хто перший», див. DriverApi).
   */
  override markArrived(
    bookingId: string,
    occurredAt: string,
  ): Observable<BookingActionResult> {
    return this.action(
      this.http.post<BookingResponse>(this.bookingUrl(bookingId, '/arrived'), {
        arrivedAt: occurredAt,
      }),
    );
  }

  override reportDelay(
    bookingId: string,
    report: DelayReport,
  ): Observable<BookingActionResult> {
    const comment = report.comment?.trim();

    return this.action(
      this.http.post<BookingResponse>(this.bookingUrl(bookingId, '/delay'), {
        reason: report.reason,
        eta: report.eta,
        // Порожній коментар не надсилаємо: для причин поза «інше» поле
        // необовʼязкове (RequestPayload::optionalString).
        ...(comment ? { comment } : {}),
      }),
    );
  }

  /**
   * У тілі рівно одне поле: контролер має ЗАКРИТИЙ перелік
   * EDITABLE_FIELDS = ['orderId'] і відхиляє будь-яке інше з 403.
   */
  override updateOrderId(
    bookingId: string,
    orderId: string | null,
  ): Observable<BookingActionResult> {
    return this.action(
      this.http.patch<BookingResponse>(this.bookingUrl(bookingId), { orderId }),
    );
  }

  private bookingUrl(bookingId: string, suffix = ''): string {
    return `${this.base}/bookings/${encodeURIComponent(bookingId)}${suffix}`;
  }

  private action(
    request: Observable<BookingResponse>,
  ): Observable<BookingActionResult> {
    return request.pipe(map(toBookingActionResult));
  }
}
