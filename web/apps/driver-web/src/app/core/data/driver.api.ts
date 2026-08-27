import { Observable } from 'rxjs';
import type {
  ArrivePayload,
  AvailableDate,
  DelayPayload,
  RoutePoint,
  RouteSheet,
} from '../models/route-sheet.model';

/**
 * Контракт доступу до booking-service через api-gateway (/api/driver/v1/...).
 * Реалізації: HttpDriverApi (реальний бекенд) і MockDriverApi (environment.useMocks).
 */
export abstract class DriverApi {
  /** GET /api/driver/v1/route-sheets — дати, на які існують листи (DRV-13). */
  abstract availableDates(): Observable<readonly AvailableDate[]>;

  /** GET /api/driver/v1/route-sheets/{date} — null, якщо листа немає (DRV-12). */
  abstract routeSheet(date: string): Observable<RouteSheet | null>;

  /** PATCH /api/driver/v1/bookings/{bookingId}/order-id (DRV-17, DRV-18). */
  abstract setOrderId(bookingId: string, orderId: string): Observable<RoutePoint>;

  /** POST /api/driver/v1/bookings/{bookingId}/arrive (DRV-26). */
  abstract arrive(bookingId: string, payload: ArrivePayload): Observable<RoutePoint>;

  /** POST /api/driver/v1/bookings/{bookingId}/delay (DRV-41). */
  abstract setDelay(bookingId: string, payload: DelayPayload): Observable<RoutePoint>;
}
