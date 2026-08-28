import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { DriverApi } from './driver.api';
import { toDayRouteSheet } from './route-sheet.mapper';
import { toBookingActionResult } from './booking-action.mapper';
import type {
  DriverRouteSheetResponse,
  RouteSheetLoad,
} from '../models/route-sheet.model';
import type {
  BookingActionResult,
  BookingResponse,
  DelayReport,
} from '../models/booking-action.model';
import { environment } from '../../../environments/environment';

/**
 * Службові заголовки свіжості, які проставляє public/sw.js. Назви мають
 * збігатися дослівно — це контракт між застосунком і його ж service worker.
 */
const FROM_CACHE_HEADER = 'x-yms-from-cache';
const CACHED_AT_HEADER = 'x-yms-cached-at';

/** ISO-момент запису в кеш → epoch ms; усе нерозбірливе — це «невідомо». */
function parseCachedAt(raw: string | null): number | null {
  if (!raw) {
    return null;
  }
  const at = Date.parse(raw);
  return Number.isNaN(at) ? null : at;
}

@Injectable()
export class HttpDriverApi extends DriverApi {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiBase;

  /**
   * `date` передається ЗАВЖДИ: без нього контролер бере поточну київську
   * дату, і клієнт із іншим часовим поясом отримав би чужий день.
   * Некоректний формат бекенд відхиляє з 422 VALIDATION_FAILED.
   *
   * Читається саме ВІДПОВІДЬ цілком (`observe: 'response'`), бо свіжість
   * листа живе в заголовках, які проставляє service worker:
   *   x-yms-from-cache: 1        — відповідь зібрано з кешу, мережі не було;
   *   x-yms-cached-at: <ISO>     — коли цю копію записали.
   * Без них офлайн-відповідь не відрізнялася від свіжої, і водій бачив
   * «Оновлено HH:MM» на добових даних (ISSUE-10).
   */
  override routeSheet(date: string): Observable<RouteSheetLoad> {
    return this.http
      .get<DriverRouteSheetResponse>(`${this.base}/route-sheet`, {
        params: new HttpParams().set('date', date),
        observe: 'response',
      })
      .pipe(
        map((response) => ({
          sheet: toDayRouteSheet(
            response.body ?? { driverId: '', date, routeSheets: [] },
          ),
          fromCache: '1' === response.headers.get(FROM_CACHE_HEADER),
          cachedAt: parseCachedAt(response.headers.get(CACHED_AT_HEADER)),
        })),
      );
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
